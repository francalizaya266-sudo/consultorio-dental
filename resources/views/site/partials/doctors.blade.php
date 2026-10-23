@php
    use App\Services\Web\PublicImages;
    use App\Support\Initials;
    use App\Support\Markdown;
    use Illuminate\Support\Str;
@endphp
{{-- full: con presentación, formación y tratamientos (página Equipo). --}}
<div @class(['team', 'full' => $full])>
    @foreach ($doctors as $doctor)
        <article class="doctor" id="{{ $doctor->slug }}">
            @if ($doctor->photo_path)
                <img src="{{ PublicImages::url($doctor->photo_path) }}" alt="{{ $doctor->name }}" class="portrait" loading="lazy" width="320" height="320">
            @else
                {{-- Sin foto: sus iniciales, nunca una foto de banco. --}}
                <span class="portrait monogram" aria-hidden="true">{{ Initials::of($doctor->name) }}</span>
            @endif
            <div class="who">
                <h3>{{ $doctor->name }}</h3>
                @if ($doctor->specialty)
                    <p class="specialty">{{ $doctor->specialty }}</p>
                @endif
                <p class="credentials">
                    @if ($doctor->cop_number)
                        COP {{ $doctor->cop_number }}
                    @endif
                    @if ($doctor->rne_number)
                        · RNE {{ $doctor->rne_number }}
                    @endif
                </p>
                @if ($full)
                    @if ($doctor->bio)
                        <div class="prose">{{ Markdown::paragraphs($doctor->bio) }}</div>
                    @endif
                    @if ($doctor->education)
                        <h4>Formación</h4>
                        <ul class="education">
                            @foreach (preg_split('/\R/', trim($doctor->education)) as $line)
                                @if (trim($line) !== '')
                                    <li>{{ trim($line) }}</li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                    @if ($doctor->treatments->isNotEmpty())
                        <h4>Atiende</h4>
                        <p class="treatments">
                            @foreach ($doctor->treatments as $treatment)
                                <a href="{{ route('site.treatment', $treatment->slug) }}">{{ $treatment->name }}</a>@if (! $loop->last), @endif
                            @endforeach
                        </p>
                    @endif
                @elseif ($doctor->bio)
                    <p class="bio">{{ Str::limit($doctor->bio, 160) }}</p>
                @endif
                @if ($doctor->accepts_online_booking)
                    <a href="{{ route('site.booking', ['doctor' => $doctor->slug]) }}" class="btn small quiet" aria-label="Reservar cita con {{ $doctor->name }}">Reservar cita</a>
                @endif
            </div>
        </article>
    @endforeach
</div>
