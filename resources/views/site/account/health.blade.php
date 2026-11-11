{{--
    "Antes de tu cita": la salud del paciente en dos minutos, desde el celular recordado. Solo se llena
    (no muestra lo ya registrado). El doctor lo ve en la ficha como "lo llenó el paciente" y lo confirma.
--}}
@php
    use App\Enums\PatientRelationship;

    $self = $patient->relationship === PatientRelationship::Self;
    $name = $patient->first_name;
    $has = $self ? '¿Tienes' : '¿'.$name.' tiene';
@endphp
<x-site-layout title="Antes de la cita" :noindex="true" :whatsapp-float="false" :minimal-footer="true">
    <section class="stack-lg health">
        <div class="stack">
            <a href="{{ route('site.account') }}" class="link-btn" style="text-decoration: none">← Volver a Mi cuenta</a>
            <h1>{{ $self ? 'Antes de tu cita' : 'Antes de la cita de '.$name }}</h1>
            <p class="lead">Dos minutos para que el doctor sepa {{ $self ? 'cómo estás' : 'cómo está '.$name }} antes de empezar. Solo lo ve el equipo del consultorio.</p>
        </div>

        <form method="POST" action="{{ route('site.account.health.store', $patient) }}" class="stack-lg health-form" novalidate>
            @csrf

            @foreach ([
                ['choice' => 'has_allergies', 'detail' => 'allergies', 'question' => $has.' alguna alergia?', 'hint' => 'A medicinas, a la anestesia, al látex.', 'label' => '¿A qué?', 'placeholder' => 'Ej.: penicilina, látex'],
                ['choice' => 'takes_medications', 'detail' => 'medications', 'question' => ($self ? '¿Tomas' : '¿'.$name.' toma').' alguna medicina todos los días?', 'hint' => 'Por ejemplo, para la presión, el azúcar o para que la sangre no coagule.', 'label' => '¿Cuáles?', 'placeholder' => 'Ej.: losartán, metformina'],
            ] as $question)
                <fieldset class="field health-question">
                    <legend class="label">{{ $question['question'] }}</legend>
                    <span class="hint">{{ $question['hint'] }}</span>
                    <div class="segmented">
                        <label><input type="radio" name="{{ $question['choice'] }}" value="no" @checked(old($question['choice']) === 'no')><span>No</span></label>
                        <label><input type="radio" name="{{ $question['choice'] }}" value="yes" @checked(old($question['choice']) === 'yes')><span>Sí</span></label>
                    </div>
                    @error($question['choice'])
                        <p class="error">{{ $message }}</p>
                    @enderror
                    <div class="field health-detail">
                        <label for="{{ $question['detail'] }}">{{ $question['label'] }}</label>
                        <input id="{{ $question['detail'] }}" name="{{ $question['detail'] }}" class="input" maxlength="300" placeholder="{{ $question['placeholder'] }}" value="{{ old($question['detail']) }}" @error($question['detail']) aria-invalid="true" @enderror>
                        @error($question['detail'])
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>
                </fieldset>
            @endforeach

            <fieldset class="field">
                <legend class="label">{{ $has }} alguna de estas?</legend>
                <span class="hint">Marca las que correspondan. Si ninguna, sigue de largo.</span>
                <div class="health-conditions">
                    @foreach ($conditions as $key => $label)
                        <label class="check"><input type="checkbox" name="conditions[]" value="{{ $key }}" @checked(in_array($key, old('conditions', []), true))><span>{{ $label }}</span></label>
                    @endforeach
                </div>
            </fieldset>

            @if ($asksPregnancy || $asksSmoking)
                <fieldset class="field">
                    <legend class="label">También</legend>
                    <div class="health-conditions">
                        @if ($asksPregnancy)
                            <label class="check"><input type="checkbox" name="is_pregnant" value="1" @checked(old('is_pregnant'))><span>{{ $self ? 'Estoy embarazada' : 'Está embarazada' }}</span></label>
                            <label class="check"><input type="checkbox" name="is_breastfeeding" value="1" @checked(old('is_breastfeeding'))><span>{{ $self ? 'Estoy dando de lactar' : 'Está dando de lactar' }}</span></label>
                        @endif
                        @if ($asksSmoking)
                            <label class="check"><input type="checkbox" name="smoker" value="1" @checked(old('smoker'))><span>{{ $self ? 'Fumo' : 'Fuma' }}</span></label>
                        @endif
                    </div>
                </fieldset>
            @endif

            <div class="field">
                <label for="notes">¿Algo más que el doctor deba saber?</label>
                <span class="hint">Opcional. Una operación reciente, un tratamiento que {{ $self ? 'llevas' : 'lleva' }}, miedo al dentista.</span>
                <textarea id="notes" name="notes" class="input" rows="3" maxlength="500">{{ old('notes') }}</textarea>
            </div>

            <div class="stack">
                <button type="submit" class="btn block">Enviar al consultorio</button>
                <p class="muted small">Si algo cambia antes de la cita, cuéntaselo al doctor ese día.</p>
            </div>
        </form>
    </section>
</x-site-layout>
