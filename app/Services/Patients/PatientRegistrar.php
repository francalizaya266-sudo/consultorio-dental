<?php

namespace App\Services\Patients;

use App\Enums\PatientRelationship;
use App\Models\Account;
use App\Models\Patient;
use App\Support\Phone;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Alta de pacientes desde el panel: el celular agrupa a la familia. */
class PatientRegistrar
{
    /**
     * @param  array<string, mixed>  $data  Datos del paciente más "phone" (opcional).
     */
    public function register(array $data): Patient
    {
        return DB::transaction(function () use ($data) {
            $account = filled($data['phone'] ?? null)
                ? Account::withTrashed()->firstOrCreate(['phone' => Phone::normalize($data['phone'])])
                : null;

            if ($account?->trashed()) {
                $account->restore();
            }

            $attributes = Arr::except($data, ['phone']);
            $attributes['account_id'] = $account?->getKey();
            $attributes['relationship'] ??= $account && $account->patients()->exists()
                ? PatientRelationship::Other
                : PatientRelationship::Self;

            return Patient::create($attributes);
        });
    }

    /** Pasa a un familiar a su propio celular (por ejemplo, al cumplir 18) sin perder su historia. */
    public function moveToPhone(Patient $patient, string $phone): Patient
    {
        $account = Account::firstOrCreate(['phone' => Phone::normalize($phone)]);

        $patient->update([
            'account_id' => $account->getKey(),
            'relationship' => $account->patients()->whereKeyNot($patient->getKey())->exists()
                ? $patient->relationship
                : PatientRelationship::Self,
        ]);

        return $patient;
    }
}
