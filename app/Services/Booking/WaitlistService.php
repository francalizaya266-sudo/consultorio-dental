<?php

namespace App\Services\Booking;

use App\Enums\MessageTemplateKey;
use App\Enums\WaitlistStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\WaitlistEntry;
use App\Models\WhatsappMessage;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\Slot;
use App\Services\WhatsApp\WhatsAppOutbox;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lista de espera: a quién ofrecerle un hueco, el aviso por WhatsApp con el horario y la salida de
 * la lista cuando se le agenda. Los horarios salen de la misma agenda inteligente que la web.
 */
final class WaitlistService
{
    /** Días hacia adelante en que se busca el primer horario que le sirva. */
    public const SEARCH_DAYS = 14;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly WhatsAppOutbox $outbox,
    ) {}

    /**
     * Quienes pueden tomar un horario que se acaba de liberar: les cae en su plazo y en la parte
     * del día que pidieron, y su tratamiento entra a esa hora sin cruzarse con otra cita.
     *
     * @return Collection<int, WaitlistEntry>
     */
    public function candidatesFor(Doctor $doctor, CarbonInterface $startsAt, ?int $exceptPatientId = null): Collection
    {
        return WaitlistEntry::query()
            ->availableOn($startsAt, $doctor->getKey())
            ->whereHas('patient')
            ->when($exceptPatientId, fn ($query, int $id) => $query->where('patient_id', '!=', $id))
            ->with(['patient', 'treatment'])
            ->oldest()
            ->get()
            ->filter(fn (WaitlistEntry $entry) => $entry->accepts($startsAt) && $this->fitsAt($entry, $doctor, $startsAt))
            ->values();
    }

    /**
     * Doctores que pueden atenderlo: el que pidió o cualquiera activo que haga el tratamiento.
     *
     * @return Collection<int, Doctor>
     */
    public function doctorsFor(WaitlistEntry $entry, ?Treatment $treatment = null): Collection
    {
        $treatment ??= $entry->treatment;

        return Doctor::query()
            ->active()
            ->when($entry->doctor_id, fn ($query, int $id) => $query->whereKey($id))
            ->when($treatment, fn ($query) => $query->whereHas('treatments', fn ($treatments) => $treatments->whereKey($treatment->getKey())))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Horarios libres de ese día que le sirven (en la parte del día que pidió y dentro de su plazo).
     *
     * @return Collection<int, Slot>
     */
    public function slotsFor(WaitlistEntry $entry, Doctor $doctor, Treatment $treatment, CarbonInterface $date): Collection
    {
        $day = CarbonImmutable::instance($date)->startOfDay();

        return $this->availability
            ->slotsFor($doctor, $treatment, $day, earliest: $day->isToday() ? now() : null)
            ->filter(fn (Slot $slot) => $entry->accepts($slot->startsAt))
            ->values();
    }

    /**
     * El primer horario libre que le sirve en los próximos días, con su doctor o con cualquiera que
     * haga el tratamiento. Null si no hay ninguno.
     *
     * @return array{doctor: Doctor, slot: Slot}|null
     */
    public function firstSlot(WaitlistEntry $entry, ?Treatment $treatment = null): ?array
    {
        $treatment ??= $entry->treatment;

        if (! $treatment) {
            return null;
        }

        $doctors = $this->doctorsFor($entry, $treatment);
        $last = CarbonImmutable::today()->addDays(self::SEARCH_DAYS - 1);

        if ($entry->latest_date && $entry->latest_date->lt($last)) {
            $last = CarbonImmutable::instance($entry->latest_date);
        }

        for ($date = CarbonImmutable::instance($entry->firstDate())->startOfDay(); $date->lte($last); $date = $date->addDay()) {
            foreach ($doctors as $doctor) {
                $slot = $this->slotsFor($entry, $doctor, $treatment, $date)->first();

                if ($slot) {
                    return ['doctor' => $doctor, 'slot' => $slot];
                }
            }
        }

        return null;
    }

    /** El aviso con el horario que se le ofrece, sin guardar. Null si el paciente no tiene celular. */
    public function offerDraft(WaitlistEntry $entry, Doctor $doctor, Treatment $treatment, CarbonInterface $startsAt): ?WhatsappMessage
    {
        $patient = $entry->patient;

        if (! $patient?->account) {
            return null;
        }

        return $this->outbox->draftForAccount($patient->account, MessageTemplateKey::WaitlistOffer, [
            'nombre' => $patient->first_name,
            'paciente' => $patient->full_name,
            'tratamiento' => mb_strtolower($treatment->name),
            'fecha' => $startsAt->translatedFormat('l j \d\e F'),
            'hora' => $startsAt->format('g:i a'),
            'doctor' => $doctor->name,
        ], $patient->getKey());
    }

    /** Queda como avisado, con el horario que se le ofreció, para agendarlo si dice que sí. */
    public function markOffered(WaitlistEntry $entry, Doctor $doctor, CarbonInterface $startsAt): void
    {
        $entry->update([
            'status' => WaitlistStatus::Offered,
            'offered_at' => now(),
            'offered_starts_at' => $startsAt,
            'offered_doctor_id' => $doctor->getKey(),
        ]);
    }

    /** Ya no quiere esperar (consiguió otro horario, se atendió en otro lado...). */
    public function remove(WaitlistEntry $entry, ?string $reason = null): void
    {
        $entry->update([
            'status' => WaitlistStatus::Removed,
            'notes' => trim(collect([$entry->notes, $reason ? 'Retirado: '.$reason : null])->filter()->implode("\n")) ?: null,
        ]);
    }

    /**
     * Al agendarle una cita (o adelantarle la que tenía), sale de la lista lo que esa cita resuelve:
     * lo que esperaba para ese tratamiento (o para cualquiera) y con ese doctor (o con cualquiera).
     */
    public function settle(Appointment $appointment): void
    {
        WaitlistEntry::query()
            ->where('patient_id', $appointment->patient_id)
            ->whereIn('status', WaitlistEntry::ACTIVE_STATUSES)
            ->where(fn ($query) => $query->whereNull('treatment_id')->orWhere('treatment_id', $appointment->treatment_id))
            ->where(fn ($query) => $query->whereNull('doctor_id')->orWhere('doctor_id', $appointment->doctor_id))
            ->get()
            ->each(fn (WaitlistEntry $entry) => $entry->update([
                'status' => WaitlistStatus::Booked,
                'appointment_id' => $appointment->getKey(),
            ]));
    }

    /** ¿Su tratamiento entra a esa hora sin cruzarse con nada? Sin tratamiento elegido, cualquier hueco sirve. */
    private function fitsAt(WaitlistEntry $entry, Doctor $doctor, CarbonInterface $startsAt): bool
    {
        if (! $entry->treatment) {
            return true;
        }

        return $this->availability->isFree($doctor, $startsAt, $doctor->durationFor($entry->treatment), $entry->treatment->bufferMinutes());
    }
}
