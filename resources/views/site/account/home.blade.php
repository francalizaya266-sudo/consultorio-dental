@php
    use App\Enums\PatientRelationship;
    use App\Enums\PlanItemStatus;
    use App\Support\CampaignText;
    use App\Support\Dates;
    use App\Support\FamilyNames;
    use App\Support\Initials;
    use App\Support\Money;
    use App\Support\Phone;
    use App\Support\SiteIcon;

    // La familia es quien ya está reconocido: a quien está por confirmar no se le saluda ni se le cuenta.
    $family = $patients->reject(fn ($patient) => in_array($patient->id, $guests, true));
    $holder = $family->firstWhere('relationship', PatientRelationship::Self);
    // Dos personas de la familia con el mismo nombre se muestran con su apellido, para saber de quién es cada cita.
    $names = FamilyNames::for($patients);
    $showsPlans = $elevated && ($plans->isNotEmpty() || $balances->isNotEmpty());
@endphp
{{--
    A un lado, de quién es la cuenta y el paso a cada parte; al otro, las citas que vienen, los planes y pagos,
    y las citas anteriores con los datos. En el celular va todo en una columna, con "Salir" al final.
--}}
<x-site-layout page="cuenta" :wide="true" :noindex="true">
    <div class="account">
        <aside class="account-side">
            <div class="who">
                @if ($holder)
                    <span class="avatar" aria-hidden="true">{{ Initials::of($holder->full_name) }}</span>
                @else
                    <span class="avatar" aria-hidden="true">{{ SiteIcon::svg('user') }}</span>
                @endif
                <div>
                    <h2>Hola{{ $holder ? ', '.$holder->first_name : '' }}</h2>
                    <p>Citas de {{ $family->count() > 1 ? 'tu familia' : 'este celular' }} · <span>{{ Phone::display($account->phone) }}</span></p>
                </div>
            </div>
            <nav aria-label="Partes de Mi cuenta">
                <a href="#proximas">{{ SiteIcon::svg('calendar') }}Próximas citas</a>
                @if ($showsPlans)
                    <a href="#planes">{{ SiteIcon::svg('wallet') }}Planes y pagos</a>
                @endif
                <a href="#anteriores">{{ SiteIcon::svg('clock') }}{{ $elevated ? 'Citas anteriores y datos' : 'Pagos, citas anteriores y datos' }}</a>
            </nav>
            <form method="POST" action="{{ route('site.account.logout') }}" class="leave">
                @csrf
                <button type="submit" class="link-btn">Salir en este celular</button>
            </form>
        </aside>

        <div class="account-main">
            <section class="account-card" aria-labelledby="proximas">
                <div class="spread">
                    <h2 id="proximas">{{ SiteIcon::svg('calendar') }}Próximas citas</h2>
                    <a href="{{ route('site.booking') }}" class="btn small">{{ $upcoming->isEmpty() ? 'Reservar cita' : 'Reservar otra cita' }}</a>
                </div>

                <div class="appointments">
                    @forelse ($upcoming as $appointment)
                        @include('site.partials.appointment-card', [
                            'name' => $appointment->patient->is($holder) ? null : $names[$appointment->patient_id],
                            'guest' => in_array((int) $appointment->patient_id, $guests, true),
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
                </div>
            </section>

            @if ($showsPlans)
                <section class="account-card" aria-labelledby="planes">
                    <h2 id="planes">{{ SiteIcon::svg('wallet') }}Planes y pagos</h2>

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

            <section class="account-card" aria-labelledby="anteriores">
                <h2 id="anteriores">{{ SiteIcon::svg('clock') }}{{ $elevated ? 'Citas anteriores y datos personales' : 'Planes, pagos, citas anteriores y datos' }}</h2>
                @if ($elevated)
                    @forelse ($past as $appointment)
                        <p class="spread">
                            <span>{{ $appointment->starts_at->format('d/m/Y') }} · <strong>{{ $names[$appointment->patient_id] }}</strong> · {{ $appointment->treatment->name }}</span>
                            <span class="badge quiet">{{ $appointment->status->getLabel() }}</span>
                        </p>
                    @empty
                        <p class="muted">Todavía no hay citas anteriores.</p>
                    @endforelse

                    <div class="family">
                        @foreach ($family as $patient)
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
                    </div>
                    <p class="muted small">¿Algún dato está mal? Escríbenos por WhatsApp y lo corregimos.</p>
                @else
                    <p>Para cuidar tus datos, primero confirmamos que eres tú: te enviaremos un enlace por WhatsApp.</p>
                    <form method="POST" action="{{ route('site.account.verify') }}">
                        @csrf
                        <button type="submit" class="btn quiet block">Enviarme el enlace</button>
                    </form>
                @endif
            </section>
        </div>
    </div>
</x-site-layout>
