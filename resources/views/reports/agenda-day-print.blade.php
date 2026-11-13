@use('App\Support\Phone')
<x-print-layout title="Agenda del día">
    <style>
        .period { font-size: 1rem; font-weight: 700; margin: -4px 0 14px; }
        .agenda-doctor { margin-bottom: 20px; }
        .agenda-doctor h2 { font-size: .95rem; color: var(--brand); margin: 0; }
        .agenda-doctor .muted { margin-bottom: 6px; }
        .agenda-table td, .agenda-table th { padding: 5px 6px; font-size: .85rem; }
        .agenda-table .time { white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 700; }
        .agenda-table .tick { width: 34px; text-align: center; }
        .agenda-table .box { display: inline-block; width: 14px; height: 14px; border: 1.5px solid var(--ink); border-radius: 3px; }
        .agenda-table .mark { font-weight: 700; }
        .agenda-table .empty { color: var(--muted); text-align: center; }
        .fine { margin-top: 16px; font-size: .8rem; color: var(--muted); }
        @media print { .agenda-table tr { break-inside: avoid; } }
    </style>

    <div class="period">{{ ucfirst($day->translatedFormat('l j \d\e F \d\e Y')) }} · {{ $total === 1 ? '1 cita' : $total.' citas' }}</div>

    @forelse ($agendas as $agenda)
        <section class="agenda-doctor">
            <h2>{{ $agenda['doctor']->name }}</h2>
            <div class="muted">
                {{ $agenda['hours'] !== '' ? 'Atiende '.$agenda['hours'] : 'No atiende este día' }}
                @foreach ($agenda['blocks'] as $block)
                    · No se atiende {{ $block->hoursOn($day) }}: {{ $block->reason ?: $block->type->getLabel() }}
                @endforeach
            </div>

            <table class="agenda-table">
                <thead>
                    <tr>
                        <th scope="col">Hora</th>
                        <th scope="col">Paciente</th>
                        <th scope="col">Tratamiento</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Notas</th>
                        <th scope="col" class="tick">Vino</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($agenda['appointments'] as $appointment)
                        <tr>
                            <td class="time">{{ $appointment->starts_at->format('H:i') }}–{{ $appointment->ends_at->format('H:i') }}</td>
                            <td>
                                {{ $appointment->patient->full_name }}
                                @if ($appointment->is_new_patient)<span class="mark">· Nuevo</span>@endif
                                @if ($appointment->is_overbooked)<span class="mark">· Sobrecupo</span>@endif
                                @if ($appointment->patient->account)
                                    <div class="muted">{{ Phone::display($appointment->patient->account->phone) }}</div>
                                @endif
                            </td>
                            <td>{{ $appointment->treatment->name }}</td>
                            <td>{{ $appointment->status->getLabel() }}</td>
                            <td>{{ collect([$appointment->patient_note, $appointment->internal_notes])->filter()->implode(' · ') }}</td>
                            <td class="tick"><span class="box"></span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="empty">Sin citas este día.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @empty
        <p class="muted">Nadie atiende ni tiene citas este día.</p>
    @endforelse

    <p class="fine">Impreso el {{ now()->translatedFormat('j \d\e F \a \l\a\s H:i') }}. Lo que se agende o cambie después no está en esta hoja.</p>
</x-print-layout>
