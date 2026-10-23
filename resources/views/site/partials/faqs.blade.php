@php
    use App\Support\Markdown;
@endphp
<div class="faqs">
    @foreach ($faqs as $faq)
        <details>
            <summary>{{ $faq->question }}</summary>
            <div class="prose">{{ Markdown::paragraphs($faq->answer) }}</div>
        </details>
    @endforeach
</div>
