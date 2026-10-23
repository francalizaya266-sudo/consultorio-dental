<?php

namespace App\Services\Clinical;

use App\Enums\MessageTemplateKey;
use App\Models\ClinicalTemplate;
use App\Models\Consultation;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\TextSnippet;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receta de la atención: una por atención, con sus medicamentos. Se imprime o se envía
 * por WhatsApp con las indicaciones del doctor.
 */
final class PrescriptionService
{
    public function __construct(private readonly WhatsAppOutbox $outbox) {}

    /** @return list<array{medication: string, instructions: string|null}> */
    public function itemsOf(Consultation $consultation): array
    {
        return $consultation->prescription?->items()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PrescriptionItem $item) => ['medication' => $item->medication, 'instructions' => $this->instructionsOf($item)])
            ->all() ?? [];
    }

    /**
     * Guarda los medicamentos de la atención. Los renglones se actualizan en su lugar para que
     * la auditoría muestre solo lo que cambió; sin medicamentos, la receta se anula.
     *
     * @param  array<array-key, array{medication?: string|null, instructions?: string|null}>  $items
     */
    public function syncForConsultation(Consultation $consultation, array $items): ?Prescription
    {
        $items = $this->clean($items);
        $prescription = $consultation->prescription;

        if ($items->isEmpty()) {
            if ($prescription) {
                DB::transaction(function () use ($prescription) {
                    $prescription->items()->get()->each->delete();
                    $prescription->delete();
                });
                $consultation->unsetRelation('prescription');
            }

            return null;
        }

        return DB::transaction(function () use ($consultation, $prescription, $items) {
            $prescription ??= $consultation->prescriptions()->create([
                'patient_id' => $consultation->patient_id,
                'doctor_id' => $consultation->doctor_id,
                'issued_at' => now(),
            ]);
            $prescription->update(['indications' => $consultation->indications]);

            $existing = $prescription->items()->orderBy('sort_order')->orderBy('id')->get()->values();

            foreach ($items->values() as $i => $item) {
                $row = ['medication' => $item['medication'], 'instructions' => $item['instructions'], 'sort_order' => $i];
                $current = $existing->get($i);
                $current ? $current->update($row) : $prescription->items()->create($row);
            }

            $existing->slice($items->count())->each->delete();

            $consultation->setRelation('prescription', $prescription);

            return $prescription;
        });
    }

    /**
     * Medicamentos de la plantilla del tratamiento, listos para el formulario.
     *
     * @return list<array{medication: string, instructions: string|null}>
     */
    public function fromTemplate(?ClinicalTemplate $template): array
    {
        return collect($template?->content['prescription_items'] ?? [])
            ->filter(fn ($item) => filled($item['medication'] ?? null))
            ->map(fn (array $item) => [
                'medication' => trim($item['medication']),
                'instructions' => $this->compose($item['dose'] ?? null, $item['frequency'] ?? null, $item['duration'] ?? null, $item['instructions'] ?? null),
            ])
            ->values()
            ->all();
    }

    /** "Ibuprofeno 400 mg: 1 tableta cada 8 horas…" → medicamento e indicación. */
    public function fromSnippet(TextSnippet $snippet): array
    {
        [$medication, $instructions] = array_pad(explode(':', $snippet->body, 2), 2, null);

        return ['medication' => trim($medication), 'instructions' => filled($instructions) ? trim($instructions) : null];
    }

    /**
     * Suma medicamentos sin repetir los que ya están (por nombre).
     *
     * @param  array<array-key, array<string, mixed>>  $current
     * @param  list<array{medication: string, instructions: string|null}>  $new
     * @return array<array-key, array<string, mixed>>
     */
    public static function merge(array $current, array $new): array
    {
        $names = collect($current)->map(fn ($item) => mb_strtolower(trim((string) ($item['medication'] ?? ''))))->filter();

        foreach ($new as $item) {
            if ($names->doesntContain(mb_strtolower($item['medication']))) {
                $current[(string) str()->uuid()] = $item;
                $names->push(mb_strtolower($item['medication']));
            }
        }

        return $current;
    }

    /** Texto de la receta para WhatsApp. */
    public function text(Prescription $prescription): string
    {
        $lines = $prescription->items()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PrescriptionItem $item) => '• '.$item->medication.(($instructions = $this->instructionsOf($item)) ? ': '.$instructions : ''));

        if (filled($prescription->indications)) {
            $lines->push('', 'Indicaciones:', trim($prescription->indications));
        }

        return $lines->implode("\n");
    }

    /** Mensaje para el celular del paciente (o del titular, si es un familiar a cargo). Null si no hay celular. */
    public function whatsappDraft(Prescription $prescription): ?WhatsappMessage
    {
        $prescription->loadMissing('patient.account', 'doctor');
        $account = $prescription->patient->account;

        if (! $account) {
            return null;
        }

        return $this->outbox->draftForAccount($account, MessageTemplateKey::Prescription, [
            'nombre' => $prescription->patient->first_name,
            'paciente' => $prescription->patient->full_name,
            'doctor' => $prescription->doctor->name,
            'receta' => $this->text($prescription),
        ], $prescription->patient_id);
    }

    /** Indicación completa del renglón (las recetas antiguas pueden tener dosis y frecuencia por separado). */
    public function instructionsOf(PrescriptionItem $item): ?string
    {
        return $this->compose($item->dose, $item->frequency, $item->duration, $item->instructions);
    }

    /** @return Collection<array-key, array{medication: string, instructions: string|null}> */
    private function clean(array $items): Collection
    {
        return collect($items)
            ->map(fn ($item) => [
                'medication' => trim((string) ($item['medication'] ?? '')),
                'instructions' => trim((string) ($item['instructions'] ?? '')) ?: null,
            ])
            ->filter(fn (array $item) => $item['medication'] !== '');
    }

    private function compose(?string $dose, ?string $frequency, ?string $duration, ?string $instructions): ?string
    {
        $text = collect([$dose, $frequency, filled($duration) ? 'por '.$duration : null])->filter()->implode(' ');

        return collect([$text, $instructions])->filter()->implode(', ') ?: null;
    }
}
