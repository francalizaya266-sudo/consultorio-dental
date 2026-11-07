<?php

namespace App\Filament\Pages;

use App\Enums\StockMovementType;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\StockMovement;
use App\Models\Supply;
use App\Services\Inventory\InventoryService;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * El conteo del mes: se escribe lo que hay en el estante y cada diferencia queda como ajuste,
 * con quién contó y por qué. Lo que se deja vacío no cambia. Los implantes van aparte: se
 * controlan unidad por unidad, con su número de serie.
 */
class StockCount extends Page
{
    protected static ?string $title = 'Conteo del inventario';

    protected static ?string $slug = 'conteo';

    protected static ?string $navigationLabel = 'Conteo';

    /** No va en el menú: se entra con "Contar" en Inventario, que queda marcado mientras tanto. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationParentItem = 'Inventario';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('moveStock', Supply::class) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['reason' => 'Conteo de '.today()->translatedFormat('F Y'), 'counted' => []]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        $last = StockMovement::query()->where('type', StockMovementType::Adjustment)->with('user')->latest('id')->first();

        return $last
            ? 'Último ajuste: '.$last->created_at->translatedFormat('j \d\e F').($last->user ? ' por '.$last->user->name : '').'.'
            : 'Escribe lo que hay en el estante. Así el inventario empieza con lo que de verdad tienes.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('reason')
                            ->label('Motivo de las diferencias')
                            ->helperText('Cada material que no coincida queda ajustado con tu nombre y este motivo. Lo que dejes vacío no cambia.')
                            ->required()
                            ->maxLength(200),
                    ]),
                ...$this->categorySections(),
                Text::make('Los implantes se controlan unidad por unidad, con su número de serie: si falta uno, dale de baja desde su ficha en Inventario.')
                    ->visible(fn () => Supply::query()->active()->where('tracks_serial', true)->exists()),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Guardar conteo')->submit('save'),
                            Action::make('back')->label('Volver al inventario')->color('gray')->url(SupplyResource::getUrl('index')),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $supplies = $this->countable()->keyBy('id');
        $missing = 0;
        $extra = 0;

        foreach ($state['counted'] ?? [] as $id => $counted) {
            $supply = $supplies->get((int) $id);

            if ($supply === null || $counted === null || $counted === '') {
                continue;
            }

            $difference = round((float) $counted - (float) $supply->stock, 2);

            if ($difference == 0.0) {
                continue;
            }

            app(InventoryService::class)->adjust($supply, (float) $counted, $state['reason'], auth()->user());
            $difference < 0 ? $missing++ : $extra++;
        }

        $adjusted = $missing + $extra;

        Notification::make()
            ->success()
            ->title($adjusted === 0 ? 'Todo coincide con el sistema.' : 'Conteo guardado: '.($adjusted === 1 ? '1 material ajustado' : "{$adjusted} materiales ajustados").'.')
            ->body($adjusted === 0 ? null : collect([
                $missing ? ($missing === 1 ? 'En 1 había menos' : "En {$missing} había menos") : null,
                $extra ? ($extra === 1 ? 'en 1 había más' : "en {$extra} había más") : null,
            ])->filter()->implode(' y ').'. Lo ves en Movimientos.')
            ->send();

        $this->form->fill(['reason' => $state['reason'], 'counted' => []]);
    }

    /** @return list<Section> Una sección por categoría, con lo que dice el sistema bajo cada material. */
    private function categorySections(): array
    {
        return $this->countable()
            ->groupBy(fn (Supply $supply) => $supply->category->value)
            ->map(fn (Collection $supplies) => Section::make($supplies->first()->category->getLabel())
                ->collapsible()
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema($supplies->map(fn (Supply $supply) => TextInput::make('counted.'.$supply->getKey())
                    ->label($supply->name)
                    ->numeric()
                    ->minValue(0)
                    ->suffix(Quantity::unit($supply->unit, 2))
                    ->helperText('En el sistema: '.$supply->quantity($supply->stock))
                    ->placeholder('¿Cuánto hay?'))
                    ->all()))
            ->values()
            ->all();
    }

    /** @return Collection<int, Supply> */
    private function countable(): Collection
    {
        return Supply::query()->active()->where('tracks_serial', false)->orderBy('category')->orderBy('name')->get();
    }
}
