<?php

namespace App\Filament\Resources\ScheduleBlocks;

use App\Filament\Resources\ScheduleBlocks\Pages\ManageScheduleBlocks;
use App\Models\ScheduleBlock;
use App\Models\User;
use App\Services\Agenda\BlockScheduler;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/** Bloquear un horario (uno suelto o repetido cada semana) y cargar los feriados del año, desde "Bloqueos" o desde la agenda. */
final class ScheduleBlockActions
{
    /**
     * "Bloquear horario": la misma ventana en la lista de bloqueos y en la agenda, donde llega con el día que se mira.
     * Si quedan citas dentro, se dice cuáles: el bloqueo no las mueve.
     *
     * @param  (Closure(): array<string, mixed>)|null  $with  Lo que llega puesto desde la agenda (el día, el doctor).
     */
    public static function add(string $name = 'create', ?Closure $with = null): CreateAction
    {
        return CreateAction::make($name)
            ->model(ScheduleBlock::class)
            ->label('Bloquear horario')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->modalHeading('Bloquear horario')
            ->modalDescription('La agenda y la web dejan de ofrecer ese horario. Las citas que ya estaban ahí no se mueven solas: se te avisa cuáles son.')
            ->modalSubmitActionLabel('Bloquear')
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(fn () => auth()->user()->can('create', ScheduleBlock::class))
            ->schema(fn (Schema $schema) => ScheduleBlockResource::form($schema))
            ->fillForm(fn () => ScheduleBlockResource::defaults($with ? $with() : []))
            ->createAnother(false)
            ->using(function (array $data): ScheduleBlock {
                /** @var User $user */
                $user = auth()->user();

                $blocks = app(BlockScheduler::class)->create(
                    $user,
                    ManageScheduleBlocks::forOwnAgenda(ScheduleBlockResource::toAttributes($data)),
                    (int) ($data['repeat_weeks'] ?? 1),
                );

                ManageScheduleBlocks::warnAboutAppointments(...$blocks->all());

                return $blocks->first();
            })
            ->successNotificationTitle(fn (array $data) => (int) ($data['repeat_weeks'] ?? 1) > 1
                ? 'Horario bloqueado en '.(int) $data['repeat_weeks'].' semanas'
                : 'Horario bloqueado');
    }

    /**
     * "Cargar feriados": los del año, de una vez, como días completos para todo el consultorio. Se marcan los que
     * el consultorio no atiende; los que ya pasaron o ya están bloqueados no aparecen.
     */
    public static function loadHolidays(): Action
    {
        return Action::make('loadHolidays')
            ->label('Cargar feriados')
            ->icon(Heroicon::OutlinedFlag)
            ->color('gray')
            ->modalHeading('Cargar los feriados del año')
            ->modalDescription('Marca los feriados en que el consultorio no atiende. Cada uno queda como un bloqueo de día completo para todos los doctores; si después deciden atender ese día, se borra su bloqueo.')
            ->modalSubmitActionLabel('Bloquear esos días')
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(fn () => auth()->user()->managesAllAgendas())
            ->fillForm(function (): array {
                // En diciembre ya casi no quedan: se propone el año que viene.
                $year = app(BlockScheduler::class)->pendingHolidays(today()->year) === [] ? today()->year + 1 : today()->year;

                return ['year' => $year, 'days' => array_keys(app(BlockScheduler::class)->pendingHolidays($year))];
            })
            ->schema([
                Select::make('year')
                    ->label('Año')
                    ->options([today()->year => (string) today()->year, today()->year + 1 => (string) (today()->year + 1)])
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('days', array_keys(app(BlockScheduler::class)->pendingHolidays((int) $state)))),
                CheckboxList::make('days')
                    ->label('Feriados en que no se atiende')
                    ->options(fn (Get $get) => collect(app(BlockScheduler::class)->pendingHolidays((int) $get('year')))
                        ->map(fn (string $name, string $date) => ucfirst(CarbonImmutable::parse($date)->translatedFormat('l j \d\e F')).' · '.$name)
                        ->all())
                    ->columns(2)
                    ->bulkToggleable()
                    ->required()
                    ->validationMessages(['required' => 'Marca al menos un feriado.'])
                    ->visible(fn (Get $get) => app(BlockScheduler::class)->pendingHolidays((int) $get('year')) !== []),
                Text::make(fn (Get $get) => 'Los feriados que quedan de '.$get('year').' ya están bloqueados.')
                    ->visible(fn (Get $get) => app(BlockScheduler::class)->pendingHolidays((int) $get('year')) === []),
            ])
            ->action(function (array $data): void {
                /** @var User $user */
                $user = auth()->user();
                $blocks = app(BlockScheduler::class)->loadHolidays($user, $data['days'] ?? []);

                Notification::make()
                    ->success()
                    ->title(match ($blocks->count()) {
                        0 => 'No había feriados por cargar',
                        1 => '1 feriado bloqueado',
                        default => $blocks->count().' feriados bloqueados',
                    })
                    ->body($blocks->isEmpty() ? null : 'La agenda y la web ya no ofrecen esos días.')
                    ->send();

                ManageScheduleBlocks::warnAboutAppointments(...$blocks->all());
            });
    }
}
