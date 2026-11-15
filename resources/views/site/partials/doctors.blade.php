@php
    use App\Services\Web\PublicImages;
    use App\Support\Initials;
    use App\Support\Markdown;
    use App\Support\SiteIcon;
    use Illuminate\Support\Str;
@endphp
{{--
    full: cada doctor en su tarjeta, con presentación, formación y lo que atiende (página Equipo).
    El botón de cada doctor sirve para elegir con quién atenderse; con un solo doctor repetiría el "Reservar cita"
    que la página ya tiene, así que fuera de Equipo solo sale cuando hay de dónde elegir.
--}}
@php($offerBooking = $doctors->count() > 1)
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
                @if (! $full && $doctor->bio)
                    <p class="bio">{{ Str::limit($doctor->bio, 160) }}</p>
                @endif
                @if (! $full && $offerBooking && $doctor->accepts_online_booking)
                    <a href="{{ route('site.booking', ['doctor' => $doctor->slug]) }}" class="btn small quiet" aria-label="Reservar cita con {{ $doctor->name }}">Reservar cita</a>
                @endif
            </div>
            {{-- En Equipo, bajo el nombre: su presentación y el botón para atenderse con él; al lado, su formación y lo que atiende. --}}
            @if ($full)
                <div class="about">
                    @if ($doctor->bio)
                        <div class="prose">{{ Markdown::paragraphs($doctor->bio) }}</div>
                    @endif
                    @if ($doctor->accepts_online_booking)
                        <a href="{{ route('site.booking', ['doctor' => $doctor->slug]) }}" class="btn small" aria-label="Reservar cita con {{ $doctor->name }}">{{ SiteIcon::svg('calendar') }}Reservar cita</a>
                    @endif
                </div>
                @if ($doctor->education || $doctor->treatments->isNotEmpty())
                    <div class="more">
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
                                    <a href="{{ route('site.treatment', $treatment->slug) }}">{{ $treatment->name }}</a>
                                @endforeach
                            </p>
                        @endif
                    </div>
                @endif
            @endif
        </article>
    @endforeach
</div>
