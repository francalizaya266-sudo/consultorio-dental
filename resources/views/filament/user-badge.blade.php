{{-- Quién está usando el panel, a la vista junto a su foto: en un consultorio varias personas comparten la misma computadora. --}}
<div class="user-badge" aria-hidden="true">
    <strong>{{ $user->name }}</strong>
    <small>{{ $user->role->getLabel() }}</small>
</div>
