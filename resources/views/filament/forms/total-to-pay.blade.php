{{-- Lo que se cobra ahora, en grande: es el número que recepción le dice al paciente. --}}
<div class="pay-total" role="status">
    <span class="pay-total-label">Total a pagar</span>
    <strong class="pay-total-value">{{ $total }}</strong>
    @if ($note)
        <small class="pay-total-note">{{ $note }}</small>
    @endif
</div>
