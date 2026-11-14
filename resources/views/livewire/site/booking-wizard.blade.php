@php
    use App\Enums\PatientRelationship;
    use App\Services\Web\ClinicInfo;
    use App\Support\CampaignText;
    use App\Support\Dates;
    use App\Support\Money;
    use App\Support\Phone;
    use Illuminate\Support\Arr;
@endphp
{{-- data-treatment: con él, el paso queda en la dirección de la página y "atrás" o recargar no pierden lo elegido. --}}
<div class="stack-lg" data-treatment="{{ $this->treatment?->slug }}">
    @if ($step < 4)
        <ol class="steps" aria-label="Paso {{ $step }} de 3">
            @foreach (['Motivo', 'Día y hora', 'Tus datos'] as $index => $name)
                <li @class(['done' => $index < $step]) @if ($index + 1 === $step) aria-current="step" @endif>{{ $name }}</li>
            @endforeach
        </ol>
    @endif

    <div wire:loading.delay class="loading-line" aria-hidden="true"></div>

    @if ($error)
        <div class="flash bad" role="alert">{{ $error }}</div>
    @endif

    {{-- La promoción con que llegó se ve en todos los pasos, para que sepa que va con ella. --}}
    @if ($step < 4 && ($promo = $this->campaign))
        <div class="flash info">
            <p>Reservas con la promoción {{ $promo->name }}{{ ($discount = CampaignText::discount($promo)) ? ' · '.$discount : '' }}.</p>
            @if ($promo->treatment && $this->treatment && ! $this->treatment->is($promo->treatment))
                <p class="muted small">Se aplica a {{ $promo->treatment->name }}: el doctor lo revisa en esta cita y lo programa contigo.</p>
            @endif
        </div>
    @endif

    {{-- Paso 1: qué necesita --}}
    @if ($step === 1)
        <section class="stack" aria-labelledby="paso-1">
            <h1 id="paso-1" tabindex="-1">¿Qué necesitas?</h1>
            <p class="lead">Elige el motivo de tu cita. Si lo que buscas no está aquí, empieza con una evaluación: ahí el doctor te da el diagnóstico y el presupuesto.</p>

            {{-- Con el celular recordado ya se sabe si está en tratamiento: se dice ahora, no al final. --}}
            @if ($current = $this->inTreatment->firstWhere('id', $patientId))
                @include('livewire.site.partials.in-treatment', ['person' => $current, 'others' => true])
            @endif

            <ul class="choice-list">
                @forelse ($this->treatments as $treatment)
                    <li>
                        <button type="button" wire:click="chooseTreatment({{ $treatment->id }})" @class(['choice', 'featured' => $treatment->is_evaluation])>
                            <strong>{{ $treatment->is_evaluation ? 'No sé qué necesito: '.mb_strtolower($treatment->name) : $treatment->name }}</strong>
                            @if ($campaignPrice = $this->campaign?->priceFor($treatment))
                                <span class="price"><s>{{ Money::short($treatment->price_from) }}</s> desde {{ Money::short($campaignPrice) }}</span>
                            @elseif ($treatment->show_price && $treatment->price_from)
                                <span class="price">desde {{ Money::short($treatment->price_from) }}</span>
                            @else
                                <span></span>
                            @endif
                            <span class="meta">
                                {{ $treatment->summary ? $treatment->summary.' · ' : '' }}{{ $treatment->duration_minutes }} min aprox.
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="panel">Por ahora no hay horarios para reservar en la web. Escríbenos por WhatsApp y te ayudamos.</li>
                @endforelse
                {{-- Quien tiene dolor no espera un horario de la web: se le da una salida desde el primer paso. --}}
                @if ($urgent = app(ClinicInfo::class)->whatsappUrl('Hola, tengo dolor y necesito una cita lo antes posible.'))
                    <li>
                        <a href="{{ $urgent }}" class="choice urgent" rel="noopener">
                            <strong>Tengo dolor o es urgente</strong>
                            <span></span>
                            <span class="meta">Escríbenos por WhatsApp y te decimos el horario más cercano.</span>
                        </a>
                    </li>
                @endif
            </ul>

            <p class="muted small">¿Ya estás en tratamiento? Tu doctor programa tus próximas sesiones; si necesitas un cambio, escríbenos.</p>
        </section>
    @endif

    {{-- Paso 2: día y hora --}}
    @if ($step === 2 && $this->treatment)
        <section class="stack" aria-labelledby="paso-2">
            <button type="button" class="link-btn" data-step-back>← Cambiar tratamiento</button>
            <h1 id="paso-2" tabindex="-1">Elige día y hora</h1>
            <p class="lead">{{ $this->treatment->name }} · {{ $this->treatment->duration_minutes }} min aprox.</p>

            @if ($this->doctors->count() > 1)
                <div class="stack">
                    <h2>Con quién</h2>
                    <div class="chips wrap" role="group" aria-label="Doctor">
                        <button type="button" class="chip" aria-pressed="{{ $doctorId === null ? 'true' : 'false' }}" wire:click="selectDoctor(null)">El primero disponible</button>
                        @foreach ($this->doctors as $doctor)
                            <button type="button" class="chip" aria-pressed="{{ $doctorId === $doctor->id ? 'true' : 'false' }}" wire:click="selectDoctor({{ $doctor->id }})">{{ $doctor->name }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @include('livewire.site.partials.slot-picker', ['action' => 'chooseTime', 'selectedTimes' => [], 'slotsNote' => null, 'slotsFull' => false])
        </section>
    @endif

    {{-- Paso 3: datos --}}
    @if ($step === 3 && $this->treatment)
        <section class="stack" aria-labelledby="paso-3">
            <button type="button" class="link-btn" data-step-back>← Cambiar horario</button>
            <h1 id="paso-3" tabindex="-1">Tus datos</h1>

            <div class="summary">
                <p class="what">{{ $this->treatment->name }}</p>
                <p style="margin: 4px 0 0">{{ Dates::day($date) }}, {{ Dates::time($time) }}</p>
                @php($chosen = $this->daySlots->firstWhere('time', $time))
                @if ($chosen)
                    <p class="muted" style="margin: 2px 0 0">con {{ $this->doctors->firstWhere('id', $chosen['doctor_id'])?->name }}</p>
                @endif
                @if ($price = CampaignText::price($this->treatment, $this->campaign))
                    <p class="price-line">{{ $price }}</p>
                @endif
            </div>

            <form wire:submit="book" class="stack" novalidate>
                @if ($this->device)
                    <div class="field">
                        <span class="label" id="para-quien">¿Para quién es la cita?</span>
                        <div class="chips wrap" role="group" aria-labelledby="para-quien">
                            @foreach ($this->family as $person)
                                <button type="button" class="chip" aria-pressed="{{ $patientId === $person->id ? 'true' : 'false' }}" wire:click="choosePatient({{ $person->id }})">{{ $this->familyNames[$person->id] }}{{ $this->inTreatment->contains('id', $person->id) ? ' · en tratamiento' : '' }}</button>
                            @endforeach
                            <button type="button" class="chip" aria-pressed="{{ $patientId === null ? 'true' : 'false' }}" wire:click="forSomeoneElse">Otra persona</button>
                        </div>
                        <span class="hint">Celular {{ Phone::display($this->device->account->phone) }}. ¿No eres tú? <a href="{{ route('site.account') }}">Cambiar</a></span>
                    </div>
                    @if ($current = $this->inTreatment->firstWhere('id', $patientId))
                        @include('livewire.site.partials.in-treatment', ['person' => $current, 'others' => false])
                    @endif
                @else
                    <div class="field">
                        <label for="phone">Tu celular</label>
                        <div class="prefix">
                            <span>+51</span>
                            <input id="phone" type="tel" inputmode="tel" autocomplete="tel-national" class="input" wire:model="phone" placeholder="987 654 321" @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                        </div>
                        <span class="hint">Por aquí te escribimos por WhatsApp para confirmar.</span>
                        @error('phone') <span class="error" id="phone-error">{{ $message }}</span> @enderror
                    </div>

                @endif

                @if ($patientId === null)
                    @unless ($this->hasHolder)
                        <div class="field">
                            <span class="label" id="es-para-ti">¿La cita es para ti?</span>
                            <div class="segmented" role="radiogroup" aria-labelledby="es-para-ti">
                                <label><input type="radio" wire:model.live="who" value="me"><span>Sí, para mí</span></label>
                                <label><input type="radio" wire:model.live="who" value="family"><span>Para un familiar</span></label>
                            </div>
                        </div>
                    @endunless

                    @if ($relationship !== 'self')
                        <div class="field">
                            <label for="relationship">El paciente es tu…</label>
                            <select id="relationship" class="input" wire:model="relationship">
                                @foreach ($relationships as $option)
                                    <option value="{{ $option->value }}">{{ $option->getLabel() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="grid-2">
                        <div class="field">
                            <label for="firstName">{{ $relationship === 'self' ? 'Tus nombres' : 'Nombres del paciente' }}</label>
                            <input id="firstName" class="input" autocomplete="{{ $relationship === 'self' ? 'given-name' : 'off' }}" wire:model="firstName" @error('firstName') aria-invalid="true" @enderror>
                            @error('firstName') <span class="error">{{ $message }}</span> @enderror
                        </div>
                        <div class="field">
                            <label for="lastName">{{ $relationship === 'self' ? 'Tus apellidos' : 'Apellidos del paciente' }}</label>
                            <input id="lastName" class="input" autocomplete="{{ $relationship === 'self' ? 'family-name' : 'off' }}" wire:model="lastName" @error('lastName') aria-invalid="true" @enderror>
                            @error('lastName') <span class="error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <fieldset class="field">
                        <legend class="label">Fecha de nacimiento {{ $relationship === 'self' ? '(opcional)' : '' }}</legend>
                        <div class="date-parts">
                            <label><span class="sr-only">Día</span><input id="birthDay" class="input" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="Día" autocomplete="{{ $relationship === 'self' ? 'bday-day' : 'off' }}" wire:model="birthDay" @error('birthDate') aria-invalid="true" @enderror></label>
                            <label><span class="sr-only">Mes</span>
                                <select id="birthMonth" class="input" autocomplete="{{ $relationship === 'self' ? 'bday-month' : 'off' }}" wire:model="birthMonth" @error('birthDate') aria-invalid="true" @enderror>
                                    <option value="">Mes</option>
                                    @foreach (range(1, 12) as $month)
                                        <option value="{{ $month }}">{{ ucfirst(\Carbon\CarbonImmutable::create(2000, $month, 1)->translatedFormat('F')) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label><span class="sr-only">Año</span><input id="birthYear" class="input" inputmode="numeric" pattern="[0-9]*" maxlength="4" placeholder="Año" autocomplete="{{ $relationship === 'self' ? 'bday-year' : 'off' }}" wire:model="birthYear" @error('birthDate') aria-invalid="true" @enderror></label>
                        </div>
                        @if ($relationship !== 'self')
                            <span class="hint">Nos ayuda a preparar la atención, sobre todo si es un niño.</span>
                        @endif
                        @error('birthDate') <span class="error">{{ $message }}</span> @enderror
                    </fieldset>

                    @unless ($this->device)
                        <div class="field">
                            <label for="origin">¿Cómo nos conociste? (opcional)</label>
                            <select id="origin" class="input" wire:model="origin">
                                <option value="">Elige una opción</option>
                                @foreach ($origins as $option)
                                    <option value="{{ $option->value }}">{{ $option->getLabel() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endunless
                @endif

                <details class="more" @if (filled($promoCode) || filled($note)) open @endif>
                    <summary>Agregar un comentario o código de promoción</summary>
                    <div class="stack" style="margin-top: 12px">
                        <div class="field">
                            <label for="note">Comentario para el doctor (opcional)</label>
                            <textarea id="note" class="input" rows="2" maxlength="300" wire:model="note" placeholder="Por ejemplo: me duele una muela del lado derecho"></textarea>
                        </div>
                        <div class="field">
                            <label for="promoCode">Código de promoción</label>
                            <input id="promoCode" class="input" style="text-transform: uppercase" maxlength="30" wire:model.live.blur="promoCode" aria-describedby="promoCode-status" @if ($this->promoCodeProblem()) aria-invalid="true" @endif>
                            <span id="promoCode-status" role="status">
                                @if ($problem = $this->promoCodeProblem())
                                    <span class="error">{{ $problem }}</span>
                                @elseif (filled($promoCode) && ($applied = $this->campaign))
                                    <span class="hint ok">Código aplicado: {{ $applied->name }}.</span>
                                @endif
                            </span>
                        </div>
                    </div>
                </details>

                @unless ($this->privacyAlreadyAccepted())
                    <div class="field">
                        <label class="check">
                            <input type="checkbox" wire:model="acceptsPrivacy" @error('acceptsPrivacy') aria-invalid="true" @enderror>
                            <span>Acepto la <a href="{{ route('site.privacy') }}" target="_blank">política de privacidad</a>: mis datos se usan solo para atenderme.</span>
                        </label>
                        @error('acceptsPrivacy') <span class="error">{{ $message }}</span> @enderror
                    </div>
                @endunless
                <label class="check">
                    <input type="checkbox" wire:model="acceptsPromotions">
                    <span>Quiero recibir promociones por WhatsApp (puedo darme de baja cuando quiera).</span>
                </label>

                @if ($errors->any())
                    <p class="form-problems" role="alert">Para reservar, revisa {{ ($fields = $this->fieldsToReview()) ? Arr::join($fields, ', ', ' y ') : 'los datos marcados' }}.</p>
                @endif

                <button type="submit" class="btn block" wire:loading.attr="disabled" wire:target="book" @disabled($this->inTreatment->contains('id', $patientId))>
                    <span wire:loading.remove wire:target="book">Reservar cita</span>
                    <span wire:loading wire:target="book">Reservando…</span>
                </button>
                <p class="muted small" style="margin-top: 8px">No se cobra nada ahora. {{ CampaignText::HOW_TO_PAY }}</p>
            </form>
        </section>
    @endif

    {{-- Paso 4: listo --}}
    @if ($step === 4 && $this->appointment)
        @php($appointment = $this->appointment)
        <section class="stack booked" aria-labelledby="listo">
            <span class="done-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
            <h1 id="listo" tabindex="-1">¡Tu cita quedó reservada!</h1>
            <div class="summary">
                <p class="what">{{ $appointment->treatment->name }}</p>
                <p style="margin: 4px 0 0">{{ Dates::day($appointment->starts_at->toImmutable()) }}, {{ Dates::time($appointment->starts_at->format('H:i')) }}</p>
                <p class="muted" style="margin: 2px 0 0">{{ $appointment->patient->full_name }} · con {{ $appointment->doctor->name }}</p>
                @if ($price = CampaignText::price($appointment->treatment, $appointment->campaign))
                    <p class="price-line">{{ $price }} {{ CampaignText::HOW_TO_PAY }}</p>
                @endif
            </div>

            @if ($this->device)
                <p class="lead">Te escribiremos por WhatsApp para recordarte la cita. También la ves en <a href="{{ route('site.account') }}">Mi cuenta</a>, donde puedes confirmarla o cambiarla.</p>
            @else
                <p class="lead">Te escribiremos por WhatsApp al {{ Phone::display($appointment->patient->account->phone) }} con un enlace para confirmar tu asistencia, cambiar el horario o cancelar.</p>
                @if ($url = $this->whatsappUrl())
                    <p>¿Quieres adelantarte? Escríbenos y te respondemos con tu enlace.</p>
                    <a href="{{ $url }}" class="btn whatsapp block" rel="noopener">Escribir al consultorio por WhatsApp</a>
                @endif
            @endif

            <a href="{{ route('site.booking') }}" class="btn quiet block">Reservar otra cita</a>
        </section>
    @endif
</div>

{{--
    En el celular cada paso empieza arriba (antes "¡Tu cita quedó reservada!" aparecía a medias) y, si falta
    un dato, la pantalla va al primero. El paso queda en la dirección de la página: el botón "atrás" vuelve
    al paso anterior en vez de sacar de la reserva, y recargar no pierde el tratamiento ni el horario.
--}}
@script
<script>
    const root = $wire.$el;
    let step = $wire.step;
    let cameFromHistory = false;

    const addressOf = (step) => {
        const url = new URL(window.location.href);

        ['tratamiento', 'fecha', 'hora'].forEach((key) => url.searchParams.delete(key));

        if (step === 2 || step === 3) {
            url.searchParams.set('tratamiento', root.dataset.treatment);
        }

        if (step === 3) {
            url.searchParams.set('fecha', $wire.date);
            url.searchParams.set('hora', $wire.time);
        }

        return url;
    };

    history.replaceState({ paso: step }, '');

    $wire.$watch('step', (next) => requestAnimationFrame(() => {
        if (! cameFromHistory) {
            // "sigue": el paso anterior quedó justo detrás en el historial.
            next > step && next < 4
                ? history.pushState({ paso: next, sigue: true }, '', addressOf(next))
                : history.replaceState({ paso: next }, '', addressOf(next));
        }

        cameFromHistory = false;
        step = next;
        window.scrollTo(0, 0);
        root.querySelector('h1')?.focus({ preventScroll: true });
    }));

    window.addEventListener('popstate', (event) => {
        const target = event.state?.paso;

        if (! target || target === step) {
            return;
        }

        // Con la cita ya reservada, o hacia adelante, la página se arma de nuevo desde su dirección.
        if (step === 4 || target > step) {
            window.location.reload();

            return;
        }

        cameFromHistory = true;
        $wire.back(step - target);
    });

    root.addEventListener('click', (event) => {
        if (event.target.closest('[data-step-back]')) {
            history.state?.sigue ? history.back() : $wire.back();
        }
    });

    $wire.on('datos-por-corregir', () => requestAnimationFrame(() => {
        const first = root.querySelector('[aria-invalid="true"]');

        if (first) {
            (first.closest('.field') ?? first).scrollIntoView({ block: 'center' });
            first.focus({ preventScroll: true });
        }
    }));
</script>
@endscript
