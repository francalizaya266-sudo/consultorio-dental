{{-- Lo que decía cada versión anterior de un texto de consentimiento, de la más reciente a la más antigua. --}}
<div style="display: grid; gap: .75rem; font-size: .9rem">
    <p style="color: var(--gray-600)">Lo que ya se firmó guarda su propio texto: cambiar el texto no toca los consentimientos firmados.</p>

    @foreach ($versions as $version)
        <details style="border: 1px solid var(--gray-200); border-radius: .5rem; padding: .6rem .8rem" @if ($loop->first) open @endif>
            <summary style="cursor: pointer; font-weight: 600">
                Versión {{ $version['number'] }}
                <span style="font-weight: 400; color: var(--gray-500)">· valió hasta el {{ $version['until'] }}, cuando la cambió {{ $version['by'] }}</span>
            </summary>
            <div style="white-space: pre-line; margin-top: .6rem">{{ $version['body'] }}</div>
        </details>
    @endforeach
</div>
