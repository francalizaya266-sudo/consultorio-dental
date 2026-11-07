<?php

namespace App\Filament\Support;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Una sola línea de tiempo del paciente: citas, atenciones, pagos y mensajes, de lo más nuevo a lo más viejo,
 * con un filtro por tipo. Reemplaza las pestañas Citas y Pagos que repetían lo mismo: cada pago lleva aquí su
 * constancia y, para quien puede, su "Anular".
 * Cada persona ve lo que le toca: lo clínico solo quien atiende, la plata solo caja y administración.
 *
 * @phpstan-type Event array{at: CarbonInterface, kind: string, icon: string, color: string, title: string, detail: string|null, url: string|null, receipt: string|null, payment: int|null}
 */
final class PatientTimeline
{
    /** Cuántos sucesos de cada tipo se juntan: años de historia, sin cargar todo de un paciente antiguo. */
    private const PER_KIND = 100;

    /** Cómo se llama cada tipo en el filtro. */
    public const KINDS = ['Cita' => 'Citas', 'Atención' => 'Atenciones', 'Pago' => 'Pagos', 'WhatsApp' => 'Mensajes'];

    /** @return Collection<int, Event> */
    public static function for(Patient $patient, User $user): Collection
    {
        $events = collect()
            ->merge(self::appointments($patient, $user))
            ->merge($user->can('viewClinical', $patient) ? self::consultations($patient) : [])
            ->merge($user->can('viewAny', Payment::class) ? self::payments($patient, $user) : [])
            ->merge(self::messages($patient));

        return $events
            ->map(fn (array $event) => [...$event, 'receipt' => $event['receipt'] ?? null, 'payment' => $event['payment'] ?? null])
            ->sortByDesc(fn (array $event) => $event['at']->getTimestamp())
            ->values();
    }

    /** @return list<array<string, mixed>> */
    private static function appointments(Patient $patient, User $user): array
    {
        return $patient->appointments()
            ->with(['treatment', 'doctor'])
            ->latest('starts_at')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (Appointment $appointment) => [
                'at' => $appointment->starts_at,
                'kind' => 'Cita',
                'icon' => 'heroicon-o-calendar-days',
                'color' => $appointment->status->getColor(),
                'title' => $appointment->treatment->name.' · '.$appointment->status->getLabel(),
                'detail' => collect([
                    'con '.$appointment->doctor->name,
                    $appointment->status === AppointmentStatus::Cancelled ? $appointment->cancellation_reason : null,
                ])->filter()->implode(' · '),
                'url' => $user->can('view', $appointment) ? AppointmentResource::getUrl('view', ['record' => $appointment]) : null,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private static function consultations(Patient $patient): array
    {
        return $patient->consultations()
            ->with(['doctor', 'diagnoses.icd10Code'])
            ->latest('attended_at')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (Consultation $consultation) => [
                'at' => $consultation->attended_at,
                'kind' => 'Atención',
                'icon' => 'heroicon-o-clipboard-document-check',
                'color' => $consultation->closed_at ? 'primary' : 'warning',
                'title' => $consultation->closed_at ? 'Atención' : 'Atención sin terminar',
                'detail' => collect([
                    $consultation->diagnoses->map(fn ($diagnosis) => $diagnosis->icd10Code?->code.($diagnosis->tooth ? ' ('.$diagnosis->tooth.')' : ''))->filter()->implode(', ') ?: null,
                    $consultation->procedures ? str($consultation->procedures)->limit(90)->toString() : null,
                    $consultation->doctor->name,
                ])->filter()->implode(' · '),
                'url' => ConsultationResource::urlFor($consultation),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private static function payments(Patient $patient, User $user): array
    {
        return $patient->payments()
            ->latest('paid_at')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (Payment $payment) => [
                'at' => $payment->paid_at,
                'kind' => 'Pago',
                'icon' => 'heroicon-o-banknotes',
                'color' => $payment->voided_at ? 'gray' : 'success',
                'title' => ((float) $payment->amount < 0 ? 'Se le devolvió '.Money::format(-$payment->amount) : 'Pagó '.Money::format($payment->amount))
                    .' · '.$payment->method->getLabel().($payment->voided_at ? ' (anulado)' : ''),
                'detail' => $payment->voided_at ? 'Anulado: '.$payment->void_reason : $payment->notes,
                'url' => null,
                'receipt' => RegisterPaymentAction::receiptUrl($payment),
                // Se anula desde aquí si todavía cuenta y el día no está cerrado para quien mira.
                'payment' => $payment->voided_at === null && $user->can('update', $payment) ? $payment->getKey() : null,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private static function messages(Patient $patient): array
    {
        return WhatsappMessage::query()
            ->whereBelongsTo($patient)
            ->latest('id')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (WhatsappMessage $message) => [
                'at' => $message->sent_at ?? $message->created_at,
                'kind' => 'WhatsApp',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'color' => $message->status->getColor(),
                'title' => $message->template_key->getLabel().' · '.mb_strtolower($message->status->getLabel()),
                'detail' => null,
                'url' => null,
            ])
            ->all();
    }
}
