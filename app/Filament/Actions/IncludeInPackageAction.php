<?php

namespace App\Filament\Actions;

use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * El doctor mete en el paquete del plan lo que iba aparte (una curación, lo que apareció después): entra sin
 * costo y el precio del paquete no cambia. Se avisa cuánto se deja de cobrar, porque es dinero que se regala.
 */
final class IncludeInPackageAction
{
    public static function make(): Action
    {
        return Action::make('includeInPackage')
            ->label('Meter al paquete')
            ->icon(Heroicon::OutlinedGift)
            ->authorize('update')
            ->visible(fn (TreatmentPlan $record) => app(TreatmentPlanService::class)->movableIntoPackage($record)->isNotEmpty())
            ->modalHeading(fn (TreatmentPlan $record) => 'Meter al paquete '.$record->offeredPackage()?->name)
            ->modalDescription('Lo que marques entra en el paquete sin costo: el precio del paquete no cambia y ya no se cobra aparte.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Meter al paquete')
            ->schema(fn (TreatmentPlan $record) => [
                self::checklist('items', $record),
                self::giftNote('items', $record),
            ])
            ->action(function (TreatmentPlan $record, array $data) {
                $items = app(TreatmentPlanService::class)->includeInPackage($record, $data['items'] ?? []);

                self::notify($record, $items);
            });
    }

    /** Lo que se puede meter en el paquete del plan, con su precio, para marcarlo. */
    public static function checklist(string $name, TreatmentPlan $plan): CheckboxList
    {
        return CheckboxList::make($name)
            ->label('Lo que entra al paquete, sin costo')
            ->options(fn () => self::options($plan))
            ->required()
            ->validationMessages(['required' => 'Marca lo que entra al paquete.'])
            ->live();
    }

    /** Cuánto se deja de cobrar con lo marcado. */
    public static function giftNote(string $name, TreatmentPlan $plan): Text
    {
        return Text::make(fn (Get $get) => self::giftText(app(TreatmentPlanService::class)->movableIntoPackage($plan)->whereIn('id', array_map('intval', (array) $get($name)))))
            ->color('warning')
            ->visible(fn (Get $get) => filled($get($name)));
    }

    /**
     * "Implante · pieza 21 · S/ 3,500.00", y de qué presupuesto viene si es de otro.
     *
     * @return array<int, string>
     */
    public static function options(TreatmentPlan $plan): array
    {
        return app(TreatmentPlanService::class)->movableIntoPackage($plan)
            ->mapWithKeys(fn (TreatmentPlanItem $item) => [$item->getKey() => $item->label().' · '.Money::format($item->subtotal())
                .($item->treatment_plan_id !== $plan->getKey() ? ' · de su presupuesto del '.$item->plan->created_at->format('d/m/Y') : '')])
            ->all();
    }

    /**
     * Lo que se regala al meterlo al paquete: "Se deja de cobrar S/ 150.00: Curación con resina · pieza 26."
     *
     * @param  Collection<int, TreatmentPlanItem>  $items
     */
    public static function giftText(Collection $items): string
    {
        $amount = round($items->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2);

        return $amount > 0
            ? 'Se deja de cobrar '.Money::format($amount).': '.$items->map(fn (TreatmentPlanItem $item) => $item->label())->implode(', ').'.'
            : '';
    }

    /** @param  Collection<int, TreatmentPlanItem>  $items */
    public static function notify(TreatmentPlan $plan, Collection $items): void
    {
        if ($items->isEmpty()) {
            Notification::make()->warning()->title('No se metió nada al paquete')->body('Lo marcado ya se empezó o ya es de un paquete.')->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Entró al paquete, sin costo: '.$items->map(fn (TreatmentPlanItem $item) => $item->label())->implode(', ').'.')
            ->body($plan->accepted_at !== null
                ? 'Paga lo mismo que firmó: no hay nada nuevo que firmar.'
                : 'El precio del paquete no cambia.')
            ->send();
    }
}
