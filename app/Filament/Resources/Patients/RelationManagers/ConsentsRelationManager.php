<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\PatientRelationship;
use App\Filament\Actions\SignConsentAction;
use App\Models\Consent;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Clinical\ConsentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Consentimientos informados firmados por el paciente o su apoderado. */
class ConsentsRelationManager extends RelationManager
{
    protected static string $relationship = 'consents';

    protected static ?string $title = 'Consentimientos';

    protected static ?string $modelLabel = 'consentimiento';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('viewClinical', $ownerRecord);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->consents()->whereNull('revoked_at')->count();

        return $count ? (string) $count : null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Patient $patient */
        $patient = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['doctor', 'patient']))
            ->defaultSort('signed_at', 'desc')
            ->columns([
                TextColumn::make('signed_at')
                    ->label('Firmado')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('title')
                    ->label('Consentimiento')
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('signer_name')
                    ->label('Firmó')
                    ->description(fn (Consent $record) => $record->signer_relationship === PatientRelationship::Self ? 'El paciente' : $record->signer_relationship->getLabel()),
                TextColumn::make('doctor.name')
                    ->label('Doctor')
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->state(fn (Consent $record) => $record->isActive() ? 'Vigente' : 'Revocado el '.$record->revoked_at->format('d/m/Y'))
                    ->badge()
                    ->color(fn (Consent $record) => $record->isActive() ? 'success' : 'danger'),
            ])
            ->recordUrl(fn (Consent $record) => SignConsentAction::printUrl($record), shouldOpenInNewTab: true)
            ->headerActions([
                SignConsentAction::make(fn () => $patient, fn () => $this->proposedTreatment($patient))
                    ->label('Nuevo consentimiento')
                    ->visible(fn () => auth()->user()->can('updateClinical', $patient)),
            ])
            ->recordActions([
                Action::make('print')
                    ->label('Ver / imprimir')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (Consent $record) => SignConsentAction::printUrl($record))
                    ->openUrlInNewTab(),
                Action::make('revoke')
                    ->label('Revocar')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize('revoke')
                    ->requiresConfirmation()
                    ->modalHeading('¿El paciente retira este consentimiento?')
                    ->modalDescription('Queda revocado con la fecha de hoy. No se borra.')
                    ->modalSubmitActionLabel('Revocar')
                    ->action(function (Consent $record) {
                        app(ConsentService::class)->revoke($record);
                        Notification::make()->success()->title('Consentimiento revocado')->send();
                    }),
            ])
            ->emptyStateHeading('Sin consentimientos firmados')
            ->emptyStateDescription('Toca "Nuevo consentimiento": el texto ya viene con los datos del paciente y se firma con el dedo.');
    }

    /** El tratamiento de su próxima cita con este doctor (o de la más reciente). */
    private function proposedTreatment(Patient $patient): ?Treatment
    {
        $appointments = $patient->appointments()->with('treatment')->where('doctor_id', auth()->user()->doctorId());

        return ((clone $appointments)->upcoming()->oldest('starts_at')->first() ?? $appointments->latest('starts_at')->first())?->treatment;
    }
}
