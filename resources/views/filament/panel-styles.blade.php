{{-- Ajustes de estilo del panel que el tema compilado de Filament no trae (el hosting no compila CSS). --}}
<style>
    /* En pantallas táctiles, los botones de cada fila se tocan con el dedo sin errar. */
    @media (pointer: coarse) {
        .fi-ta-actions { flex-wrap: wrap; gap: .5rem; }
        .fi-ta-actions > .fi-link,
        .fi-ta-actions > .fi-dropdown .fi-icon-btn,
        .fi-ta-actions > .fi-icon-btn { min-height: 2.75rem; min-width: 2.75rem; padding: .5rem .85rem; border-radius: .6rem; background: var(--gray-100); justify-content: center; }
        .dark .fi-ta-actions > .fi-link,
        .dark .fi-ta-actions > .fi-dropdown .fi-icon-btn,
        .dark .fi-ta-actions > .fi-icon-btn { background: rgb(255 255 255 / .07); }
        .fi-modal-footer-actions .fi-btn { min-height: 2.75rem; }
        /* "Ctrl + K" no sirve sin teclado y le quita espacio al buscador. */
        .fi-global-search-field .fi-input-wrp-suffix { display: none; }
    }

    /* "Hoy": cada cita en una o dos líneas. */
    .today-line { font-weight: 600; color: var(--gray-950); }
    .dark .today-line { color: #fff; }
    .today-line time { font-variant-numeric: tabular-nums; }
</style>
