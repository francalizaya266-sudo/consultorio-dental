<div class="quotes">
    @foreach ($reviews as $review)
        <figure class="quote">
            <p class="stars" aria-label="{{ $review->rating }} de 5 estrellas">{{ str_repeat('★', $review->rating) }}<span class="off">{{ str_repeat('★', 5 - $review->rating) }}</span></p>
            <blockquote>{{ $review->body }}</blockquote>
            <figcaption>
                {{ $review->shortName() }}
                <span>
                    @if ($review->url)
                        <a href="{{ $review->url }}" rel="noopener nofollow" target="_blank">en {{ $review->source->getLabel() }}</a>
                    @else
                        en {{ $review->source->getLabel() }}
                    @endif
                    @if ($review->reviewed_on)
                        · {{ $review->reviewed_on->translatedFormat('F Y') }}
                    @endif
                </span>
            </figcaption>
        </figure>
    @endforeach
</div>
