<?php

namespace App\Models;

use App\Enums\AppointmentOrigin;
use App\Enums\Dentition;
use App\Enums\DocumentType;
use App\Enums\PatientRelationship;
use App\Enums\Sex;
use App\Enums\TreatmentPlanStatus;
use App\Models\Concerns\Auditable;
use App\Support\Phone;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** Paciente. Cada familiar tiene su propia historia; comparten el celular de la cuenta. */
#[Fillable(['account_id', 'relationship', 'first_name', 'last_name', 'document_type', 'document_number', 'birth_date', 'sex', 'email', 'address', 'district', 'occupation', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship', 'origin', 'referral_code', 'referred_by_patient_id', 'admin_notes', 'first_visit_at', 'last_visit_at'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'relationship' => PatientRelationship::class,
            'document_type' => DocumentType::class,
            'sex' => Sex::class,
            'origin' => AppointmentOrigin::class,
            'birth_date' => 'date',
            'first_visit_at' => 'datetime',
            'last_visit_at' => 'datetime',
            'balance_due' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'referred_by_patient_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function medicalBackground(): HasOne
    {
        return $this->hasOne(MedicalBackground::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function odontograms(): HasMany
    {
        return $this->hasMany(Odontogram::class);
    }

    public function treatmentPlans(): HasMany
    {
        return $this->hasMany(TreatmentPlan::class);
    }

    public function clinicalFiles(): HasMany
    {
        return $this->hasMany(ClinicalFile::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birth_date?->age);
    }

    /** Odontograma de niño o de adulto según la edad. */
    public function suggestedDentition(): Dentition
    {
        $age = $this->age;

        return match (true) {
            $age === null, $age >= 13 => Dentition::Permanent,
            $age < 6 => Dentition::Primary,
            default => Dentition::Mixed,
        };
    }

    /** Pacientes con un plan en curso no reservan sus sesiones solos en la web. */
    public function hasActiveTreatment(): bool
    {
        return $this->treatmentPlans()
            ->whereIn('status', [TreatmentPlanStatus::Accepted, TreatmentPlanStatus::InProgress])
            ->exists();
    }

    /** Saldo pendiente en soles: cargos menos pagos (sin contar anulados). Negativo = a favor del paciente. */
    public function balance(): float
    {
        $charged = (float) $this->charges()->valid()->sum(DB::raw('amount - discount'));
        $paid = (float) $this->payments()->whereNull('voided_at')->sum('amount');

        return round($charged - $paid, 2);
    }

    /** El saldo calculado en la misma consulta (columna balance_due), para listas y orden. */
    #[Scope]
    protected function withBalance(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        return $query->selectRaw(self::balanceSql().' as balance_due');
    }

    /** Pacientes que deben algo. */
    #[Scope]
    protected function owing(Builder $query): Builder
    {
        return $query->whereRaw(self::balanceSql().' > 0');
    }

    private static function balanceSql(): string
    {
        return '((select coalesce(sum(c.amount - c.discount), 0) from charges c where c.patient_id = patients.id and c.voided_at is null)'
            .' - (select coalesce(sum(p.amount), 0) from payments p where p.patient_id = patients.id and p.voided_at is null))';
    }

    public function latestOdontogram(): HasOne
    {
        return $this->hasOne(Odontogram::class)->latestOfMany();
    }

    /** Busca por nombre, apellido, documento o celular. */
    #[Scope]
    protected function search(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $digits = preg_replace('/\D+/', '', $term);

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where(function (Builder $name) use ($term) {
                foreach (preg_split('/\s+/', $term) as $word) {
                    $name->where(fn (Builder $w) => $w->where('first_name', 'like', "%{$word}%")->orWhere('last_name', 'like', "%{$word}%"));
                }
            });

            if (strlen($digits) >= 4) {
                $q->orWhere('document_number', 'like', "{$digits}%")
                    ->orWhereHas('account', fn (Builder $a) => $a->where('phone', 'like', "%{$digits}%"));
            }
        });
    }

    /** "María Quispe · DNI 12345678 · 987 654 321" para buscadores. */
    public function selectLabel(): string
    {
        return collect([
            $this->full_name,
            $this->document_number ? ($this->document_type?->getLabel() ?? 'Doc.').' '.$this->document_number : null,
            $this->account ? Phone::display($this->account->phone) : null,
        ])->filter()->implode(' · ');
    }
}
