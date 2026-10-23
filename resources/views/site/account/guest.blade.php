@php
    use App\Models\Setting;
    use App\Support\Phone;

    $requested = session('access_requested');
    $clinicWhatsapp = (string) Setting::get('clinic.whatsapp');
    $whatsappUrl = $requested && Phone::isValid($clinicWhatsapp)
        ? 'https://wa.me/'.Phone::forWhatsApp($clinicWhatsapp).'?text='.rawurlencode('Hola, pedí mi enlace para entrar a Mi cuenta. Mi celular es '.Phone::display($requested).'.')
        : null;
@endphp
<x-site-layout title="Mi cuenta" :noindex="true">
    <section class="stack-lg">
        <div class="stack">
            <h1>Mi cuenta</h1>
            <p class="lead">Aquí ves y manejas las citas de tu familia. No necesitas contraseña: entras con el enlace que te enviamos por WhatsApp, y desde ahí este celular queda recordado.</p>
        </div>

        @if ($requested)
            <div class="panel stack" role="status">
                <h2>Te enviaremos tu enlace por WhatsApp</h2>
                <p>Si tu número está registrado, el consultorio te enviará tu enlace al {{ Phone::display($requested) }} en su horario de atención. Tócalo desde este celular para entrar.</p>
                @if ($whatsappUrl)
                    <a href="{{ $whatsappUrl }}" class="btn whatsapp block" rel="noopener">Avisar al consultorio por WhatsApp</a>
                @endif
            </div>
        @else
            <form method="POST" action="{{ route('site.account.access') }}" class="panel stack">
                @csrf
                <h2>Pedir mi enlace</h2>
                <div class="field">
                    <label for="phone">Tu celular</label>
                    <div class="prefix">
                        <span>+51</span>
                        <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" class="input" value="{{ old('phone') }}" placeholder="987 654 321" required @error('phone') aria-invalid="true" @enderror>
                    </div>
                    @error('phone') <span class="error">{{ $message }}</span> @enderror
                </div>
                <button type="submit" class="btn block">Pedir mi enlace por WhatsApp</button>
            </form>
        @endif

        <div class="stack">
            <h2>¿Primera vez?</h2>
            <p>Reserva tu cita y te enviaremos por WhatsApp el enlace para confirmarla. Ese mismo enlace deja este celular recordado.</p>
            <a href="{{ route('site.booking') }}" class="btn quiet block">Reservar una cita</a>
        </div>
    </section>
</x-site-layout>
