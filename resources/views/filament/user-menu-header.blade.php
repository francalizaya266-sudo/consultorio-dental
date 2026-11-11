{{-- Al abrir el menú del usuario: con qué cuenta se está trabajando (también en el celular, donde no entra junto a la foto). --}}
<div class="user-menu-who">
    <small>Sesión iniciada como</small>
    <strong>{{ $user->name }}</strong>
    <span>{{ $user->role->getLabel() }}</span>
    <span>{{ $user->email }}</span>
</div>
