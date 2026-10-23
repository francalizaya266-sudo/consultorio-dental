<?php

namespace App\Filament\Actions;

use App\Enums\PatientRelationship;
use App\Enums\PlanItemStatus;
use App\Enums\PlanPaymentMode;
use App\Exceptions\ClinicalException;
use App\Filament\Forms\SignaturePad;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Clinical\ConsentService;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * El paciente acepta el plan: se revisa con él lo que acepta y cómo lo pagará, y firma con el dedo.
 * Con la firma el precio queda fijo. Si después se agregó algo, se firma solo eso.
 */
final class SignPlanAction
{
    public static function make(): Action
    {
        return Action::make('accept')
            ->label(fn (TreatmentPlan $record) => self::isFirst($record) ? 'El paciente acepta y firma' : 'Firmar lo agregado')
            ->icon(Heroicon::OutlinedPencil)
            ->color('success')
            ->visible(fn (TreatmentPlan $record) => $record->isOpen() && (self::isFirst($record) || $record->itemsToAccept()->isNotEmpty()))
            ->authorize('update')
            ->modalHeading(fn (TreatmentPlan $record) => self::isFirst($record) ? 'Aceptar el plan' : 'Aceptar lo agregado al plan')
            ->modalDescription('Revisa con el paciente lo que acepta y cómo lo pagará, y entrégale la tablet para que firme. Con la firma, el precio ya no cambia.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Guardar firma')
            ->fillForm(function (TreatmentPlan $record) {
                $signer = app(ConsentService::class)->defaultSigner($record->patient);

                return [
                    ...$signer,
                    'signer_relationship' => $signer['signer_relationship']->value,
                    'payment_mode' => PlanPaymentMode::PerSession->value,
                    'down_payment' => 0,
                    'installments' => 6,
                    'first_due_on' => today()->addMonthNoOverflow()->toDateString(),
                    'upfront_discount' => 0,
                ];
            })
            ->schema(fn (TreatmentPlan $record) => [
                Text::make(new HtmlString(self::itemsHtml(self::items($record))))
                    ->weight(FontWeight::Medium),
                Section::make('Forma de pago')
                    ->compact()
                    ->visible(self::isFirst($record))
                    ->schema([
                        Radio::make('payment_mode')
                            ->hiddenLabel()
                            ->options(PlanPaymentMode::class)
                            ->required()
                            ->live(),
                        Grid::make(3)
                            ->visible(fn (Get $get) => self::mode($get) === PlanPaymentMode::Installments)
                            ->schema([
                                TextInput::make('down_payment')
                                    ->label('Inicial')
                                    ->prefix('S/')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live(onBlur: true),
                                TextInput::make('installments')
                                    ->label('Número de cuotas')
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(TreatmentPlanService::MAX_INSTALLMENTS)
                                    ->required()
                                    ->live(onBlur: true),
                                DatePicker::make('first_due_on')
                                    ->label('Primera cuota')
                                    ->minDate(today())
                                    ->required()
                                    ->live(),
                            ]),
                        TextInput::make('upfront_discount')
                            ->label('Descuento por pagar todo junto')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn () => self::total($record))
                            ->visible(fn (Get $get) => self::mode($get) === PlanPaymentMode::Upfront)
                            ->live(onBlur: true),
                        Text::make(fn (Get $get) => self::preview($record, $get))
                            ->weight(FontWeight::Bold),
                    ]),
                Grid::make(3)->schema([
                    Select::make('signer_relationship')
                        ->label('Quién firma')
                        ->options(collect(PatientRelationship::cases())->mapWithKeys(fn (PatientRelationship $r) => [
                            $r->value => $r === PatientRelationship::Self ? 'El mismo paciente' : $r->getLabel().' (apoderado)',
                        ]))
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set) use ($record) {
                            if ($state === PatientRelationship::Self->value) {
                                $set('signer_name', $record->patient->full_name);
                                $set('signer_document', $record->patient->document_number);
                            }
                        }),
                    TextInput::make('signer_name')
                        ->label('Nombre de quien firma')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('signer_document')
                        ->label('Documento')
                        ->maxLength(20),
                ]),
                SignaturePad::make('signature')
                    ->label('Firma')
                    ->required()
                    ->validationMessages(['required' => 'Falta la firma.']),
            ])
            ->action(function (array $data, TreatmentPlan $record, Action $action) {
                $first = self::isFirst($record);

                /** @var User $user */
                $user = auth()->user();

                try {
                    app(TreatmentPlanService::class)->sign(
                        $record,
                        $data,
                        [
                            'signer_name' => $data['signer_name'],
                            'signer_document' => $data['signer_document'] ?? null,
                            'signer_relationship' => $data['signer_relationship'],
                        ],
                        (string) $data['signature'],
                        $user,
                    );
                } catch (ClinicalException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                $record->refresh();

                Notification::make()
                    ->success()
                    ->title($first ? 'Plan aceptado y firmado' : 'Lo agregado quedó aceptado')
                    ->body($first ? app(TreatmentPlanService::class)->paymentText($record) : 'Se cobra por sesión, aparte de la forma de pago del plan.')
                    ->send();
            });
    }

    private static function isFirst(TreatmentPlan $plan): bool
    {
        return $plan->accepted_at === null;
    }

    /** @return Collection<int, TreatmentPlanItem> Lo que se firma ahora. */
    private static function items(TreatmentPlan $plan): Collection
    {
        return self::isFirst($plan)
            ? $plan->items->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)->values()
            : $plan->itemsToAccept();
    }

    private static function total(TreatmentPlan $plan): float
    {
        return round(self::items($plan)->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2);
    }

    /** @param  Collection<int, TreatmentPlanItem>  $items */
    private static function itemsHtml(Collection $items): string
    {
        $rows = $items->map(fn (TreatmentPlanItem $item) => '<li>'.e($item->label()).($item->quantity > 1 ? ' × '.$item->quantity : '').': '.e(Money::format($item->subtotal())).'</li>')->implode('');

        return '<ul style="list-style: disc; padding-left: 1.25rem">'.$rows.'</ul>'
            .'<p style="margin-top: .5rem; font-size: 1.05rem"><strong>Total: '.e(Money::format($items->sum(fn (TreatmentPlanItem $item) => $item->subtotal()))).'</strong></p>';
    }

    private static function mode(Get $get): PlanPaymentMode
    {
        $state = $get('payment_mode');

        return $state instanceof PlanPaymentMode ? $state : (PlanPaymentMode::tryFrom((string) $state) ?? PlanPaymentMode::PerSession);
    }

    private static function preview(TreatmentPlan $plan, Get $get): string
    {
        $mode = self::mode($get);

        try {
            return TreatmentPlanService::scheduleText($mode, TreatmentPlanService::schedule(self::total($plan), $mode, [
                'down_payment' => $get('down_payment'),
                'installments' => $get('installments'),
                'first_due_on' => $get('first_due_on'),
                'upfront_discount' => $get('upfront_discount'),
            ], today()));
        } catch (ClinicalException $e) {
            return $e->getMessage();
        }
    }
}
