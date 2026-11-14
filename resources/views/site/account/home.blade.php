@php
    use App\Enums\PatientRelationship;
    use App\Enums\PlanItemStatus;
    use App\Support\CampaignText;
    use App\Support\Dates;
    use App\Support\FamilyNames;
    use App\Support\Money;
    use App\Support\Phone;

    $holder = $patients->firstWhere('relationship', PatientRelationship::Self);
    // Dos personas de la familia con el mismo nombre se muestran con su apellido, para saber de quién es cada cita.
    $names = FamilyNames::for($patients);
@endphp
<x-site-layout title="Mi cuenta" :noindex="true">
    <section class="stack-lg">
        <div class="stack">
            <h1>Hola{{ $holder ? ', '.$holder->first_name : '' }}</h1>
            <p class="lead">Citas de {{ $patients->count() > 1 ? 'tu familia' : 'este celular' }} · {{ Phone::display($account->phone) }}</p>
        </div>

        <section class="stack" aria-labelledby="proximas">
            <div class="spread">
                <h2 id="proximas">Próximas citas</h2>
                <a href="{{ route('site.booking') }}" class="btn small">Reservar otra</a>
            </div>

            @forelse ($upcoming as $appointment)
                @include('site.partials.appointment-card', [
                    'name' => $appointment->patient->relationship === PatientRelationship::Self ? null : $names[$appointment->patient_id],
                    'confirmUrl' => route('site.account.confirm', $appointment),
                    'changeUrl' => route('site.account.change', $appointment),
                    'cancelUrl' => route('site.account.cancel', $appointment),
                    'askHealth' => in_array($appointment->id, $askHealth, true),
                ])
            @empty
                <div class="panel">
                    <p>No tienes citas próximas.</p>
                </div>
            @endforelse
        </section>

        @if ($elevated && ($plans->isNotEmpty() || $balances->isNotEmpty()))
            <section class="stack" aria-labelledby="planes">
                <h2 id="planes">Planes y pagos</h2>

                @foreach ($plans as ['plan' => $plan, 'progress' => $progress, 'payment' => $payment])
                    @php
                        $items = $plan->items->filter(fn ($item) => $item->accepted_at !== null && $item->status !== PlanItemStatus::Cancelled);
                        $done = $items->where('status', PlanItemStatus::Done)->count();
                        [$state, $tone] = match (true) {
                            $progress === null => ['Incluido en el 2x1', 'ok'],
                            $progress['late'] => ['Atrasado', 'bad'],
                            $progress['owed'] > 0 => ['Por pagar', null],
                            $progress['remaining'] <= 0 => ['Pagado', 'ok'],
                            default => ['Al día', 'ok'],
                        };
                    @endphp
                    <article class="plan-card">
                        <div class="spread">
                            <h3>{{ $plan->title ?: 'Plan de tratamiento' }}</h3>
                            <span @class(['badge', $tone])>{{ $state }}</span>
                        </div>
                        <p class="muted">{{ $names[$plan->patient_id] }} · {{ $plan->doctor?->name }}</p>

                        @if ($progress === null)
                            <p>Va incluido en el 2x1 de {{ $plan->includedIn?->patient?->first_name }}: no tiene costo.</p>
                        @else
                            <div class="meter" role="img" aria-label="Pagado {{ Money::format($progress['paid']) }} de {{ Money::format($progress['total']) }}">
                                <span style="width: {{ $progress['total'] > 0 ? round(min(1, $progress['paid'] / $progress['total']) * 100) : 100 }}%"></span>
                            </div>
                            <dl class="facts">
                                <dt>Total</dt>
                                <dd>{{ Money::format($progress['total']) }}</dd>
                                <dt>Pagado</dt>
                                <dd>{{ Money::format($progress['paid']) }}</dd>
                                <dt>Falta pagar</dt>
                                <dd>{{ Money::format($progress['remaining']) }}</dd>
                                @if ($progress['owed'] > 0)
                                    <dt>{{ $progress['late'] ? 'Vencido' : 'Para pagar ya' }}</dt>
                                    <dd @class(['owed' => $progress['late']])>{{ Money::format($progress['owed']) }}</dd>
                                @endif
                                @if ($progress['next'])
                                    <dt>Próximo pago</dt>
                                    <dd>
                                        {{ Money::format($progress['next']['due']) }} · {{ Dates::day($progress['next']['installment']->due_on) }}
                                        <span class="muted small">{{ $progress['next']['installment']->label() }}</span>
                                    </dd>
                                @endif
                            </dl>
                            <p class="muted small">{{ $payment }}</p>
                        @endif

                        @if ($items->isNotEmpty())
                            <details class="more">
                                <summary>Tratamientos: {{ $done }} de {{ $items->count() }} {{ $items->count() === 1 ? 'hecho' : 'hechos' }}</summary>
                                <ul class="plan-items">
                                    @foreach ($items as $item)
                                        <li @class(['done' => $item->status === PlanItemStatus::Done])>
                                            <span>{{ $item->label() }}</span>
                                            <span class="muted">{{ $item->sessions_planned > 1 && $item->status !== PlanItemStatus::Done ? $item->sessions_done.' de '.$item->sessions_planned.' sesiones' : $item->status->getLabel() }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </article>
                @endforeach

                @foreach ($balances as $name => $balance)
                    <p class="spread">
                        <span>Saldo de {{ $name }}</span>
                        <strong @class(['owed' => $balance > 0])>{{ $balance > 0 ? Money::format($balance).' por pagar' : Money::format(-$balance).' a favor' }}</strong>
                    </p>
                @endforeach
                <p class="muted small">{{ CampaignText::HOW_TO_PAY }}</p>
            </section>
        @endif

        <section class="stack" aria-labelledby="anteriores">
            <h2 id="anteriores">{{ $elevated ? 'Citas anteriores y datos personales' : 'Planes, pagos, citas anteriores y datos' }}</h2>
            @if ($elevated)
                @forelse ($past as $appointment)
                    <p class="spread">
                        <span>{{ $appointment->starts_at->format('d/m/Y') }} · <strong>{{ $names[$appointment->patient_id] }}</strong> · {{ $appointment->treatment->name }}</span>
                        <span class="badge quiet">{{ $appointment->status->getLabel() }}</span>
                    </p>
                @empty
                    <p class="muted">Todavía no hay citas anteriores.</p>
                @endforelse

                <div class="stack" style="margin-top: 24px">
                    @foreach ($patients as $patient)
                        <div class="panel">
                            <h3>{{ $patient->full_name }}</h3>
                            <dl class="facts" style="margin-top: 8px">
                                <dt>Parentesco</dt>
                                <dd>{{ $patient->relationship->getLabel() }}</dd>
                                <dt>Documento</dt>
                                <dd>{{ $patient->document_number ?: '—' }}</dd>
                                <dt>Nacimiento</dt>
                                <dd>{{ $patient->birth_date?->format('d/m/Y') ?? '—' }}</dd>
                                <dt>Correo</dt>
                                <dd>{{ $patient->email ?: '—' }}</dd>
                            </dl>
                        </div>
                    @endforeach
                    <p class="muted small">¿Algún dato está mal? Escríbenos por WhatsApp y lo corregimos.</p>
                </div>
            @else
                <p>Para cuidar tus datos, primero confirmamos que eres tú: te enviaremos un enlace por WhatsApp.</p>
                <form method="POST" action="{{ route('site.account.verify') }}">
                    @csrf
                    <button type="submit" class="btn quiet block">Enviarme el enlace</button>
                </form>
            @endif
        </section>

        <form method="POST" action="{{ route('site.account.logout') }}">
            @csrf
            <button type="submit" class="link-btn">Salir en este celular</button>
        </form>
    </section>
</x-site-layout>
