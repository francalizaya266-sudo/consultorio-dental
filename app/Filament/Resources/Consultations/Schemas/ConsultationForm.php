<?php

namespace App\Filament\Resources\Consultations\Schemas;

use App\Enums\DiagnosisType;
use App\Enums\SnippetField;
use App\Enums\ToothState;
use App\Filament\Forms\QuickTextarea;
use App\Filament\Support\ClinicalAlerts;
use App\Livewire\OdontogramChart;
use App\Models\Consultation;
use App\Models\Icd10Code;
use App\Models\OdontogramEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Pantalla de la atención: todo lo que el doctor escribe mientras atiende, de arriba hacia abajo. */
class ConsultationForm
{
    /** Hallazgo en rojo del odontograma → diagnóstico CIE-10 que se sugiere. */
    public const DIAGNOSIS_FOR = [
        'caries' => 'K02.1',
        'remanente_radicular' => 'K08.3',
        'fractura' => 'S02.5',
        'impactacion' => 'K01.1',
        'superficie_desgastada' => 'K03.0',
        'defecto_esmalte' => 'K00.4',
        'posicion' => 'K07.3',
        'giroversion' => 'K07.3',
        'migracion' => 'K07.3',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Group::make(fn (?Consultation $record) => array_filter([ClinicalAlerts::callout($record?->patient)])),

                Section::make('Motivo y examen')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->columns(2)
                    ->schema([
                        QuickTextarea::make('chief_complaint')
                            ->label('Motivo de consulta')
                            ->snippets(SnippetField::ChiefComplaint),
                        QuickTextarea::make('current_illness')
                            ->label('Enfermedad actual')
                            ->snippets(SnippetField::Any),
                        QuickTextarea::make('clinical_exam')
                            ->label('Examen clínico')
                            ->snippets(SnippetField::ClinicalExam)
                            ->columnSpanFull(),
                        Grid::make(3)
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('vital_signs.blood_pressure')
                                    ->label('Presión arterial')
                                    ->placeholder('120/80')
                                    ->maxLength(10),
                                TextInput::make('vital_signs.heart_rate')
                                    ->label('Pulso')
                                    ->suffix('lpm')
                                    ->numeric()
                                    ->minValue(20)
                                    ->maxValue(250),
                                TextInput::make('vital_signs.temperature')
                                    ->label('Temperatura')
                                    ->suffix('°C')
                                    ->numeric()
                                    ->minValue(30)
                                    ->maxValue(45),
                            ]),
                    ]),

                Section::make('Odontograma')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->description('Cada toque se guarda solo. Parte del odontograma de la atención anterior.')
                    ->schema([
                        Livewire::make(OdontogramChart::class, ['editable' => true])
                            ->key('odontogram'),
                    ]),

                Section::make('Diagnóstico')
                    ->key('diagnosis')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->headerActions([self::suggestDiagnosesAction()])
                    ->schema([
                        Repeater::make('diagnoses')
                            ->hiddenLabel()
                            ->relationship()
                            ->addActionLabel('Agregar diagnóstico')
                            ->defaultItems(0)
                            ->columns(6)
                            ->itemLabel(fn (array $state) => filled($state['tooth'] ?? null) ? 'Pieza '.$state['tooth'] : null)
                            ->schema([
                                Select::make('icd10_code_id')
                                    ->label('CIE-10')
                                    ->options(fn () => Icd10Code::query()->orderBy('code')->get()->mapWithKeys(fn (Icd10Code $code) => [$code->getKey() => "{$code->code} · {$code->description}"]))
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(3),
                                TextInput::make('tooth')
                                    ->label('Pieza')
                                    ->placeholder('36')
                                    ->maxLength(10)
                                    ->columnSpan(1),
                                ToggleButtons::make('type')
                                    ->label('Tipo')
                                    ->options(DiagnosisType::class)
                                    ->default(DiagnosisType::Definitive)
                                    ->inline()
                                    ->grouped()
                                    ->columnSpan(2),
                            ]),
                        QuickTextarea::make('diagnosis_notes')
                            ->label('Notas del diagnóstico')
                            ->snippets(SnippetField::Diagnosis),
                    ]),

                Section::make('Evolución')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->description('Qué se hizo hoy.')
                    ->schema([
                        QuickTextarea::make('procedures')
                            ->hiddenLabel()
                            ->label('Evolución')
                            ->snippets(SnippetField::Procedures),
                    ]),

                Section::make('Indicaciones y próximos pasos')
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->columns(2)
                    ->schema([
                        QuickTextarea::make('indications')
                            ->label('Indicaciones para el paciente')
                            ->snippets(SnippetField::Indications),
                        QuickTextarea::make('next_steps')
                            ->label('Próximos pasos')
                            ->placeholder('Ej.: resina en 36 la próxima sesión')
                            ->snippets(SnippetField::Any),
                    ]),
            ]);
    }

    /** Agrega a la lista los hallazgos en rojo del odontograma de hoy que todavía no tienen diagnóstico. */
    private static function suggestDiagnosesAction(): Action
    {
        return Action::make('suggestDiagnoses')
            ->label('Sugerir desde el odontograma')
            ->icon(Heroicon::OutlinedSparkles)
            ->link()
            ->action(function (?Consultation $record, Get $get, Set $set) {
                $codes = Icd10Code::query()->whereIn('code', array_unique(self::DIAGNOSIS_FOR))->pluck('id', 'code');
                $current = collect($get('diagnoses') ?? []);
                $added = 0;

                $entries = $record?->odontogram?->entries()->with('condition')->where('state', ToothState::Bad)->orderBy('tooth')->get() ?? collect();

                foreach ($entries as $entry) {
                    /** @var OdontogramEntry $entry */
                    $codeId = $codes[self::DIAGNOSIS_FOR[$entry->condition->code] ?? ''] ?? null;
                    $exists = $current->contains(fn (array $row) => (int) ($row['icd10_code_id'] ?? 0) === $codeId && (string) ($row['tooth'] ?? '') === (string) $entry->tooth);

                    if (! $codeId || $exists) {
                        continue;
                    }

                    $current->put('suggested-'.$entry->getKey(), ['icd10_code_id' => $codeId, 'tooth' => (string) $entry->tooth, 'type' => DiagnosisType::Definitive->value]);
                    $added++;
                }

                $set('diagnoses', $current->all());

                Notification::make()
                    ->title($added ? "Se agregaron {$added} diagnósticos. Revísalos y guarda." : 'No hay hallazgos nuevos en rojo para diagnosticar.')
                    ->status($added ? 'success' : 'info')
                    ->send();
            });
    }
}
