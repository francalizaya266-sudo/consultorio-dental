<x-print-layout title="Consentimiento informado" :doctor="$doctor" :patient="$patient" :date="$consent->signed_at">
    <style>
        .revoked { padding: 8px 12px; border-radius: 6px; background: #fee2e2; color: #b91c1c; font-weight: 700; margin-bottom: 12px; }
        .consent-title { font-weight: 700; margin-bottom: 8px; }
        .body { white-space: pre-line; text-align: justify; }
        .signed { display: flex; justify-content: space-around; gap: 24px; margin-top: 32px; align-items: flex-end; }
        .signed figure { margin: 0; text-align: center; min-width: 220px; }
        .signed img { display: block; max-width: 240px; max-height: 110px; margin: 0 auto -6px; }
        .signed figcaption { border-top: 1px solid var(--ink); padding-top: 4px; font-size: .85rem; }
    </style>

    @if ($consent->revoked_at)
        <div class="revoked">Revocado por el paciente el {{ $consent->revoked_at->format('d/m/Y H:i') }}</div>
    @endif

    <div class="consent-title">{{ $consent->title }}</div>
    <div class="body">{{ $consent->body_snapshot }}</div>

    <div class="signed">
        <figure>
            @if ($signature)
                <img src="{{ $signature }}" alt="Firma de {{ $consent->signer_name }}">
            @endif
            <figcaption>
                {{ $consent->signer_name }}{{ $consent->signer_document ? ' · '.$consent->signer_document : '' }}<br>
                <span class="muted">
                    {{ $consent->signer_relationship === \App\Enums\PatientRelationship::Self ? 'Paciente' : $consent->signer_relationship->getLabel().' del paciente' }}
                    · firmado el {{ $consent->signed_at->format('d/m/Y H:i') }}
                </span>
            </figcaption>
        </figure>
        @if ($doctor)
            <figure>
                <figcaption>{{ $doctor->name }}<br><span class="muted">{{ $doctor->cop_number ? 'C.O.P. '.$doctor->cop_number : 'Firma y sello' }}</span></figcaption>
            </figure>
        @endif
    </div>
</x-print-layout>
