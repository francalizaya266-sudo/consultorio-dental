<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\Sex;
use App\Filament\Actions\EditMedicalBackgroundAction;
use App\Filament\Support\ClinicalAlerts;
use App\Livewire\OdontogramChart;
use App\Models\MedicalBackground;
use App\Models\Patient;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ClinicalRecordInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Group::make(fn (Patient $record) => array_filter([ClinicalAlerts::callout($record, withLink: false)])),

                Section::make('Antecedentes médicos')
                    ->key('background')
                    ->icon(Heroicon::OutlinedHeart)
                    ->description(fn (Patient $record) => $record->medicalBackground
                        ? 'Actualizado el '.$record->medicalBackground->updated_at->format('d/m/Y').($record->medicalBackground->updatedBy ? ' por '.$record->medicalBackground->updatedBy->name : '')
                        : 'Todavía no se registraron.')
                    ->headerActions([EditMedicalBackgroundAction::make(fn (Patient $record) => $record)])
                    ->columns(3)
                    ->collapsible()
                    ->collapsed(fn (Patient $record) => $record->medicalBackground !== null)
                    ->schema([
                        TextEntry::make('medicalBackground.conditions')
                            ->label('Enfermedades')
                            ->state(fn (Patient $record) => collect($record->medicalBackground?->conditions ?? [])->map(fn (string $key) => MedicalBackground::CONDITIONS[$key] ?? $key)->all())
                            ->badge()
                            ->color('danger')
                            ->placeholder('Ninguna registrada')
                            ->columnSpanFull(),
                        TextEntry::make('medicalBackground.allergies')->label('Alergias')->placeholder('—'),
                        TextEntry::make('medicalBackground.medications')->label('Medicamentos que toma')->placeholder('—'),
                        TextEntry::make('medicalBackground.surgeries')->label('Operaciones')->placeholder('—'),
                        TextEntry::make('medicalBackground.smoker')
                            ->label('Fuma')
                            ->formatStateUsing(fn (?bool $state) => $state ? 'Sí' : 'No')
                            ->placeholder('No registrado'),
                        TextEntry::make('pregnancy')
                            ->label('Embarazo / lactancia')
                            ->state(fn (Patient $record) => collect([
                                $record->medicalBackground?->is_pregnant ? 'Embarazada'.($record->medicalBackground->pregnancy_weeks ? " ({$record->medicalBackground->pregnancy_weeks} sem.)" : '') : null,
                                $record->medicalBackground?->is_breastfeeding ? 'Dando de lactar' : null,
                            ])->filter()->implode(' · ') ?: null)
                            ->placeholder('No')
                            ->visible(fn (Patient $record) => $record->sex !== Sex::Male),
                        TextEntry::make('medicalBackground.notes')->label('Otras notas')->placeholder('—'),
                    ]),

                Section::make('Implantes colocados')
                    ->icon(Heroicon::OutlinedQrCode)
                    ->description('Con su serie y lote, por si el fabricante pide revisar alguno.')
                    ->collapsible()
                    ->visible(fn (Patient $record) => app(InventoryService::class)->implantsOf($record)->isNotEmpty())
                    ->schema([
                        TextEntry::make('implants')
                            ->hiddenLabel()
                            ->state(fn (Patient $record) => app(InventoryService::class)->implantsOf($record)
                                ->map(fn (StockMovement $use) => collect([
                                    ($use->consultation?->attended_at ?? $use->appointment?->starts_at ?? $use->created_at)->format('d/m/Y'),
                                    $use->supply->name,
                                    $use->batch?->label() ?? 'serie sin registrar',
                                    $use->consultation?->doctor?->name ?? $use->appointment?->doctor?->name,
                                ])->filter()->implode(' · '))
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted(),
                    ]),

                Section::make('Odontograma')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->description('El más reciente. Elige una fecha para ver cómo estaba antes.')
                    ->collapsible()
                    ->schema([
                        Livewire::make(OdontogramChart::class)->key('odontogram'),
                    ]),
            ]);
    }
}
