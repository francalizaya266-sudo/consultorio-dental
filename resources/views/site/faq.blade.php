@php
    use App\Support\SiteArt;
    use App\Support\SiteIcon;
@endphp
{{-- Quien no encuentra su duda tiene el WhatsApp en la franja que cierra la página. --}}
<x-site-layout page="preguntas" :wide="true" :json-ld="$jsonLd" description="Respuestas sobre citas, pagos, tratamientos y cómo llegar.">
    @if ($topics->isEmpty())
        <p class="panel">Pronto publicaremos las preguntas más comunes.</p>
    @else
        {{--
            El buscador y los temas dejan a la vista solo lo que se busca. Sin JavaScript no hay buscador:
            salen todas las preguntas y cada tema lleva a las suyas.
        --}}
        <div @class(['faq-page', 'with-topics' => $topics->count() > 1])>
            <div class="faq-search" role="search">
                {{ SiteIcon::svg('search') }}
                <label for="buscar-pregunta" class="sr-only">Buscar una pregunta</label>
                <input type="search" id="buscar-pregunta" placeholder="Buscar una pregunta…" autocomplete="off" enterkeyhint="search">
            </div>

            @if ($topics->count() > 1)
                <nav class="faq-topics" aria-labelledby="temas">
                    <h2 id="temas">Temas</h2>
                    <ul>
                        <li><a href="#preguntas" data-topic="" aria-current="true"><span class="topic-icon">{{ SiteIcon::svg('chat-check') }}</span>Todas</a></li>
                        @foreach ($topics as $topic)
                            <li><a href="#{{ $topic['id'] }}" data-topic="{{ $topic['id'] }}"><span class="topic-icon">{{ $topic['treatment'] ? SiteArt::treatment($topic['treatment'], 32) : SiteIcon::svg('question') }}</span>{{ $topic['name'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <div class="faq-list" id="preguntas">
                @foreach ($topics as $topic)
                    {{-- Con un solo tema, su título sobra: las preguntas van directo. --}}
                    <section class="faq-group" id="{{ $topic['id'] }}" aria-label="{{ $topic['name'] }}">
                        @if ($topics->count() > 1)
                            <h2>@if ($topic['treatment'])<a href="{{ route('site.treatment', $topic['treatment']->slug) }}">{{ $topic['name'] }}</a>@else{{ $topic['name'] }}@endif</h2>
                        @endif
                        @include('site.partials.faqs', ['faqs' => $topic['faqs']])
                    </section>
                @endforeach
                <p class="panel faq-none" role="status" hidden>No encontramos preguntas con esas palabras. Prueba con otras.</p>
            </div>
        </div>

        <script>
            // El buscador y los temas: uno a la vez. Escribir busca en todas las preguntas; elegir un tema limpia lo escrito.
            (function () {
                var page = document.querySelector('.faq-page');
                var input = page.querySelector('.faq-search input');
                var links = page.querySelectorAll('.faq-topics a');
                var none = page.querySelector('.faq-none');
                var plain = function (text) { return text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

                function show(topic) {
                    var words = plain(input.value).split(/\s+/).filter(Boolean);
                    var found = 0;

                    page.querySelectorAll('.faq-group').forEach(function (group) {
                        var shown = 0;

                        group.querySelectorAll('details').forEach(function (question) {
                            var text = plain(question.textContent);
                            var matches = (!topic || group.id === topic) && words.every(function (word) { return text.indexOf(word) !== -1; });

                            question.hidden = !matches;
                            shown += matches ? 1 : 0;
                        });
                        group.hidden = shown === 0;
                        found += shown;
                    });
                    none.hidden = found > 0;
                    links.forEach(function (link) {
                        if (link.dataset.topic === topic) { link.setAttribute('aria-current', 'true'); } else { link.removeAttribute('aria-current'); }
                    });
                }

                input.addEventListener('input', function () { show(''); });
                links.forEach(function (link) {
                    link.addEventListener('click', function (event) {
                        event.preventDefault();
                        input.value = '';
                        show(link.dataset.topic);
                    });
                });
            })();
        </script>
    @endif
</x-site-layout>
