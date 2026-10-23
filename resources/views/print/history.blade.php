@use('App\Enums\Sex')
@use('App\Enums\ToothState')
@use('App\Models\MedicalBackground')
@use('App\Support\Money')
@use('App\Support\Teeth')
@use('App\Services\Clinical\ClinicalFileService')
<x-print-layout title="Historia clínica" :doctor="$doctor" :patient="$patient">
    <style>
        h2 { font-size: .95rem; margin: 22px 0 8px; padding-bottom: 4px; border-bottom: 1px solid var(--line); color: var(--brand); text-transform: uppercase; letter-spacing: .03em; break-after: avoid; }
        h3 { font-size: .9rem; margin: 0 0 6px; }
        dl { display: grid; grid-template-columns: 170px 1fr; gap: 3px 12px; margin: 0; }
        dt { color: var(--muted); font-size: .8rem; }
        dd { margin: 0; white-space: pre-line; }
        .visit { border: 1px solid var(--line); border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; break-inside: avoid; }
        .visit .when { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 6px; }
        .odontogram { border: 1px solid var(--line); border-radius: 6px; padding: 6px; overflow: hidden; }
        .odontogram svg { max-width: 100%; height: auto; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
        .empty { color: var(--muted); font-style: italic; }
        ul { margin: 0; padding-left: 18px; }
        .printed { margin-top: 24px; font-size: .75rem; color: var(--muted); }
    </style>

    @php
        $background = $patient->medicalBackground;
    @endphp

    <h2>Datos del paciente</h2>
    <dl>
        <dt>Nacimiento</dt><dd>{{ $patient->birth_date?->format('d/m/Y') ?? '—' }}</dd>
        <dt>Sexo</dt><dd>{{ $patient->sex?->getLabel() ?? '—' }}</dd>
        <dt>Celular</dt><dd>{{ $patient->account?->phone ?? '—' }}{{ $patient->relationship && $patient->relationship->value !== 'self' ? ' (del titular, '.mb_strtolower($patient->relationship->getLabel()).')' : '' }}</dd>
        <dt>Dirección</dt><dd>{{ collect([$patient->address, $patient->district])->filter()->implode(', ') ?: '—' }}</dd>
        <dt>Ocupación</dt><dd>{{ $patient->occupation ?? '—' }}</dd>
        <dt>Contacto de emergencia</dt><dd>{{ collect([$patient->emergency_contact_name, $patient->emergency_contact_relationship, $patient->emergency_contact_phone])->filter()->implode(' · ') ?: '—' }}</dd>
    </dl>

    <h2>Antecedentes médicos</h2>
    @if ($background)
        <dl>
            <dt>Enfermedades</dt><dd>{{ collect($background->conditions ?? [])->map(fn ($key) => MedicalBackground::CONDITIONS[$key] ?? $key)->implode(', ') ?: 'Ninguna registrada' }}</dd>
            <dt>Alergias</dt><dd>{{ $background->allergies ?: '—' }}</dd>
            <dt>Medicamentos</dt><dd>{{ $background->medications ?: '—' }}</dd>
            <dt>Operaciones</dt><dd>{{ $background->surgeries ?: '—' }}</dd>
            <dt>Fuma</dt><dd>{{ $background->smoker === null ? '—' : ($background->smoker ? 'Sí' : 'No') }}</dd>
            @if ($patient->sex !== Sex::Male)
                <dt>Embarazo / lactancia</dt><dd>{{ collect([$background->is_pregnant ? 'Embarazada'.($background->pregnancy_weeks ? " ({$background->pregnancy_weeks} sem.)" : '') : null, $background->is_breastfeeding ? 'Dando de lactar' : null])->filter()->implode(' · ') ?: 'No' }}</dd>
            @endif
            <dt>Otras notas</dt><dd>{{ $background->notes ?: '—' }}</dd>
            <dt>Actualizado</dt><dd>{{ $background->updated_at->format('d/m/Y') }}</dd>
        </dl>
    @else
        <p class="empty">Todavía no se registraron.</p>
    @endif

    <h2>Odontograma actual</h2>
    @if ($odontogram)
        <p class="muted" style="margin: 0 0 6px">{{ $odontogram->type->getLabel() }} · {{ $odontogram->created_at->format('d/m/Y') }} · rojo: por tratar, azul: tratado o en buen estado</p>
        <div class="odontogram">
            <x-odontogram-drawing :odontogram="$odontogram" :rows="$rows" :teeth="$teeth" :spans="$spans" />
        </div>
        @if ($findings->isNotEmpty())
            <table style="margin-top: 8px">
                <thead><tr><th>Pieza</th><th>Hallazgo</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach ($findings as $entry)
                        <tr>
                            <td>{{ $entry->tooth }}{{ $entry->to_tooth ? ' a '.$entry->to_tooth : '' }}{{ $entry->surface ? ' · '.Teeth::surfaceName($entry->surface) : '' }}</td>
                            <td>{{ $entry->condition->name }}{{ $entry->abbreviation ? " ({$entry->abbreviation})" : '' }}</td>
                            <td><span class="dot" style="background: {{ $entry->state === ToothState::Good ? '#2563eb' : '#dc2626' }}"></span>{{ $entry->state === ToothState::Good ? 'Tratado' : 'Por tratar' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @if (filled($odontogram->specifications) || filled($odontogram->observations))
            <dl style="margin-top: 8px">
                @if (filled($odontogram->specifications))<dt>Especificaciones</dt><dd>{{ $odontogram->specifications }}</dd>@endif
                @if (filled($odontogram->observations))<dt>Observaciones</dt><dd>{{ $odontogram->observations }}</dd>@endif
            </dl>
        @endif
    @else
        <p class="empty">Sin odontograma.</p>
    @endif

    <h2>Atenciones ({{ $patient->consultations->count() }})</h2>
    @forelse ($patient->consultations as $consultation)
        <div class="visit">
            <div class="when">
                <h3>{{ $consultation->attended_at->translatedFormat('l d/m/Y · H:i') }}</h3>
                <span class="muted">{{ $consultation->doctor->name }}{{ $consultation->closed_at ? '' : ' · sin terminar' }}</span>
            </div>
            <dl>
                @if ($consultation->appointment?->treatment)<dt>Cita</dt><dd>{{ $consultation->appointment->treatment->name }}</dd>@endif
                @if (filled($consultation->chief_complaint))<dt>Motivo</dt><dd>{{ $consultation->chief_complaint }}</dd>@endif
                @if (filled($consultation->current_illness))<dt>Enfermedad actual</dt><dd>{{ $consultation->current_illness }}</dd>@endif
                @if (filled($consultation->clinical_exam))<dt>Examen clínico</dt><dd>{{ $consultation->clinical_exam }}</dd>@endif
                @php
                    $vitals = collect([
                        filled($consultation->vital_signs['blood_pressure'] ?? null) ? 'PA '.$consultation->vital_signs['blood_pressure'] : null,
                        filled($consultation->vital_signs['heart_rate'] ?? null) ? 'Pulso '.$consultation->vital_signs['heart_rate'].' lpm' : null,
                        filled($consultation->vital_signs['temperature'] ?? null) ? $consultation->vital_signs['temperature'].' °C' : null,
                    ])->filter()->implode(' · ');
                @endphp
                @if ($vitals)<dt>Signos vitales</dt><dd>{{ $vitals }}</dd>@endif
                @if ($consultation->diagnoses->isNotEmpty())
                    <dt>Diagnóstico</dt>
                    <dd>{{ $consultation->diagnoses->map(fn ($d) => collect([$d->icd10Code ? "{$d->icd10Code->code} {$d->icd10Code->description}" : $d->description, $d->tooth ? "pieza {$d->tooth}" : null, $d->type?->getLabel()])->filter()->implode(' · '))->implode("\n") }}</dd>
                @endif
                @if (filled($consultation->diagnosis_notes))<dt>Notas del diagnóstico</dt><dd>{{ $consultation->diagnosis_notes }}</dd>@endif
                @if ($consultation->planItems->isNotEmpty())<dt>Del plan se trabajó</dt><dd>{{ $consultation->planItems->map->label()->implode("\n") }}</dd>@endif
                @if (filled($consultation->procedures))<dt>Evolución</dt><dd>{{ $consultation->procedures }}</dd>@endif
                @if (filled($consultation->indications))<dt>Indicaciones</dt><dd>{{ $consultation->indications }}</dd>@endif
                @if ($consultation->prescription)
                    <dt>Receta</dt>
                    <dd>{{ $consultation->prescription->items->map(fn ($item) => $item->medication.(($i = $prescriptions->instructionsOf($item)) ? ': '.$i : ''))->implode("\n") }}</dd>
                @endif
                @if (filled($consultation->next_steps))<dt>Próximos pasos</dt><dd>{{ $consultation->next_steps }}</dd>@endif
            </dl>
        </div>
    @empty
        <p class="empty">Sin atenciones.</p>
    @endforelse

    <h2>Planes de tratamiento</h2>
    @forelse ($patient->treatmentPlans as $plan)
        <div class="visit">
            <div class="when">
                <h3>{{ $plan->title ?: 'Plan de tratamiento' }} · {{ $plan->created_at->format('d/m/Y') }}</h3>
                <span class="muted">{{ $plan->status->getLabel() }} · {{ $plan->doctor->name }} · {{ Money::format($plan->total()) }}</span>
            </div>
            <ul>
                @foreach ($plan->items as $item)
                    <li>{{ $item->label() }} · {{ $item->status->getLabel() }}{{ $item->sessions_planned > 1 ? " ({$item->sessions_done} de {$item->sessions_planned} sesiones)" : '' }} · {{ Money::format($item->subtotal()) }}</li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="empty">Sin planes de tratamiento.</p>
    @endforelse

    <h2>Consentimientos</h2>
    @forelse ($patient->consents as $consent)
        <p style="margin: 0 0 4px">{{ $consent->signed_at?->format('d/m/Y') }} · {{ $consent->title }} · firmó {{ $consent->signer_name }}{{ $consent->revoked_at ? ' · revocado el '.$consent->revoked_at->format('d/m/Y') : '' }}</p>
    @empty
        <p class="empty">Sin consentimientos firmados.</p>
    @endforelse

    <h2>Radiografías, fotos y documentos</h2>
    @forelse ($patient->clinicalFiles as $file)
        <p style="margin: 0 0 4px">{{ $file->taken_at?->format('d/m/Y') }} · {{ $file->type->getLabel() }} · {{ $file->title }} <span class="muted">({{ ClinicalFileService::humanSize($file->size_bytes) }})</span></p>
    @empty
        <p class="empty">Sin archivos.</p>
    @endforelse

    <p class="printed">Impreso el {{ now()->format('d/m/Y H:i') }} por {{ auth()->user()->name }}. Documento confidencial (Ley N.º 29733).</p>
</x-print-layout>
