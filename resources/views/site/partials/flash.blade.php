{{-- El aviso de lo que se acaba de hacer ("La cita quedó confirmada") o de lo que salió mal. --}}
@if (session('status'))
    <div class="flash ok" role="status">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="flash bad" role="alert">{{ session('error') }}</div>
@endif
