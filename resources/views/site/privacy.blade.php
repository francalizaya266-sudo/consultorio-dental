@php
    use App\Models\Setting;
    use App\Support\Phone;

    $clinic = (string) Setting::get('clinic.name');
    $address = Setting::get('clinic.address');
    $whatsapp = (string) Setting::get('clinic.whatsapp');
@endphp
<x-site-layout title="Política de privacidad" description="Cómo {{ $clinic }} cuida tus datos personales y de salud (Ley N.º 29733).">
    <article class="stack-lg">
        <div class="stack">
            <h1>Política de privacidad</h1>
            <p class="lead">Cómo cuidamos tus datos personales y de salud, según la Ley N.º 29733 de Protección de Datos Personales y su reglamento.</p>
        </div>

        <section class="stack">
            <h2>Quién es responsable</h2>
            <p>{{ $clinic }}{{ $address ? ', con dirección en '.$address : '' }}, es el titular del banco de datos de sus pacientes y responsable de su tratamiento.</p>
        </section>

        <section class="stack">
            <h2>Qué datos usamos</h2>
            <p>Tu nombre, celular, documento de identidad, fecha de nacimiento y correo; los de los familiares que tienes a cargo; tus citas y pagos; y tu historia clínica (antecedentes, odontograma, diagnósticos, tratamientos, radiografías, fotos clínicas y consentimientos). Los datos de salud son datos sensibles y se tratan con más cuidado.</p>
        </section>

        <section class="stack">
            <h2>Para qué los usamos</h2>
            <p>Para agendar y recordarte tus citas, atenderte, llevar tu historia clínica, emitir recetas y presupuestos y registrar tus pagos. Solo si lo aceptas, para enviarte promociones por WhatsApp; puedes darte de baja cuando quieras.</p>
        </section>

        <section class="stack">
            <h2>Quién los ve</h2>
            <p>Recepción ve tus datos de contacto y tus citas. Tu historia clínica solo la ven los odontólogos que te atienden. Cada consulta y cada cambio queda registrado con fecha y usuario, y nada se borra. No vendemos ni compartimos tus datos con terceros; se guardan en un servidor contratado por el consultorio con conexión cifrada y copias de seguridad.</p>
        </section>

        <section class="stack">
            <h2>Tu celular recordado</h2>
            <p>Cuando tocas el enlace que te enviamos por WhatsApp, guardamos en tu navegador una cookie que recuerda este celular para que no tengas que identificarte cada vez. Puedes quitarla con "Salir en este celular" en Mi cuenta.</p>
        </section>

        <section class="stack">
            <h2>Cuánto tiempo los guardamos</h2>
            <p>La historia clínica se conserva el tiempo que exigen las normas de salud. Los demás datos, mientras seas paciente o hasta que nos pidas cancelarlos, si la ley lo permite.</p>
        </section>

        <section class="stack">
            <h2>Tus derechos</h2>
            <p>Puedes pedir acceder a tus datos, corregirlos, cancelarlos u oponerte a su uso (derechos ARCO), y retirar tu consentimiento para promociones. Escríbenos{{ Phone::isValid($whatsapp) ? ' por WhatsApp al '.Phone::display($whatsapp) : '' }} o acércate al consultorio. Si no quedas conforme, puedes acudir a la Autoridad Nacional de Protección de Datos Personales.</p>
        </section>
    </article>
</x-site-layout>
