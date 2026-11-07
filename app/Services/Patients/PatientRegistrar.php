<?php

namespace App\Services\Patients;

use App\Enums\DocumentType;
use App\Enums\PatientRelationship;
use App\Models\Account;
use App\Models\Patient;
use App\Support\Phone;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

            if (array_key_exists('document_number', $attributes)) {
                $attributes['document_number'] = DocumentType::clean($attributes['document_number']);
            }
            $attributes['account_id'] = $account?->getKey();
            $attributes['relationship'] ??= $account && $account->patients()->exists()
                ? PatientRelationship::Other
                : PatientRelationship::Self;

            return Patient::create($attributes);
        });
    }

    /**
     * Pacientes ya registrados que podrían ser el mismo que se está por registrar: con el mismo documento, con
     * el mismo nombre y fecha de nacimiento, con el mismo nombre completo o con el mismo celular. Un paciente
     * repetido parte su historia en dos, así que antes de registrarlo se pregunta.
     *
     * @param  array<string, mixed>  $data  first_name, last_name, birth_date, document_number y phone (los que haya).
     * @return Collection<int, array{patient: Patient, reason: string}>
     */
    public function possibleDuplicates(array $data, ?Patient $ignore = null): Collection
    {
        $found = collect();
        $add = function (iterable $patients, string $reason) use ($found) {
            foreach ($patients as $patient) {
                if (! $found->has($patient->getKey())) {
                    $found->put($patient->getKey(), ['patient' => $patient, 'reason' => $reason]);
                }
            }
        };
        $patients = fn () => Patient::query()->with('account')->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))->limit(5);

        $document = DocumentType::clean($data['document_number'] ?? null);

        if ($document !== null && strlen($document) >= 6) {
            $add($patients()->where('document_number', $document)->get(), 'mismo documento');
        }

        $firstName = Str::squish((string) ($data['first_name'] ?? ''));
        $lastName = Str::squish((string) ($data['last_name'] ?? ''));

        if (mb_strlen($firstName) >= 2 && mb_strlen($lastName) >= 2) {
            // Con la fecha de nacimiento basta el primer nombre y el primer apellido ("Rosa Huamán" es "Rosa María Huamán Torres").
            if (filled($data['birth_date'] ?? null)) {
                $add($patients()
                    ->where('first_name', 'like', Str::before($firstName, ' ').'%')
                    ->where('last_name', 'like', Str::before($lastName, ' ').'%')
                    ->whereDate('birth_date', $data['birth_date'])
                    ->get(), 'mismo nombre y fecha de nacimiento');
            }

            $add($patients()->where('first_name', 'like', $firstName)->where('last_name', 'like', $lastName)->get(), 'mismo nombre');
        }

        if (filled($data['phone'] ?? null) && Phone::isValid($data['phone'])) {
            $add(Account::query()->where('phone', Phone::normalize($data['phone']))->first()?->patients()
                ->with('account')
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->orderBy('first_name')
                ->get() ?? [], 'mismo celular');
        }

        return $found->values();
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
