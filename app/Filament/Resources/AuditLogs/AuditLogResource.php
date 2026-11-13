<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Concerns\HasSettingsMenuGroup;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Support\ChangeHistory;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Historial de cambios de Configuración: quién cambió qué y cuándo, en Ajustes, la página web, usuarios, doctores,
 * tratamientos, paquetes y textos. Solo se lee.
 */
class AuditLogResource extends Resource
{
    use HasSettingsMenuGroup, HasSpanishLabels;

    protected static ?string $slug = 'historial-de-cambios';

    protected static ?string $model = AuditLog::class;

    protected static ?string $modelLabel = 'cambio';

    protected static ?string $pluralModelLabel = 'historial de cambios';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function settingsMenuGroup(): string
    {
        return 'Consultorio';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->whereIn('auditable_type', array_keys(ChangeHistory::SECTIONS))
                // Al restaurar algo borrado quedan dos registros: basta el que dice "Se restauró".
                ->whereNot(fn (Builder $twice) => $twice->where('event', 'updated')->where('new_values', '{"deleted_at":null}'))
                ->with(['user', 'auditable' => fn (MorphTo $subject) => $subject->withTrashed()]))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('user.name')
                    ->label('Quién')
                    ->placeholder('El sistema'),
                TextColumn::make('section')
                    ->label('Dónde')
                    ->state(fn (AuditLog $record) => ChangeHistory::section($record))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('subject')
                    ->label('Qué')
                    ->state(fn (AuditLog $record) => ChangeHistory::subject($record))
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('summary')
                    ->label('Cambio')
                    ->state(fn (AuditLog $record) => ChangeHistory::summary($record))
                    ->lineClamp(3)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('section')
                    ->label('Dónde')
                    ->options(fn () => array_combine($sections = array_values(array_unique(ChangeHistory::SECTIONS)), $sections))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $section) => $query->whereIn('auditable_type', ChangeHistory::classesOf($section)),
                    )),
                SelectFilter::make('user_id')
                    ->label('Quién')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('detail')
                    ->label('Ver')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (AuditLog $record) => ChangeHistory::subject($record))
                    ->modalDescription(fn (AuditLog $record) => ChangeHistory::section($record).' · '.$record->created_at->format('d/m/Y H:i').' · '.($record->user?->name ?? 'El sistema'))
                    ->modalContent(fn (AuditLog $record) => view('filament.settings.change-detail', [
                        'event' => $record->event,
                        'summary' => ChangeHistory::summary($record),
                        'changes' => ChangeHistory::changes($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Todavía no hay cambios')
            ->emptyStateDescription('Aquí queda cada cambio que se haga en Configuración y en la página web.')
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditLogs::route('/'),
        ];
    }
}
