@use('App\Filament\Pages\OwingBalances')
@use('App\Filament\Resources\Appointments\AppointmentResource')
@use('App\Filament\Resources\Installments\InstallmentResource')
@use('App\Filament\Resources\Patients\PatientResource')
@use('App\Support\Money')
{{--
    Dinero en espera, con nombre: presupuestos sin respuesta (el de más plata primero), las faltas del período con lo
    que se perdió y si volvieron a agendar, y quién debe. Cada nombre lleva a su ficha.
--}}
<x-filament-widgets::widget>
    <div class="earnings-grid">
        <x-filament::section heading="Presupuestos sin respuesta" description="Presentados y sin un sí ni un no. Un mensaje a tiempo suele cerrarlos." class="is-wide">
            <div class="earnings-scroll">
                <table class="report-table report-stack">
                    <thead>
                        <tr>
                            <th scope="col">Paciente</th>
                            <th scope="col">Doctor</th>
                            <th scope="col">Presentado</th>
                            <th scope="col" class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($plans as $row)
                            <tr>
                                <td><a href="{{ PatientResource::getUrl('view', ['record' => $row['plan']->patient]) }}" class="report-link">{{ $row['plan']->patient->full_name }}</a></td>
                                <td>{{ $row['plan']->doctor?->name ?? '—' }}</td>
                                <td>
                                    {{ $row['waiting_days'] === 0 ? 'Hoy' : 'Hace '.$row['waiting_days'].($row['waiting_days'] === 1 ? ' día' : ' días') }}
                                    @if ($row['expired'])
                                        <span class="report-tag">Ya venció</span>
                                    @endif
                                </td>
                                <td class="num">{{ Money::format($row['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty">Todos los presupuestos presentados tienen respuesta.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($plans->count() > 1)
                        <tfoot>
                            <tr>
                                <td colspan="3">{{ $plans->count() }} presupuestos</td>
                                <td class="num">{{ Money::format($plans->sum('total')) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Faltas del período" :description="$period->label().': lo que valía cada cita y si volvió a agendar.'" class="is-wide">
            <div class="earnings-scroll">
                <table class="report-table report-stack">
                    <thead>
                        <tr>
                            <th scope="col">Cita</th>
                            <th scope="col">Paciente</th>
                            <th scope="col">Venía a</th>
                            <th scope="col">Después</th>
                            <th scope="col" class="num">Se perdió</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($noShows as $row)
                            @php($appointment = $row['appointment'])
                            <tr>
                                <td>{{ $appointment->starts_at->format('d/m H:i') }}<br><small class="report-muted">{{ $appointment->doctor?->name }}</small></td>
                                <td class="is-lead"><a href="{{ PatientResource::getUrl('view', ['record' => $appointment->patient]) }}" class="report-link">{{ $appointment->patient->full_name }}</a></td>
                                <td>{{ $appointment->planItem?->label(withTooth: false) ?? $appointment->treatment?->name }}</td>
                                <td>
                                    @if ($row['rebooked'])
                                        Volvió a agendar
                                    @else
                                        <a href="{{ AppointmentResource::newUrl(['patient' => $appointment->patient_id]) }}" class="report-link">Sin cita: agendarle</a>
                                    @endif
                                </td>
                                <td class="num">{{ $row['lost'] !== null ? Money::format($row['lost']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="empty">Nadie faltó en este período.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($noShows->count() > 1)
                        <tfoot>
                            <tr>
                                <td colspan="4">{{ $noShows->count() }} faltas</td>
                                <td class="num">{{ Money::format((float) $noShows->sum('lost')) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>

        @if ($balances)
            <x-filament::section heading="Los que más deben" :description="$balances['count'] > 0 ? 'Por cobrar: '.Money::format($balances['amount']).' entre '.$balances['count'].($balances['count'] === 1 ? ' paciente.' : ' pacientes.') : null">
                <table class="report-table">
                    <tbody>
                        @forelse ($balances['patients'] as $patient)
                            <tr>
                                <td><a href="{{ PatientResource::getUrl('view', ['record' => $patient]) }}" class="report-link">{{ $patient->full_name }}</a></td>
                                <td class="num">{{ Money::format((float) $patient->balance_due) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="empty">Nadie debe.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if ($balances['count'] > $balances['patients']->count())
                    <x-filament::link :href="OwingBalances::getUrl()" size="sm" class="report-more">Ver los {{ $balances['count'] }}</x-filament::link>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section heading="Cuotas atrasadas" :description="$installments['count'] > 0 ? Money::format($installments['amount']).' en '.$installments['count'].($installments['count'] === 1 ? ' cuota.' : ' cuotas.') : null">
            <table class="report-table">
                <tbody>
                    @forelse ($installments['installments'] as ['installment' => $installment, 'due' => $due])
                        <tr>
                            <td>
                                <a href="{{ PatientResource::getUrl('view', ['record' => $installment->plan->patient]) }}" class="report-link">{{ $installment->plan->patient->full_name }}</a>
                                <br><small class="report-muted">{{ $installment->label() }} · venció el {{ $installment->due_on->format('d/m') }}</small>
                            </td>
                            <td class="num">{{ Money::format($due) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty">Ninguna cuota atrasada.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($installments['count'] > $installments['installments']->count())
                <x-filament::link :href="InstallmentResource::getUrl('index', ['tab' => 'late'])" size="sm" class="report-more">Ver las {{ $installments['count'] }}</x-filament::link>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
