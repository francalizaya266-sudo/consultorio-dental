{{-- Miniaturas de las radiografías y fotos de la atención; cada una se abre en tamaño completo. --}}
@if ($files->isEmpty())
    <p style="font-size: .85rem; color: var(--gray-500)">Todavía no hay radiografías ni fotos de esta atención.</p>
@else
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr)); gap: .75rem">
        @foreach ($files as $file)
            <a href="{{ $file->url() }}" target="_blank" rel="noopener" style="display: block; text-decoration: none; color: inherit">
                @if ($file->isImage())
                    <img src="{{ $file->url(thumbnail: true) }}" alt="{{ $file->title }}" loading="lazy" style="display: block; width: 100%; height: 6.5rem; object-fit: cover; border-radius: .5rem; background: #0f172a">
                @else
                    <span style="display: flex; align-items: center; justify-content: center; height: 6.5rem; border-radius: .5rem; background: var(--gray-100); font-weight: 700; color: var(--gray-600)">PDF</span>
                @endif
                <span style="display: block; margin-top: .3rem; font-size: .78rem; line-height: 1.2; color: var(--gray-700); overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $file->type->getLabel() }} · {{ $file->title }}</span>
            </a>
        @endforeach
    </div>
@endif
