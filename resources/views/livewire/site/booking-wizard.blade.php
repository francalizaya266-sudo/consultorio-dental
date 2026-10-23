@php
    use App\Enums\PatientRelationship;
    use App\Livewire\Site\BookingWizard;
    use App\Support\Money;
    use App\Support\Phone;
@endphp
<div class="stack-lg">
    @if ($step < 4)
        <ol class="steps" aria-label="Paso {{ $step }} de 3">
            @foreach ([1, 2, 3] as $n)
                <li @class(['done' => $n <= $step])></li>
            @endforeach
        </ol>
    @endif

    <div wire:loading.delay class="loading-line" aria-hidden="true"></div>

    @if ($error)
        <div class="flash bad" role="alert">{{ $error }}</div>
    @endif

    {{-- Paso 1: qué necesita --}}
    @if ($step === 1)
        <section class="stack" aria-labelledby="paso-1">
            <h1 id="paso-1">¿Qué necesitas?</h1>
            <p class="lead">Elige el motivo de tu cita. Si no estás seguro, empieza con una evaluación.</p>

            <ul class="choice-list">
                @forelse ($this->treatments as $treatment)
                    <li>
                        <button type="button" wire:click="chooseTreatment({{ $treatment->id }})" @class(['choice', 'featured' => $treatment->is_evaluation])>
                            <strong>{{ $treatment->is_evaluation ? 'No sé qué necesito: '.mb_strtolower($treatment->name) : $treatment->name }}</strong>
                            @if ($treatment->show_price && $treatment->price_from)
                                <span class="price">desde {{ Money::format($treatment->price_from) }}</span>
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
            </ul>

            <p class="muted small">¿Ya estás en tratamiento? Tu doctor programa tus próximas sesiones; si necesitas un cambio, escríbenos.</p>
        </section>
    @endif

    {{-- Paso 2: día y hora --}}
    @if ($step === 2 && $this->treatment)
        <section class="stack" aria-labelledby="paso-2">
            <button type="button" class="link-btn" wire:click="back">← Cambiar tratamiento</button>
            <h1 id="paso-2">Elige día y hora</h1>
            <p class="lead">{{ $this->treatment->name }} · {{ $this->treatment->duration_minutes }} min aprox.</p>

            @if ($this->doctors->count() > 1)
                <div class="stack">
                    <h2>Con quién</h2>
                    <div class="chips" role="group" aria-label="Doctor">
                        <button type="button" class="chip" aria-pressed="{{ $doctorId === null ? 'true' : 'false' }}" wire:click="selectDoctor(null)">El primero disponible</button>
                        @foreach ($this->doctors as $doctor)
                            <button type="button" class="chip" aria-pressed="{{ $doctorId === $doctor->id ? 'true' : 'false' }}" wire:click="selectDoctor({{ $doctor->id }})">{{ $doctor->name }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @include('livewire.site.partials.slot-picker', ['action' => 'chooseTime', 'selectedTimes' => []])
        </section>
    @endif

    {{-- Paso 3: datos --}}
    @if ($step === 3 && $this->treatment)
        <section class="stack" aria-labelledby="paso-3">
            <button type="button" class="link-btn" wire:click="back">← Cambiar horario</button>
            <h1 id="paso-3">Tus datos</h1>

            <div class="summary">
                <p class="what">{{ $this->treatment->name }}</p>
                <p style="margin: 4px 0 0">{{ BookingWizard::dayLabel($date) }}, {{ BookingWizard::timeLabel($time) }}</p>
                @php($chosen = $this->daySlots->firstWhere('time', $time))
                @if ($chosen)
                    <p class="muted" style="margin: 2px 0 0">con {{ $this->doctors->firstWhere('id', $chosen['doctor_id'])?->name }}</p>
                @endif
            </div>

            <form wire:submit="book" class="stack" novalidate>
                @if ($this->device)
                    <div class="field">
                        <span class="label" id="para-quien">¿Para quién es la cita?</span>
                        <div class="chips" role="group" aria-labelledby="para-quien">
                            @foreach ($this->family as $person)
                                <button type="button" class="chip" aria-pressed="{{ $patientId === $person->id ? 'true' : 'false' }}" wire:click="choosePatient({{ $person->id }})">{{ $person->first_name }}</button>
                            @endforeach
                            <button type="button" class="chip" aria-pressed="{{ $patientId === null ? 'true' : 'false' }}" wire:click="forSomeoneElse">Otra persona</button>
                        </div>
                        <span class="hint">Celular {{ Phone::display($this->device->account->phone) }}. ¿No eres tú? <a href="{{ route('site.account') }}">Cambiar</a></span>
                    </div>
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

                    <div class="field">
                        <label for="birthDate">Fecha de nacimiento {{ $relationship === 'self' ? '(opcional)' : '' }}</label>
                        <input id="birthDate" type="date" class="input" max="{{ today()->subDay()->toDateString() }}" wire:model="birthDate">
                        @if ($relationship !== 'self')
                            <span class="hint">Nos ayuda a preparar la atención, sobre todo si es un niño.</span>
                        @endif
                        @error('birthDate') <span class="error">{{ $message }}</span> @enderror
                    </div>

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
                            <input id="promoCode" class="input" style="text-transform: uppercase" maxlength="30" wire:model="promoCode">
                        </div>
                    </div>
                </details>

                @unless ($this->privacyAlreadyAccepted())
                    <div class="field">
                        <label class="check">
                            <input type="checkbox" wire:model="acceptsPrivacy">
                            <span>Acepto la <a href="{{ route('site.privacy') }}" target="_blank">política de privacidad</a>: mis datos se usan solo para atenderme.</span>
                        </label>
                        @error('acceptsPrivacy') <span class="error">{{ $message }}</span> @enderror
                    </div>
                @endunless
                <label class="check">
                    <input type="checkbox" wire:model="acceptsPromotions">
                    <span>Quiero recibir promociones por WhatsApp (puedo darme de baja cuando quiera).</span>
                </label>

                <button type="submit" class="btn block" wire:loading.attr="disabled" wire:target="book">
                    <span wire:loading.remove wire:target="book">Reservar cita</span>
                    <span wire:loading wire:target="book">Reservando…</span>
                </button>
                <p class="muted small" style="margin-top: 8px">No se cobra nada ahora: pagas en el consultorio.</p>
            </form>
        </section>
    @endif

    {{-- Paso 4: listo --}}
    @if ($step === 4 && $this->appointment)
        @php($appointment = $this->appointment)
        <section class="stack" aria-labelledby="listo">
            <h1 id="listo">¡Tu cita quedó reservada!</h1>
            <div class="summary">
                <p class="what">{{ $appointment->treatment->name }}</p>
                <p style="margin: 4px 0 0">{{ BookingWizard::dayLabel($appointment->starts_at->toImmutable()) }}, {{ BookingWizard::timeLabel($appointment->starts_at->format('H:i')) }}</p>
                <p class="muted" style="margin: 2px 0 0">{{ $appointment->patient->full_name }} · con {{ $appointment->doctor->name }}</p>
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
