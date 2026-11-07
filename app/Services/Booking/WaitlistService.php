<?php

namespace App\Services\Booking;

use App\Enums\MessageTemplateKey;
use App\Enums\WaitlistStatus;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryResource;
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
 * Lista de espera: a quién ofrecerle un hueco, el aviso por WhatsApp con el horario (que queda apartado
 * mientras responde) y la salida de la lista cuando se le agenda. Los horarios salen de la misma agenda
 * inteligente que la web.
 */
final class WaitlistService
{
    /** Días hacia adelante en que se busca el primer horario que le sirva. */
    public const SEARCH_DAYS = 14;

    /** Horas que el horario ofrecido queda apartado para quien lo recibió (o hasta la cita, si es antes). */
    public const HOLD_HOURS = 2;

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
            // Quien ya tiene otro horario apartado está por responder; a quien se le ofreció este y no respondió, no se le insiste.
            ->whereNotIn('id', WaitlistEntry::query()->holding()->select('id'))
            ->where(fn ($query) => $query
                ->whereNull('offered_starts_at')
                ->orWhere('offered_starts_at', '!=', $startsAt)
                ->orWhere('offered_doctor_id', '!=', $doctor->getKey()))
            ->with(['patient.account', 'treatment'])
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
            ->forPatient($entry->patient_id)
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
            'hasta' => $this->holdEndsAt($startsAt)->format('g:i a'),
        ], $patient->getKey());
    }

    /**
     * Hasta cuándo queda apartado un horario que se ofrece ahora: unas horas, pero no tan encima de la cita que
     * ya no dé tiempo a llegar (media hora antes). Si la cita es muy pronto, hasta que empiece.
     */
    public function holdEndsAt(CarbonInterface $startsAt): CarbonImmutable
    {
        $startsAt = CarbonImmutable::instance($startsAt);
        $end = now()->toImmutable()->addHours(self::HOLD_HOURS)->min($startsAt->subMinutes(30));

        return $end->isAfter(now()) ? $end : $startsAt;
    }

    /**
     * Queda como avisado, con el horario que se le ofreció, para agendarlo si dice que sí. El horario queda
     * apartado un rato (ver holdEndsAt): mientras tanto no se le ofrece a otro ni lo toma la web.
     */
    public function markOffered(WaitlistEntry $entry, Doctor $doctor, Treatment $treatment, CarbonInterface $startsAt): void
    {
        $startsAt = CarbonImmutable::instance($startsAt);

        $entry->update([
            'status' => WaitlistStatus::Offered,
            'offered_at' => now(),
            'offered_starts_at' => $startsAt,
            'offered_until' => $startsAt->addMinutes($doctor->durationFor($treatment) + $treatment->bufferMinutes()),
            'offered_doctor_id' => $doctor->getKey(),
            'held_until' => $this->holdEndsAt($startsAt),
        ]);
    }

    /** Deja libre el horario apartado (no lo quiere, o no respondió a tiempo); sigue en la lista. */
    public function release(WaitlistEntry $entry): void
    {
        $entry->update(['held_until' => null]);
    }

    /**
     * Para la alerta de un horario que se liberó: quién de la lista de espera puede tomarlo (el que espera
     * hace más tiempo) y el botón que abre la agenda con el mensaje para él ya escrito.
     *
     * @return array{text: string, link: array{label: string, url: string}}|null
     */
    public function freedSlotNotice(Doctor $doctor, CarbonInterface $startsAt, ?int $exceptPatientId = null): ?array
    {
        if (! $startsAt->isFuture()) {
            return null;
        }

        $candidates = $this->candidatesFor($doctor, $startsAt, $exceptPatientId);
        $first = $candidates->first(fn (WaitlistEntry $entry) => $entry->patient->account !== null);

        if (! $first) {
            return null;
        }

        $others = $candidates->count() - 1;

        return [
            'text' => "{$first->patient->full_name}, de la lista de espera, puede venir a esa hora"
                .match (true) {
                    $others === 1 => ' (y 1 persona más).',
                    $others > 1 => " (y {$others} personas más).",
                    default => '.',
                },
            'link' => ['label' => 'Ofrecérselo a '.$first->patient->first_name, 'url' => WaitlistEntryResource::urlForOffer($first, $doctor, $startsAt)],
        ];
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

        return $this->availability->forPatient($entry->patient_id)->isFree($doctor, $startsAt, $doctor->durationFor($entry->treatment), $entry->treatment->bufferMinutes());
    }
}
