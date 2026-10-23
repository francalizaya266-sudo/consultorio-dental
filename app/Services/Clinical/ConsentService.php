<?php

namespace App\Services\Clinical;

use App\Enums\PatientRelationship;
use App\Models\Consent;
use App\Models\ConsentTemplate;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Signatures;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Consentimiento informado: el texto de la plantilla con los datos del paciente, firmado con
 * el dedo en la pantalla. Se guarda el texto tal como se firmó; si se retira, queda revocado.
 */
final class ConsentService
{
    /** Datos que se pueden usar en el texto de la plantilla. */
    public const PLACEHOLDERS = [
        '{paciente}' => 'Nombre del paciente',
        '{firmante}' => 'Quien firma (el paciente o su apoderado)',
        '{documento}' => 'Documento de quien firma',
        '{calidad}' => '"en mi calidad de paciente" o "de padre / madre del paciente"',
        '{doctor}' => 'Doctor que realiza el tratamiento',
        '{tratamiento}' => 'Tratamiento',
        '{fecha}' => 'Fecha de la firma',
        '{ciudad}' => 'Distrito o ciudad del consultorio (Ajustes)',
    ];

    /** La plantilla del tratamiento o, si no tiene, la general. */
    public function templateFor(?Treatment $treatment): ?ConsentTemplate
    {
        return ConsentTemplate::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('treatment_id')->when($treatment, fn ($q) => $q->orWhere('treatment_id', $treatment->getKey())))
            ->orderByRaw('treatment_id is null')
            ->latest('version')
            ->first();
    }

    /**
     * Quién firma por defecto: el paciente, o el titular del celular si es un menor a su cargo.
     *
     * @return array{signer_name: string, signer_document: string|null, signer_relationship: PatientRelationship}
     */
    public function defaultSigner(Patient $patient): array
    {
        $holder = $patient->relationship !== PatientRelationship::Self && $patient->age !== null && $patient->age < 18
            ? Patient::query()->where('account_id', $patient->account_id)->where('relationship', PatientRelationship::Self)->first()
            : null;

        $signer = $holder ?? $patient;

        return [
            'signer_name' => $signer->full_name,
            'signer_document' => $signer->document_number,
            'signer_relationship' => $holder ? self::inverse($patient->relationship) : PatientRelationship::Self,
        ];
    }

    /** Texto final con los datos de esta firma. */
    public function render(string $body, Patient $patient, array $signer, ?Doctor $doctor, ?Treatment $treatment, CarbonInterface $date): string
    {
        $relationship = $signer['signer_relationship'] instanceof PatientRelationship
            ? $signer['signer_relationship']
            : (PatientRelationship::tryFrom((string) ($signer['signer_relationship'] ?? '')) ?? PatientRelationship::Self);

        return strtr($body, [
            '{paciente}' => $patient->full_name,
            '{firmante}' => filled($signer['signer_name'] ?? null) ? $signer['signer_name'] : '________________',
            '{documento}' => filled($signer['signer_document'] ?? null) ? 'documento N.º '.$signer['signer_document'] : 'documento N.º ________',
            '{calidad}' => $relationship === PatientRelationship::Self
                ? 'en mi calidad de paciente'
                : 'en mi calidad de '.mb_strtolower($relationship->getLabel()).' del paciente',
            '{doctor}' => $doctor?->name ?? 'el doctor tratante',
            '{tratamiento}' => $treatment ? mb_strtolower($treatment->name) : 'el tratamiento indicado',
            '{fecha}' => $date->translatedFormat('j \d\e F \d\e Y'),
            '{ciudad}' => (string) (Setting::get('clinic.district') ?: '________'),
        ]);
    }

    /**
     * Guarda el consentimiento firmado.
     *
     * @param  array{signer_name: string, signer_document?: string|null, signer_relationship: PatientRelationship|string}  $signer
     */
    public function sign(Patient $patient, ConsentTemplate $template, ?Treatment $treatment, array $signer, string $signature, User $by): Consent
    {
        $png = Signatures::png($signature);
        $doctor = $by->doctor;
        $now = now();

        return DB::transaction(function () use ($patient, $template, $treatment, $signer, $png, $doctor, $now) {
            $path = 'clinica/'.$patient->getKey().'/firmas/'.Str::uuid().'.png';
            Storage::disk(ClinicalFileService::DISK)->put($path, $png);

            return Consent::create([
                'patient_id' => $patient->getKey(),
                'consent_template_id' => $template->getKey(),
                'treatment_id' => $treatment?->getKey(),
                'doctor_id' => $doctor?->getKey(),
                'title' => $template->name.($treatment ? ' · '.$treatment->name : ''),
                'body_snapshot' => $this->render($template->body, $patient, $signer, $doctor, $treatment, $now),
                'signer_name' => $signer['signer_name'],
                'signer_document' => $signer['signer_document'] ?? null,
                'signer_relationship' => $signer['signer_relationship'],
                'signature_path' => $path,
                'signed_at' => $now,
            ]);
        });
    }

    /** El paciente retiró su consentimiento: no se borra, queda revocado con la fecha. */
    public function revoke(Consent $consent): void
    {
        if ($consent->revoked_at === null) {
            $consent->update(['revoked_at' => now()]);
        }
    }

    /** Firma lista para mostrarla en la hoja impresa. */
    public function signatureDataUri(Consent $consent): ?string
    {
        return Signatures::dataUri($consent->signature_path);
    }

    /** Si el paciente es "hijo" del titular, el titular es su "padre / madre", y así. */
    private static function inverse(PatientRelationship $relationship): PatientRelationship
    {
        return match ($relationship) {
            PatientRelationship::Child => PatientRelationship::Parent,
            PatientRelationship::Parent => PatientRelationship::Child,
            PatientRelationship::Grandchild => PatientRelationship::Grandparent,
            PatientRelationship::Grandparent => PatientRelationship::Grandchild,
            default => $relationship,
        };
    }
}
