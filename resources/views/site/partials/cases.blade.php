@php
    use App\Services\Web\PublicImages;
    use App\Support\Markdown;
@endphp
<div class="cases">
    @foreach ($cases as $case)
        <figure class="case">
            <div class="pair">
                <div>
                    <img src="{{ PublicImages::url($case->before_path) }}" alt="Antes: {{ $case->title }}" loading="lazy">
                    <span class="tag">Antes</span>
                </div>
                <div>
                    <img src="{{ PublicImages::url($case->after_path) }}" alt="Después: {{ $case->title }}" loading="lazy">
                    <span class="tag">Después</span>
                </div>
            </div>
            <figcaption>
                <strong>{{ $case->title }}</strong>
                @if ($case->treatment)
                    <a href="{{ route('site.treatment', $case->treatment->slug) }}">{{ $case->treatment->name }}</a>
                @endif
                @if (! empty($withDescription) && $case->description)
                    <div class="prose">{{ Markdown::paragraphs($case->description) }}</div>
                @endif
            </figcaption>
        </figure>
    @endforeach
</div>
