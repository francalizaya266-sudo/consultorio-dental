{{-- Lo que pasó con la cita, de lo primero a lo último: cambios, avisos de WhatsApp y pagos. --}}
<ol class="ap-history">
    @foreach ($rows as $row)
        <li class="ap-history-row is-{{ $row['tone'] }}">
            <span class="ap-history-dot" aria-hidden="true"></span>
            <span class="ap-history-text">
                <b>{{ $row['label'] }}</b>
                <small>{{ $row['meta'] }}</small>
            </span>
        </li>
    @endforeach
</ol>
