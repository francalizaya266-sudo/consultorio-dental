<?php

namespace App\Filament\Pages;

use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién debe y cuánto, con el que más debe primero: se cobra desde aquí mismo. Va dentro de Caja,
 * que es donde se cobra; la lista de pacientes queda para buscar pacientes.
 */
class OwingBalances extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.owing-balances';

    protected static ?string $title = 'Saldos pendientes';

    protected static ?string $slug = 'saldos-pendientes';

    protected static ?string $navigationParentItem = 'Caja';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Charge::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PatientResource::getEloquentQuery()->owing()->withBalance()->with('account'))
            ->stackedOnMobile()
            ->description(fn () => self::owedTotal())
            ->columns([
                TextColumn::make('last_name')
                    ->label('Paciente')
                    ->formatStateUsing(fn (Patient $record) => $record->full_name)
                    ->description(fn (Patient $record) => $record->document_number ? $record->document_type?->getLabel().' '.$record->document_number : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->search($search)),
                TextColumn::make('account.phone')
                    ->label('Celular')
                    ->formatStateUsing(fn (string $state) => Phone::display($state))
                    ->placeholder('Sin celular'),
                TextColumn::make('last_visit_at')
                    ->label('Última visita')
                    ->date('d/m/Y')
                    ->placeholder('Aún no viene')
                    ->sortable(),
                TextColumn::make('balance_due')
                    ->label('Debe')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ->color('danger')
                    ->weight('bold')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('balance_due', $direction))
                    ->alignEnd(),
            ])
            // Primero quien más debe.
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('balance_due'))
            ->recordUrl(fn (Patient $record) => PatientResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                RegisterPaymentAction::make(fn (Patient $record) => $record)
                    ->name('collect')
                    ->label('Cobrar')
                    ->color('success')
                    ->visible(fn () => auth()->user()->can('create', Payment::class)),
                Action::make('file')
                    ->label('Ficha')
                    ->icon(Heroicon::OutlinedUser)
                    ->color('gray')
                    ->url(fn (Patient $record) => PatientResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('Nadie debe')
            ->emptyStateDescription('Cuando un paciente se vaya debiendo, aparece aquí con su saldo.');
    }

    /** "Por cobrar: S/ 2,350.00 entre 9 pacientes". */
    private static function owedTotal(): ?string
    {
        $balances = PatientResource::getEloquentQuery()->owing()->withBalance()->pluck('balance_due');

        if ($balances->isEmpty()) {
            return null;
        }

        return 'Por cobrar: '.Money::format((float) $balances->sum()).' entre '.$balances->count().($balances->count() === 1 ? ' paciente' : ' pacientes');
    }
}
