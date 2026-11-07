<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CashClosing;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Si el día cuadra: lo que había para cobrar, lo cobrado y quién se fue debiendo (con "Cobrar"), las citas que
 * quedaron sin cerrar y el arqueo del efectivo. Cerrado el día, sus pagos ya no cambian sin la administradora.
 */
class CashClosingCheck extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithPageFilters;
    use InteractsWithSchemas {
        // El de los esquemas resuelve las acciones desde la vista ($this->collectAction).
        InteractsWithSchemas::__get insteadof InteractsWithPageFilters;
    }

    protected string $view = 'filament.widgets.cash-closing-check';

    protected int|string|array $columnSpan = 'full';

    private ?CashClosing $closing = null;

    /** @var array<string, mixed>|null */
    private ?array $closingFilters = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Payment::class) ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $closing = $this->closing();
        $wholeDay = $closing->receivedBy === null;

        return [
            'wholeDay' => $wholeDay,
            'collected' => $closing->total(),
            'expected' => $closing->expected(),
            'pending' => $wholeDay ? $closing->pending() : collect(),
            'pendingTotal' => $wholeDay ? $closing->pendingTotal() : 0.0,
            'unclosed' => $wholeDay ? $closing->unclosedAppointments()->map(fn ($appointment) => [
                'time' => $appointment->starts_at->format('H:i'),
                'name' => $appointment->patient->full_name,
                'detail' => $appointment->treatment->name.' · '.mb_strtolower($appointment->status->getLabel()),
                'url' => AppointmentResource::getUrl('view', ['record' => $appointment]),
            ]) : collect(),
            'record' => $closing->record(),
        ];
    }

    /** Cobrar a quien se fue debiendo, desde el mismo cierre. */
    public function collectAction(): Action
    {
        return RegisterPaymentAction::make(fn (array $arguments) => Patient::query()->find($arguments['patient'] ?? null))
            ->name('collect')
            ->label('Cobrar')
            ->color('success')
            ->size('sm')
            ->after(fn () => $this->closing = null);
    }

    /** El arqueo: el fondo con que se abrió, el efectivo que debe haber y el que se contó. */
    public function closeDayAction(): Action
    {
        return Action::make('closeDay')
            ->label('Cerrar el día')
            ->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn () => $this->closing()->receivedBy === null && $this->closing()->record() === null)
            ->modalHeading(fn () => 'Cerrar la caja del '.$this->closing()->date->translatedFormat('l j \d\e F'))
            ->modalDescription('Cuenta el efectivo de la caja. Después del cierre, anular un pago de este día o registrar uno con esta fecha lo hace solo la administradora.')
            ->modalSubmitActionLabel('Cerrar el día')
            ->modalWidth('lg')
            ->fillForm(fn () => ['opening_cash' => $this->closing()->suggestedOpeningCash()])
            ->schema([
                TextInput::make('opening_cash')
                    ->label('Fondo con que se abrió la caja')
                    ->prefix('S/')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true),
                Text::make(fn (Get $get) => 'Debe haber '.Money::format((float) $get('opening_cash') + $this->closing()->cash()).': el fondo más '.Money::format($this->closing()->cash()).' cobrados en efectivo.'),
                TextInput::make('counted_cash')
                    ->label('Efectivo contado')
                    ->prefix('S/')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true),
                Text::make(fn (Get $get) => self::differenceText($this->difference($get)))
                    ->color(fn (Get $get) => abs($this->difference($get) ?? 0) < 0.005 ? 'success' : 'warning')
                    ->visible(fn (Get $get) => filled($get('counted_cash'))),
                TextInput::make('notes')
                    ->label('Observaciones')
                    ->placeholder('Por ejemplo: se dio vuelto de más')
                    ->maxLength(255)
                    ->required(fn (Get $get) => abs($this->difference($get) ?? 0) >= 0.005)
                    ->validationMessages(['required' => 'Si no cuadra, anota por qué.']),
                Text::make(fn () => $this->leftOpenText())
                    ->color('warning')
                    ->visible(fn () => $this->leftOpenText() !== null),
            ])
            ->action(function (array $data) {
                $record = $this->closing()->close((float) $data['opening_cash'], (float) $data['counted_cash'], $data['notes'] ?? null, self::user());

                Notification::make()
                    ->success()
                    ->title('Día cerrado')
                    ->body(self::differenceText($record->difference()))
                    ->send();
            });
    }

    /** Solo la administradora reabre un día cerrado (para corregir un pago de ese día). */
    public function reopenDayAction(): Action
    {
        return Action::make('reopenDay')
            ->label('Reabrir el día')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->size('sm')
            ->visible(fn () => self::user()->isAdmin() && $this->closing()->record() !== null)
            ->requiresConfirmation()
            ->modalHeading('¿Reabrir el día?')
            ->modalDescription('Se podrán anular y registrar pagos de este día. Después vuelve a cerrarlo con el efectivo contado. Queda registrado quién lo reabrió.')
            ->modalSubmitActionLabel('Reabrir')
            ->action(function () {
                $this->closing()->record()?->delete();

                Notification::make()->success()->title('Día reabierto')->send();
            });
    }

    /** "Cuadra", "Faltan S/ 20.00" o "Sobran S/ 5.00". */
    public static function differenceText(?float $difference): string
    {
        return match (true) {
            $difference === null => '',
            abs($difference) < 0.005 => 'Cuadra: el efectivo contado es el que debía haber.',
            $difference < 0 => 'Faltan '.Money::format(-$difference).' en la caja.',
            default => 'Sobran '.Money::format($difference).' en la caja.',
        };
    }

    private function difference(Get $get): ?float
    {
        if (! is_numeric($get('counted_cash'))) {
            return null;
        }

        return round((float) $get('counted_cash') - ((float) $get('opening_cash') + $this->closing()->cash()), 2);
    }

    /** Lo que sigue pendiente al cerrar: no impide cerrar, pero conviene verlo antes. */
    private function leftOpenText(): ?string
    {
        $closing = $this->closing();
        $pending = $closing->pending()->count();
        $unclosed = $closing->unclosedAppointments()->count();

        $parts = array_filter([
            $pending > 0 ? 'faltó cobrar '.Money::format($closing->pendingTotal()).' a '.$pending.($pending === 1 ? ' paciente' : ' pacientes') : null,
            $unclosed > 0 ? $unclosed.($unclosed === 1 ? ' cita quedó sin cerrar' : ' citas quedaron sin cerrar') : null,
        ]);

        return $parts === [] ? null : 'Antes de cerrar: '.implode(' y ', $parts).'.';
    }

    /** El cierre del día y la persona elegidos; se arma una vez por pedido (cada parte lo consulta varias veces). */
    private function closing(): CashClosing
    {
        $filters = $this->pageFilters ?? [];

        if ($this->closing === null || $this->closingFilters !== $filters) {
            $this->closing = CashClosing::fromFilters($filters);
            $this->closingFilters = $filters;
        }

        return $this->closing;
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
