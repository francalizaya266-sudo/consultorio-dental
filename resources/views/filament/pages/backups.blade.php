<x-filament-panels::page>
    @if ($stale)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">No hay una copia de las últimas horas</x-slot>
            Las copias se crean solas cada madrugada y hoy no se creó. Por ahora toca "Crear copia ahora", y avisa a quien
            te ayuda con el hosting: la tarea automática del servidor no está corriendo.
        </x-filament::section>
    @endif

    <x-filament::section heading="Copias guardadas" :description="'Se crea una cada día a las 3:00 a. m. y se guardan las últimas '.$keep.'.'">
        @if ($backups->isEmpty())
            <p class="backup-meta">Aún no hay copias.</p>
        @else
            <div class="backup-list">
                @foreach ($backups as $backup)
                    <div class="backup-row">
                        <div>
                            <div class="backup-date">{{ $backup['date'] }}</div>
                            <div class="backup-meta">{{ $backup['ago'] }} · {{ $backup['size'] }}</div>
                        </div>
                        <x-filament::button tag="a" :href="$backup['url']" color="gray" size="sm" icon="heroicon-o-arrow-down-tray">
                            Descargar
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Para que sirva de verdad" collapsible>
        <div class="backup-help">
            <p>Una copia que queda en el mismo hosting no sirve si el hosting falla. Una vez por semana, descarga la más reciente y guárdala en otro lugar seguro (por ejemplo, la cuenta de Google Drive del consultorio).</p>
            <p>Tiene datos clínicos de los pacientes: no la compartas ni la envíes por WhatsApp.</p>
            <p>Guarda los datos: pacientes, citas, historias y pagos. Las radiografías, fotos y firmas son archivos aparte: pide a quien te ayuda con el hosting que también los copie.</p>
            <p>Si alguna vez hay que recuperar los datos, esa persona lo hace con este archivo.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
