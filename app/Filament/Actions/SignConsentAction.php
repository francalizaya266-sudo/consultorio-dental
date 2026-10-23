<?php

namespace App\Filament\Actions;

use App\Enums\PatientRelationship;
use App\Exceptions\ClinicalException;
use App\Filament\Forms\SignaturePad;
use App\Models\Consent;
use App\Models\ConsentTemplate;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Clinical\ConsentService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Consentimiento informado en la pantalla: el texto ya viene con los datos del paciente
 * y del tratamiento; el paciente (o su apoderado) lo lee y firma con el dedo.
 */
final class SignConsentAction
{
    /**
     * @param  Closure(): Patient  $patient
     * @param  Closure(): ?Treatment|null  $treatment  Tratamiento que se propone (el de la cita, por ejemplo).
     */
    public static function make(Closure $patient, ?Closure $treatment = null, string $name = 'signConsent'): Action
    {
        $consents = app(ConsentService::class);

        return Action::make($name)
            ->label('Firmar consentimiento')
            ->icon(Heroicon::OutlinedPencil)
            ->modalHeading('Consentimiento informado')
            ->modalDescription('Revisa los datos, entrega el celular o la tablet al paciente para que lea y firme.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Guardar consentimiento firmado')
            ->fillForm(function () use ($patient, $treatment, $consents) {
                $proposed = $treatment ? $treatment() : null;
                $signer = $consents->defaultSigner($patient());

                return [
                    'treatment_id' => $proposed?->getKey(),
                    'consent_template_id' => $consents->templateFor($proposed)?->getKey(),
                    ...$signer,
                    'signer_relationship' => $signer['signer_relationship']->value,
                ];
            })
            ->schema([
                Grid::make(2)->schema([
                    Select::make('treatment_id')
                        ->label('Tratamiento')
                        ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('consent_template_id', $consents->templateFor(Treatment::find($state))?->getKey())),
                    Select::make('consent_template_id')
                        ->label('Texto')
                        ->options(fn () => ConsentTemplate::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live(),
                ]),
                Grid::make(3)->schema([
                    Select::make('signer_relationship')
                        ->label('Firma')
                        ->options(collect(PatientRelationship::cases())->mapWithKeys(fn (PatientRelationship $r) => [
                            $r->value => $r === PatientRelationship::Self ? 'El mismo paciente' : $r->getLabel().' (apoderado)',
                        ]))
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set) use ($patient) {
                            if ($state === PatientRelationship::Self->value) {
                                $set('signer_name', $patient()->full_name);
                                $set('signer_document', $patient()->document_number);
                            }
                        }),
                    TextInput::make('signer_name')
                        ->label('Nombre de quien firma')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),
                    TextInput::make('signer_document')
                        ->label('Documento')
                        ->maxLength(20)
                        ->live(onBlur: true),
                ]),
                Section::make('Texto que se firma')
                    ->compact()
                    ->schema([
                        Text::make(function (Get $get) use ($patient, $consents) {
                            $template = ConsentTemplate::find($get('consent_template_id'));

                            if (! $template) {
                                return 'Elige el texto del consentimiento.';
                            }

                            $signer = ['signer_name' => $get('signer_name'), 'signer_document' => $get('signer_document'), 'signer_relationship' => $get('signer_relationship')];
                            $body = $consents->render($template->body, $patient(), $signer, auth()->user()->doctor, Treatment::find($get('treatment_id')), now());

                            return new HtmlString('<div style="white-space: pre-line; line-height: 1.55">'.e($body).'</div>');
                        }),
                    ]),
                SignaturePad::make('signature')
                    ->label('Firma')
                    ->required()
                    ->validationMessages(['required' => 'Falta la firma.']),
            ])
            ->action(function (array $data, Action $action) use ($patient, $consents) {
                try {
                    $consent = $consents->sign(
                        $patient(),
                        ConsentTemplate::findOrFail($data['consent_template_id']),
                        Treatment::find($data['treatment_id'] ?? null),
                        [
                            'signer_name' => $data['signer_name'],
                            'signer_document' => $data['signer_document'] ?? null,
                            'signer_relationship' => $data['signer_relationship'],
                        ],
                        (string) $data['signature'],
                        auth()->user(),
                    );
                } catch (ClinicalException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title('Consentimiento firmado')
                    ->actions([
                        Action::make('print')
                            ->label('Imprimir')
                            ->url(self::printUrl($consent))
                            ->openUrlInNewTab(),
                    ])
                    ->send();
            });
    }

    public static function printUrl(Consent $consent): string
    {
        return Filament::getPanel('admin')->route('print.consent', ['consent' => $consent]);
    }
}
