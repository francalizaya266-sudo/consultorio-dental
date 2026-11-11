{{-- "Antes de la cita": a quien todavía no le conocemos la salud, el enlace a contarla en dos minutos. --}}
@php($self = $patient->relationship === \App\Enums\PatientRelationship::Self)
<a href="{{ route('site.account.health', $patient) }}" class="health-ask" data-health-ask>
    <strong>Antes de la cita</strong>
    <span>{{ $self ? 'Cuéntanos de tu salud' : 'Cuéntanos de la salud de '.$patient->first_name }} en dos minutos.</span>
</a>
