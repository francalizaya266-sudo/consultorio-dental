<x-filament-widgets::widget>
    <x-filament::section heading="Por hacer hoy" icon="heroicon-o-clipboard-document-check" description="A quién escribirle o llamar para que no se pierda ningún tratamiento.">
        @forelse ($sections as $section)
            <section class="dash-group" aria-labelledby="follow-up-{{ $section['key'] }}">
                <header class="dash-group-head">
                    <h3 id="follow-up-{{ $section['key'] }}">{{ $section['title'] }} <span class="dash-count">{{ $section['count'] }}</span></h3>
                    @if ($section['url'])
                        <x-filament::link :href="$section['url']" size="sm">
                            {{ $section['count'] > count($section['rows']) && $section['rows'] !== [] ? 'Ver los '.$section['count'] : 'Ver lista' }}
                        </x-filament::link>
                    @endif
                </header>
                <p class="dash-hint">{{ $section['summary'] ?? $section['hint'] }}</p>

                @if ($section['rows'] !== [])
                    <ul class="dash-rows">
                        @foreach ($section['rows'] as $row)
                            <li>
                                <div class="dash-row-text">
                                    <a href="{{ $row['url'] }}" class="dash-name">{{ $row['name'] }}</a>
                                    <small>{{ $row['detail'] }}</small>
                                    @if ($row['contacted'])
                                        <small class="dash-done">
                                            <x-filament::icon icon="heroicon-m-check" class="dash-done-icon" />
                                            {{ $row['contacted'] }}
                                        </small>
                                    @endif
                                </div>
                                <div class="dash-row-actions">
                                    @if ($row['can_message'] && $section['template'])
                                        {{ ($this->whatsappAction)(['template' => $section['template'], 'patient' => $row['patient_id']]) }}
                                    @endif
                                    @if ($row['new_appointment_url'])
                                        <x-filament::link :href="$row['new_appointment_url']" icon="heroicon-m-calendar-days" size="sm" color="gray">Nueva cita</x-filament::link>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <div class="dash-empty">
                <x-filament::icon icon="heroicon-o-check-circle" class="dash-empty-icon" />
                <p>Todo al día: no hay presupuestos, planes ni cuotas por perseguir.</p>
            </div>
        @endforelse
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
