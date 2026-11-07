<?php

namespace App\Filament\Concerns;

use App\Enums\MessageTemplateKey;
use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Actions\SignConsentAction;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\Dashboard\FollowUps;
use Filament\Actions\Action;

/**
 * Los botones del resumen del paciente (ficha y cita) que se hacen ahí mismo: cobrar lo que debe, firmar el
 * consentimiento de la cita de hoy y escribirle por WhatsApp con el mensaje ya armado.
 */
trait HasPatientSummaryActions
{
    abstract protected function summaryPatient(): Patient;

    public function collectPaymentAction(): Action
    {
        return RegisterPaymentAction::make(fn () => $this->summaryPatient())->name('collectPayment');
    }

    public function consentForVisitAction(): Action
    {
        return SignConsentAction::make(
            fn () => $this->summaryPatient(),
            fn (array $arguments) => Appointment::query()->whereBelongsTo($this->summaryPatient())->find($arguments['appointment'] ?? null)?->treatment,
            'consentForVisit',
        )->visible(fn () => auth()->user()->can('updateClinical', $this->summaryPatient()));
    }

    /** Cumpleaños, presupuesto sin respuesta o control: el aviso del seguimiento, para este paciente. */
    public function followUpAction(): Action
    {
        return SendWhatsAppAction::compose('followUp', function (array $arguments) {
            $key = MessageTemplateKey::tryFrom((string) ($arguments['template'] ?? ''));

            return $key ? app(FollowUps::class)->draft($key, $this->summaryPatient()) : null;
        });
    }
}
