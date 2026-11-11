<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\ScheduleBlock;
use App\Models\WhatsappMessage;
use App\Services\StaffAlerts;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que se hace el día anterior a cada cita: en la mañana queda listo su recordatorio y, si en la tarde sigue
 * sin confirmar, se marca para llamar.
 *
 * "El día anterior" es el último día en que el consultorio atiende antes de la cita: el recordatorio de un lunes
 * sale el sábado, porque el domingo no hay nadie para enviarlo (con la API de WhatsApp sale solo, así que va el
 * domingo). Cada pasada pone al día lo que falte: no depende de que una tarea corra a una hora exacta.
 */
class AppointmentReminders
{
    /** Avisos que ya le dicen al paciente cuándo es su cita: con uno de ese mismo día, el recordatorio sobra. */
    private const TELLS_THE_DATE = [
        MessageTemplateKey::Reminder,
        MessageTemplateKey::BookingConfirmation,
        MessageTemplateKey::AppointmentScheduled,
        MessageTemplateKey::Rescheduled,
        MessageTemplateKey::RescheduleApproved,
    ];

    /** Avisos que solo sirven antes de la cita: pasada la hora, salen de "Por enviar". */
    private const ONLY_BEFORE_THE_VISIT = [
        ...self::TELLS_THE_DATE,
        MessageTemplateKey::RescheduleRejected,
    ];

    /** Lo más que se adelanta un recordatorio: alcanza para un fin de semana largo sin atención. */
    private const MAX_DAYS_BEFORE = 3;

    /** @var array<string, bool> Si el consultorio atiende cada día consultado (por fecha). */
    private array $openDays = [];

    public function __construct(
        private readonly WhatsAppOutbox $outbox,
        private readonly StaffAlerts $alerts,
    ) {}

    /**
     * Pone al día todo lo que toca a esta hora.
     *
     * @return array{reminders: int, flagged: int, expired: int}
     */
    public function catchUp(): array
    {
        return [
            'expired' => $this->expireStale(),
            'reminders' => $this->prepareReminders(),
            'flagged' => $this->flagUnconfirmed(),
        ];
    }

    /** Deja en Avisos el recordatorio de las citas a las que ya les toca. Devuelve cuántos preparó. */
    public function prepareReminders(): int
    {
        $appointments = $this->comingUp(Appointment::query()->upcoming())
            ->with(['patient.account', 'doctor', 'treatment'])
            ->get()
            ->filter(fn (Appointment $appointment) => now()->gte($this->remindAt($appointment)));

        $told = $this->lastTold($appointments);

        $prepared = 0;

        foreach ($appointments as $appointment) {
            $dayBefore = $this->dayBefore($appointment->starts_at);

            // Agendada o movida ese mismo día: ya se lo dijeron (o recepción eligió no avisar).
            if ($appointment->created_at->gte($dayBefore) || ($told[$appointment->getKey()] ?? null)?->gte($dayBefore)) {
                continue;
            }

            $prepared += $this->outbox->queueForAppointment($appointment, MessageTemplateKey::Reminder) ? 1 : 0;
        }

        return $prepared;
    }

    /**
     * Marca "llamar" en las citas que a la tarde del día anterior siguen sin confirmar y avisa a recepción.
     * Devuelve cuántas marcó.
     */
    public function flagUnconfirmed(): int
    {
        $appointments = $this->comingUp(Appointment::query()->where('status', AppointmentStatus::Pending))
            ->whereNull('unconfirmed_flagged_at')
            ->get()
            // Las que se agendaron después de esa hora no: el paciente acaba de pedirla.
            ->filter(fn (Appointment $appointment) => now()->gte($this->flagAt($appointment)) && $appointment->created_at->lt($this->flagAt($appointment)));

        if ($appointments->isEmpty()) {
            return 0;
        }

        // Si otra pasada las marcó en este instante, no se avisa dos veces.
        if (Appointment::query()->whereKey($appointments->modelKeys())->whereNull('unconfirmed_flagged_at')->update(['unconfirmed_flagged_at' => now()]) === 0) {
            return 0;
        }

        $appointments
            ->groupBy(fn (Appointment $appointment) => $appointment->starts_at->toDateString())
            ->each(fn (Collection $ofTheDay, string $date) => $this->alerts->toReception(
                ($ofTheDay->count() === 1 ? '1 cita ' : $ofTheDay->count().' citas ').Dates::ofDay(CarbonImmutable::parse($date)).' sin confirmar',
                $ofTheDay->count() === 1
                    ? 'Llama a ese paciente para confirmar su asistencia. En la agenda sale con "Llamar".'
                    : 'Llama a esos pacientes para confirmar su asistencia. En la agenda salen con "Llamar".',
                link: ['label' => 'Ver citas por confirmar', 'url' => AppointmentResource::getUrl('index', ['tab' => 'pending'])],
            ));

        return $appointments->count();
    }

    /**
     * Saca de "Por enviar" los avisos de citas que ya pasaron (un recordatorio o una confirmación que nadie envió):
     * mandarlos después confunde al paciente. Devuelve cuántos retiró.
     */
    public function expireStale(): int
    {
        return WhatsappMessage::query()
            ->pending()
            ->whereIn('template_key', self::ONLY_BEFORE_THE_VISIT)
            ->whereHas('appointment', fn (Builder $query) => $query->where('starts_at', '<', now()))
            ->update(['status' => MessageStatus::Cancelled]);
    }

    /** El día (a las 00:00) en que se le recuerda la cita: el último en que el consultorio atiende antes de ella. */
    public function dayBefore(CarbonInterface $startsAt): CarbonImmutable
    {
        $dayBefore = CarbonImmutable::instance($startsAt)->startOfDay()->subDay();

        // Con la API el recordatorio sale solo: va el día anterior aunque el consultorio esté cerrado.
        if ($this->outbox->channel() === MessageChannel::CloudApi) {
            return $dayBefore;
        }

        for ($back = 0; $back < self::MAX_DAYS_BEFORE; $back++) {
            if ($this->isOpen($dayBefore->subDays($back))) {
                return $dayBefore->subDays($back);
            }
        }

        return $dayBefore;
    }

    /** Citas de mañana en adelante, hasta donde puede tocarles recordatorio hoy. */
    private function comingUp(Builder $query): Builder
    {
        return $query
            ->where('starts_at', '>=', today()->addDay())
            ->where('starts_at', '<', today()->addDays(self::MAX_DAYS_BEFORE + 1));
    }

    private function remindAt(Appointment $appointment): CarbonImmutable
    {
        return $this->dayBefore($appointment->starts_at)
            ->setTimeFromTimeString(config('consultorio.defaults.reminders.day_before_at', '09:00'));
    }

    private function flagAt(Appointment $appointment): CarbonImmutable
    {
        return $this->dayBefore($appointment->starts_at)
            ->setTimeFromTimeString(config('consultorio.defaults.reminders.flag_unconfirmed_at', '16:00'));
    }

    /**
     * Cuándo fue el último aviso que le dijo la fecha a cada cita (enviado o no).
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, CarbonInterface> Por id de la cita.
     */
    private function lastTold(Collection $appointments): Collection
    {
        return WhatsappMessage::query()
            ->whereIn('appointment_id', $appointments->modelKeys())
            ->whereIn('template_key', self::TELLS_THE_DATE)
            ->get(['appointment_id', 'created_at'])
            ->groupBy('appointment_id')
            ->map(fn (Collection $messages) => $messages->max('created_at'));
    }

    /** ¿Atiende algún doctor ese día y el consultorio no está bloqueado a la hora de los recordatorios? */
    private function isOpen(CarbonImmutable $day): bool
    {
        return $this->openDays[$day->toDateString()] ??= (function () use ($day): bool {
            $at = $day->setTimeFromTimeString(config('consultorio.defaults.reminders.day_before_at', '09:00'));

            return DoctorSchedule::query()->validOn($day)->whereHas('doctor', fn (Builder $query) => $query->active())->exists()
                && ! ScheduleBlock::query()->whereNull('doctor_id')->where('starts_at', '<=', $at)->where('ends_at', '>', $at)->exists();
        })();
    }
}
