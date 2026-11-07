{{-- Estilos y scripts propios del panel, en archivos que el navegador guarda en caché (el hosting no compila CSS ni JS). --}}
<link rel="stylesheet" href="{{ asset('css/panel.css') }}?v={{ @filemtime(public_path('css/panel.css')) }}">
{{-- El odontograma registra su componente de Alpine: va antes que Alpine, sin defer. --}}
<script src="{{ asset('js/odontogram.js') }}?v={{ @filemtime(public_path('js/odontogram.js')) }}"></script>
{{-- Safari del iPhone solo muestra el estado "tocado" (:active) si la página escucha los toques. --}}
<script>document.addEventListener('touchstart', function () {}, { passive: true });</script>
