{{-- Estilos propios del panel, en un solo archivo que el navegador guarda en caché (el hosting no compila CSS). --}}
<link rel="stylesheet" href="{{ asset('css/panel.css') }}?v={{ @filemtime(public_path('css/panel.css')) }}">
