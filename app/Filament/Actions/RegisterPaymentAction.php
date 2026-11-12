<?php

namespace App\Filament\Actions;

use App\Enums\PaymentMethod;
use App\Filament\Forms\PaymentFields;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\DailyClosing;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\CashClosing;
use App\Services\Patients\PatientInsights;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Registrar un pago: el mismo formulario en Caja, en la ficha del paciente, en las listas de cobro y al
 * terminar la atención. Dice lo que debe y por qué, propone el saldo y deja la constancia a un clic.
 */
final class RegisterPaymentAction
{
    /**
     * @param  Closure|null  $patient  Devuelve el paciente (puede recibir $record). Sin él, se elige en el formulario.
     * @param  Closure|null  $amount  Lo que se propone cobrar (puede recibir $record). Por defecto, lo que debe.
     */
    public static function make(?Closure $patient = null, ?Closure $amount = null): Action
    {
        $resolve = fn (Action $action): ?Patient => $patient ? $action->evaluate($patient) : null;

        return Action::make('registerPayment')
            ->label('Cobrar')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color(fn (Action $action) => ($resolve($action)?->balance() ?? 0) > 0 ? 'success' : 'gray')
            ->visible(fn () => self::user()->can('create', Payment::class))
            ->modalHeading(fn (Action $action) => ($found = $resolve($action)) ? 'Cobrar a '.$found->full_name : 'Cobrar')
            ->modalSubmitAction(fn (Action $action) => $action->label('Cobrar')->color('primary'))
            ->modalWidth('lg')
            ->fillForm(function (Action $action) use ($resolve, $amount) {
                $found = $resolve($action);
                $proposed = $amount ? $action->evaluate($amount) : max(0, $found?->balance() ?? 0);

                return [
                    'patient_id' => $found?->getKey(),
                    'amount' => $proposed > 0 ? round($proposed, 2) : null,
                    'method' => ($found?->usualPaymentMethod() ?? PaymentMethod::Cash)->value,
                    'paid_at' => now()->format('Y-m-d H:i'),
                ];
            })
            ->schema([
                Select::make('patient_id')
                    ->label('Paciente')
                    ->placeholder('Busca por nombre, DNI o celular')
                    ->searchable()
                    ->required()
                    // Antes de escribir, a quienes se les cargó algo hoy y no lo pagaron: son a quienes más se les cobra desde aquí.
                    ->options(fn () => self::owingFromToday())
                    ->getSearchResultsUsing(fn (string $search) => Patient::query()
                        ->search($search)
                        ->with('account')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Patient $found) => [$found->getKey() => $found->selectLabel()]))
                    ->getOptionLabelUsing(fn ($value) => Patient::with('account')->find($value)?->selectLabel())
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state) {
                        if (! $found = Patient::find($state)) {
                            return;
                        }

                        $balance = $found->balance();
                        $set('amount', $balance > 0 ? $balance : null);
                        $set('method', $found->usualPaymentMethod()->value);
                    })
                    ->hidden($patient !== null),
                Text::make(fn (Get $get) => self::owed(Patient::find($get('patient_id'))))
                    ->visible(fn (Get $get) => filled($get('patient_id'))),
                ...PaymentFields::make(),
                DateTimePicker::make('paid_at')
                    ->label('Fecha')
                    ->seconds(false)
                    ->maxDate(now()->endOfDay())
                    ->required()
                    ->rule(fn () => self::openDay()),
                TextInput::make('notes')
                    ->label('Nota')
                    ->placeholder('Por ejemplo: cuota 2 de ortodoncia')
                    ->maxLength(255),
            ])
            ->action(function (array $data, Action $action) use ($resolve) {
                $found = $resolve($action) ?? Patient::findOrFail($data['patient_id']);
                $payment = app(BillingService::class)->registerPayment($found, $data, self::user());

                self::notifyRegistered($payment);
            });
    }

    /**
     * "Pago registrado" (o "Devolución registrada"), con el saldo que quedó y la constancia para imprimir o enviar.
     * Al cobrar se dice también cuándo vuelve: su próxima cita si ya la tiene o, si no, lo que le toca y el botón
     * para agendarla ahí mismo, mientras el paciente sigue en el mostrador.
     */
    public static function notifyRegistered(Payment $payment): void
    {
        $next = (float) $payment->amount > 0 ? self::nextVisit($payment) : null;

        Notification::make()
            ->success()
            ->title((float) $payment->amount < 0 ? 'Devolución registrada' : 'Pago registrado')
            ->body(collect(['Saldo de '.$payment->patient->full_name.': '.Money::balance($payment->patient->balance()), $next['text'] ?? null])->filter()->implode('<br>'))
            ->actions(array_values(array_filter([
                ($next['url'] ?? null) ? Action::make('book')
                    ->label('Agendar próxima cita')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url($next['url'])
                    ->button() : null,
                Action::make('receipt')
                    ->label('Ver constancia')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(self::receiptUrl($payment), shouldOpenInNewTab: true)
                    ->button()
                    ->color(($next['url'] ?? null) ? 'gray' : 'primary'),
            ])))
            ->persistent(($next['url'] ?? null) !== null)
            ->send();
    }

    /**
     * Cuándo vuelve el paciente que acaba de pagar: la cita que ya tiene, la sesión que sigue de su plan con su
     * fecha sugerida, o su control. Con url, falta agendarla.
     *
     * @return array{text: string, url: string|null}
     */
    private static function nextVisit(Payment $payment): array
    {
        $insights = app(PatientInsights::class);
        $patient = $payment->patient;
        $user = self::user();

        if ($booked = $insights->upcomingVisit($patient)) {
            return ['text' => 'Su próxima cita: '.$booked->starts_at->translatedFormat('l j \d\e F, g:i a').' · '.$booked->treatment->name.'.', 'url' => null];
        }

        $canBook = $user->can('create', Appointment::class);
        $doctorId = $payment->appointment?->doctor_id ?? $payment->doctor_id;

        if ($suggested = $insights->suggestedNextVisit($patient, withTooth: $user->canSeeClinical())) {
            return [
                'text' => 'Sin próxima cita. Le toca: '.$suggested['label'].', el '.$suggested['on']->translatedFormat('l j \d\e F').' ('.mb_strtolower(mb_substr($suggested['why'], 0, 1)).mb_substr($suggested['why'], 1).').',
                'url' => $canBook ? AppointmentResource::nextVisitUrl($patient, $doctorId, $suggested['on']) : null,
            ];
        }

        $checkup = $insights->checkup($patient);

        return [
            'text' => $checkup
                ? 'Sin próxima cita. Su '.$checkup['kind']->checkupLabel().' le toca en '.$checkup['on']->translatedFormat('F \d\e Y').'.'
                : 'Sin próxima cita.',
            'url' => $canBook ? AppointmentResource::nextVisitUrl($patient, $doctorId, $checkup && ! $checkup['due'] ? $checkup['on'] : null) : null,
        ];
    }

    /** La hoja de la constancia, para imprimirla o enviarla por WhatsApp. */
    public static function receiptUrl(Payment $payment): string
    {
        return Filament::getPanel('admin')->route('print.receipt', ['payment' => $payment]);
    }

    /** Un pago no entra en un día cuya caja ya se cerró (salvo que lo registre la administradora). */
    public static function openDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (filled($value) && DailyClosing::locksFor(self::user(), $value)) {
                $fail(DailyClosing::LOCKED);
            }
        };
    }

    /**
     * "Olivia Arenas · debe S/ 120.00": los mismos que cuentan "Cobrado hoy" en el inicio y el cierre del día,
     * primero quien más debe de lo de hoy. Lo que dice es todo su saldo, que es lo que se propone cobrar.
     *
     * @return array<int, string>
     */
    private static function owingFromToday(): array
    {
        return (new CashClosing(CarbonImmutable::today()))->pending()
            ->take(20)
            ->mapWithKeys(fn (array $line) => [$line['patient']->getKey() => $line['patient']->full_name.' · debe '.Money::format((float) $line['patient']->balance_due)])
            ->all();
    }

    /** "Debe S/ 120.00: Curación con resina S/ 120.00 (5/11)." */
    private static function owed(?Patient $patient): string
    {
        if (! $patient) {
            return '';
        }

        $balance = $patient->balance();

        if ($balance <= 0) {
            return $balance < 0 ? 'Tiene a favor '.Money::format(-$balance).': lo que pague queda a cuenta.' : 'Está al día: lo que pague queda a cuenta.';
        }

        $owed = app(BillingService::class)->unpaidCharges($patient);
        $detail = $owed->take(4)
            ->map(fn (array $row) => $row['charge']->description.' '.Money::format($row['due']).' ('.$row['charge']->charged_at->format('j/n').')')
            ->implode(' · ');

        return 'Debe '.Money::format($balance).': '.$detail.($owed->count() > 4 ? ' y '.($owed->count() - 4).' más' : '').'.';
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
