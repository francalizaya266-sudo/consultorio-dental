<x-site-layout title="Entrar a Mi cuenta" :noindex="true">
    <section class="stack">
        <h1>Entrar a Mi cuenta</h1>
        <p class="lead">Toca el botón para ver tus citas. Este celular quedará recordado y la próxima vez entrarás directo.</p>
        <form method="POST" action="{{ route('patient.link.enter', $token) }}">
            @csrf
            <button type="submit" class="btn block">Entrar a Mi cuenta</button>
        </form>
        <p class="muted small">Si este no es tu celular (por ejemplo, uno prestado), no toques el botón: después puedes cerrar la sesión desde Mi cuenta.</p>
    </section>
</x-site-layout>
