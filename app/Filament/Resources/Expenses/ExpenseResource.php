<?php

namespace App\Filament\Resources\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Models\Expense;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Lo que el consultorio gasta: alquiler, sueldos, laboratorio, servicios. Lo pagado en efectivo sale de la caja
 * del día (el cierre lo descuenta) y todo junto se resta de lo cobrado en el reporte de ingresos.
 */
class ExpenseResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'gastos';

    protected static ?string $model = Expense::class;

    protected static ?string $modelLabel = 'gasto';

    protected static ?string $pluralModelLabel = 'gastos';

    protected static ?string $navigationLabel = 'Gastos';

    protected static ?string $navigationParentItem = 'Caja';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('description')
                ->label('En qué se gastó')
                ->placeholder('Por ejemplo: corona de Rosa Quispe, recibo de luz de octubre')
                ->required()
                ->maxLength(200)
                ->columnSpanFull(),
            Select::make('category')
                ->label('Tipo de gasto')
                ->options(ExpenseCategory::class)
                ->default(ExpenseCategory::Other->value)
                ->required()
                ->native(false)
                ->helperText('Los materiales no se anotan aquí: el inventario ya cuenta lo que se usa en cada atención.')
                ->columnSpanFull(),
            TextInput::make('amount')
                ->label('Monto')
                ->prefix('S/')
                ->numeric()
                ->minValue(0.1)
                ->maxValue(999999)
                ->required(),
            Select::make('method')
                ->label('Se pagó con')
                ->options(PaymentMethod::class)
                ->default(PaymentMethod::Cash->value)
                ->required()
                ->native(false)
                ->helperText('En efectivo sale de la caja: el cierre del día lo descuenta.'),
            DateTimePicker::make('spent_at')
                ->label('Cuándo')
                ->native(false)
                ->displayFormat('d/m/Y H:i')
                ->seconds(false)
                ->default(fn () => now())
                ->maxDate(fn () => now()->endOfDay())
                ->required()
                ->rule(fn () => RegisterPaymentAction::openDay())
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('spent_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Gasto')
                    ->description(fn (Expense $record) => $record->category->getLabel())
                    ->searchable()
                    ->wrap(),
                TextColumn::make('method')
                    ->label('Se pagó con')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('PEN', locale: 'es_PE')
                    ->color(fn (Expense $record) => $record->voided_at ? 'gray' : null)
                    ->description(fn (Expense $record) => $record->voided_at ? 'Anulado: '.$record->void_reason : null)
                    ->alignEnd()
                    ->summarize(Sum::make()
                        ->label('Total (sin anulados)')
                        ->money('PEN', locale: 'es_PE')
                        ->query(fn (QueryBuilder $query) => $query->whereNull('voided_at'))),
                TextColumn::make('paidBy.name')
                    ->label('Anotó')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('spent_at', 'desc')
            ->filters([
                Filter::make('spent_at')
                    ->label('Fecha')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y')->firstDayOfWeek(1)->closeOnDateSelection()->default(today()->startOfMonth()->toDateString()),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y')->firstDayOfWeek(1)->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(self::day($data['from'] ?? null), fn (Builder $q, $date) => $q->whereDate('spent_at', '>=', $date))
                        ->when(self::day($data['until'] ?? null), fn (Builder $q, $date) => $q->whereDate('spent_at', '<=', $date)))
                    ->indicateUsing(fn (array $data) => match (true) {
                        ! self::day($data['from'] ?? null) && ! self::day($data['until'] ?? null) => null,
                        self::day($data['from'] ?? null) === today()->startOfMonth()->toDateString() && ! self::day($data['until'] ?? null) => 'Este mes',
                        default => 'Del '.(($from = self::day($data['from'] ?? null)) ? now()->parse($from)->format('d/m/Y') : '…').' al '.(($until = self::day($data['until'] ?? null)) ? now()->parse($until)->format('d/m/Y') : 'hoy'),
                    }),
                SelectFilter::make('category')
                    ->label('Tipo de gasto')
                    ->options(ExpenseCategory::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::voidAction(),
                ]),
            ])
            ->emptyStateHeading('No hay gastos en estas fechas')
            ->emptyStateDescription('Anota aquí lo que sale: laboratorio, alquiler, sueldos, servicios. Así el reporte de ingresos dice cuánto queda.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('paidBy');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenses::route('/'),
        ];
    }

    /** Anular un gasto mal anotado: queda registrado (no se borra) y deja de restar. */
    private static function voidAction(): Action
    {
        return Action::make('void')
            ->label('Anular')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Expense $record) => $record->voided_at === null)
            ->authorize('update')
            ->authorizationTooltip()
            ->modalHeading('Anular gasto')
            ->modalDescription('El gasto queda registrado como anulado (no se borra).')
            ->modalSubmitActionLabel('Anular')
            ->schema([
                Textarea::make('reason')->label('Motivo')->required()->rows(2)->maxLength(250),
            ])
            ->action(function (array $data, Expense $record) {
                $record->update([
                    'voided_at' => now(),
                    'voided_by_user_id' => auth()->id(),
                    'void_reason' => $data['reason'],
                ]);

                Notification::make()->success()->title('Gasto anulado')->send();
            });
    }

    /** El calendario del panel guarda "2026-11-01 00:00:00": para filtrar por día basta "2026-11-01". */
    private static function day(?string $value): ?string
    {
        return filled($value) ? CarbonImmutable::parse($value)->toDateString() : null;
    }
}
