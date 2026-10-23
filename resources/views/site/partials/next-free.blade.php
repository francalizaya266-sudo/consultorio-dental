@php
    use App\Support\Dates;

    $headingLevel ??= 'h2';
@endphp
{{-- Los próximos horarios libres de verdad, sacados de la agenda: un toque y queda elegido. --}}
<div class="next-free" aria-labelledby="proximos">
    <{{ $headingLevel }} id="proximos">Próximo horario libre</{{ $headingLevel }}>
    <p class="when">{{ Dates::relativeDay($nextFree['date'], withDate: true) }}</p>
    <p class="for">{{ $treatment->name }} · {{ $treatment->duration_minutes }} min</p>
    <ul class="free-times">
        @foreach ($nextFree['times'] as $time)
            <li><a href="{{ route('site.booking', ['tratamiento' => $treatment->slug, 'fecha' => $nextFree['date'], 'hora' => $time]) }}">{{ Dates::time($time) }}</a></li>
        @endforeach
    </ul>
    <a href="{{ route('site.booking', ['tratamiento' => $treatment->slug]) }}" class="more">Ver otros días y horarios</a>
</div>
