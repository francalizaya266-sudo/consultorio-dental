<?php

namespace App\Filament\Resources\WaitlistEntries\Tables;

use App\Enums\WaitlistStatus;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryActions;
use App\Models\Doctor;
use App\Models\WaitlistEntry;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Quién espera, qué quiere, cuándo puede y en qué va: avisado, agendado o retirado. */
class WaitlistEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('patient')
                    ->label('Paciente')
                    ->state(fn (WaitlistEntry $record) => $record->patient->full_name)
                    ->description(fn (WaitlistEntry $record) => $record->patient->account ? Phone::display($record->patient->account->phone) : 'Sin celular')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn (Builder $patient) => $patient->search($search)))
                    ->weight('bold'),
                TextColumn::make('wants')
                    ->label('Quiere')
                    ->state(fn (WaitlistEntry $record) => $record->treatment?->name ?? 'Cualquier tratamiento')
                    // La nota va aquí: es parte de lo que pide ("quiere adelantar la del 20/11").
                    ->description(fn (WaitlistEntry $record) => ($record->doctor ? 'Con '.$record->doctor->name : 'Con cualquier doctor')
                        .(filled($record->notes) ? ' · '.Str::limit($record->notes, 90) : ''))
                    ->wrap(),
                TextColumn::make('earliest_date')
                    ->label('Puede venir')
                    ->formatStateUsing(fn (WaitlistEntry $record) => self::window($record))
                    ->description(fn (WaitlistEntry $record) => $record->preferred_period->getLabel())
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (WaitlistEntry $record) => $record->isActive() || $record->status === WaitlistStatus::Removed || $record->status === WaitlistStatus::Booked ? $record->status : 'expired')
                    ->formatStateUsing(fn (WaitlistStatus|string $state) => $state instanceof WaitlistStatus ? $state->getLabel() : 'Venció el plazo')
                    ->color(fn (WaitlistStatus|string $state) => $state instanceof WaitlistStatus ? $state->getColor() : 'gray')
                    ->description(fn (WaitlistEntry $record) => self::progress($record))
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Anotado')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('fits')
                    ->label('Pueden venir')
                    ->schema([
                        DatePicker::make('date')
                            ->label('Pueden venir el')
                            ->native(false)
                            ->displayFormat('D d/m/Y')
                            ->firstDayOfWeek(1)
                            ->closeOnDateSelection(),
                        Select::make('doctor_id')
                            ->label('Con')
                            ->placeholder('Cualquier doctor')
                            ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id')),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'] ?? null,
                        fn (Builder $fits, string $date) => $fits->availableOn(CarbonImmutable::parse($date), filled($data['doctor_id'] ?? null) ? (int) $data['doctor_id'] : null),
                    ))
                    ->indicateUsing(function (array $data): array {
                        if (blank($data['date'] ?? null)) {
                            return [];
                        }

                        $doctor = filled($data['doctor_id'] ?? null) ? Doctor::find($data['doctor_id'])?->name : null;

                        return [Indicator::make('Pueden venir el '.CarbonImmutable::parse($data['date'])->translatedFormat('D j M').($doctor ? ' con '.$doctor : ''))];
                    }),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                WaitlistEntryActions::offer(),
                WaitlistEntryActions::book(),
                ActionGroup::make([
                    EditAction::make()
                        ->modalHeading('Editar lo que espera'),
                    WaitlistEntryActions::release(),
                    WaitlistEntryActions::remove(),
                ]),
            ])
            // Cada pestaña dice lo suyo cuando está vacía: antes las tres decían "Nadie en lista de espera".
            ->emptyStateHeading(fn (HasTable $livewire) => match ($livewire->activeTab ?? null) {
                'booked' => 'Nadie de la lista se agendó todavía',
                'closed' => 'Nadie retirado ni vencido',
                default => 'Nadie en lista de espera',
            })
            ->emptyStateDescription(fn (HasTable $livewire) => match ($livewire->activeTab ?? null) {
                'booked' => 'Aquí quedan quienes pasaron de la lista de espera a una cita.',
                'closed' => 'Aquí quedan quienes se retiraron de la lista y aquellos cuya espera venció sin conseguir horario.',
                default => 'Anota aquí a quien quiere un horario antes o un día que hoy está lleno. Cuando se libere un hueco, el sistema te avisa a quién ofrecérselo.',
            });
    }

    /** "Lo antes posible", "Desde el 9 nov.", "Hasta el 30 nov." o "Del 9 al 30 nov.". */
    private static function window(WaitlistEntry $entry): string
    {
        $from = $entry->earliest_date?->isAfter(today()) ? $entry->earliest_date : null;
        $until = $entry->latest_date;

        return match (true) {
            $from && $until => 'Del '.$from->translatedFormat('j M').' al '.$until->translatedFormat('j M'),
            $from !== null => 'Desde el '.$from->translatedFormat('j M'),
            $until !== null => 'Hasta el '.$until->translatedFormat('j M'),
            default => 'Lo antes posible',
        };
    }

    /** En qué va: el horario que se le ofreció, la cita que se le dio o la que ya tiene. */
    private static function progress(WaitlistEntry $entry): ?string
    {
        $upcoming = $entry->upcoming_appointment_at ? CarbonImmutable::parse($entry->upcoming_appointment_at) : null;

        return match (true) {
            $entry->status === WaitlistStatus::Booked && $entry->appointment !== null => 'Cita: '.self::when($entry->appointment->starts_at),
            $entry->isHolding() => 'Le ofreciste '.self::when($entry->offered_starts_at).' · apartado hasta las '.$entry->held_until->format('H:i'),
            $entry->status === WaitlistStatus::Offered && $entry->offered_starts_at !== null => 'Le ofreciste '.self::when($entry->offered_starts_at).' · '.$entry->offered_at->diffForHumans(),
            $entry->isActive() && $upcoming !== null => 'Ya tiene cita el '.self::when($upcoming).': adelántasela',
            default => null,
        };
    }

    private static function when(CarbonInterface $date): string
    {
        return CarbonImmutable::instance($date)->translatedFormat('D j M, H:i');
    }
}
