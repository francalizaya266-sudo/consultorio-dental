<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Lo que se le cargó al paciente: sesiones del plan, tratamientos y conceptos sueltos. */
class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    protected static ?string $title = 'Cargos';

    protected static ?string $modelLabel = 'cargo';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('viewAny', Charge::class);
    }

    /** El saldo en la pestaña, para verlo sin entrar. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Patient $ownerRecord */
        $balance = $ownerRecord->balance();

        return $balance == 0 ? null : Money::balance($balance);
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Patient $ownerRecord */
        return $ownerRecord->balance() > 0 ? 'danger' : 'success';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['doctor', 'voidedBy']))
            ->columns([
                TextColumn::make('charged_at')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Concepto')
                    ->description(fn (Charge $record) => $record->doctor?->name)
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Precio')
                    ->money('PEN', locale: 'es_PE')
                    ->alignEnd(),
                TextColumn::make('discount')
                    ->label('Descuento')
                    ->formatStateUsing(fn (Charge $record) => (float) $record->discount > 0 ? '− '.Money::format($record->discount) : '—')
                    ->description(fn (Charge $record) => (float) $record->discount > 0 ? $record->discount_reason : null)
                    ->alignEnd(),
                TextColumn::make('net')
                    ->label('A pagar')
                    ->state(fn (Charge $record) => $record->net())
                    ->money('PEN', locale: 'es_PE')
                    ->weight('bold')
                    ->color(fn (Charge $record) => $record->voided_at ? 'gray' : null)
                    ->description(fn (Charge $record) => $record->voided_at ? 'Anulado: '.$record->void_reason : null)
                    ->alignEnd(),
            ])
            ->defaultSort('charged_at', 'desc')
            ->headerActions([
                Action::make('addCharge')
                    ->label('Agregar cargo')
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize(fn () => auth()->user()->can('create', Charge::class))
                    ->modalHeading('Agregar cargo')
                    ->modalDescription('Para lo que no salió de una cita: un material, un trabajo de laboratorio, un ajuste.')
                    ->schema([
                        TextInput::make('description')
                            ->label('Concepto')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('amount')
                            ->label('Precio')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),
                        TextInput::make('discount')
                            ->label('Descuento')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->lte('amount')
                            ->live(onBlur: true),
                        TextInput::make('discount_reason')
                            ->label('Motivo del descuento')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => (float) $get('discount') > 0),
                        Select::make('doctor_id')
                            ->label('Doctor')
                            ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id')),
                    ])
                    ->action(function (array $data) {
                        /** @var User $user */
                        $user = auth()->user();
                        app(BillingService::class)->addCharge($this->getOwnerRecord(), $data, $user);

                        Notification::make()->success()->title('Cargo agregado')->send();
                    }),
            ])
            ->recordActions([
                Action::make('void')
                    ->label('Anular')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Charge $record) => $record->voided_at === null)
                    ->authorize('update')
                    ->modalHeading('Anular cargo')
                    ->modalDescription('El cargo queda registrado como anulado (no se borra) y deja de sumar al saldo.')
                    ->modalSubmitActionLabel('Anular')
                    ->schema([
                        Textarea::make('reason')->label('Motivo')->required()->rows(2)->maxLength(250),
                    ])
                    ->action(function (array $data, Charge $record) {
                        /** @var User $user */
                        $user = auth()->user();
                        app(BillingService::class)->void($record, $user, $data['reason']);

                        Notification::make()->success()->title('Cargo anulado')->send();
                    }),
            ])
            ->emptyStateHeading('Sin cargos')
            ->emptyStateDescription('Se cargan solos al terminar cada atención.');
    }
}
