<x-filament-panels::page>
    <style>
        .backup-list { display: grid; gap: 0; }
        .backup-row { display: flex; flex-wrap: wrap; gap: .25rem 1rem; align-items: center; justify-content: space-between; padding: .75rem 0; border-bottom: 1px solid var(--gray-200); }
        .backup-row:last-child { border-bottom: 0; }
        .dark .backup-row { border-color: rgb(255 255 255 / .08); }
        .backup-date { font-weight: 600; color: var(--gray-950); }
        .dark .backup-date { color: #fff; }
        .backup-meta { font-size: .8rem; color: var(--gray-500); }
        .backup-help { font-size: .875rem; color: var(--gray-600); display: grid; gap: .5rem; }
        .dark .backup-help { color: var(--gray-400); }
    </style>

    @if ($stale)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">No hay una copia de las últimas horas</x-slot>
            Las copias se crean solas cada madrugada con el cron del hosting. Si no aparecen, revisa que el cron esté activo
            (ver "Publicación en hosting compartido" en el README) y mientras tanto usa "Crear copia ahora".
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
            <p>Esta copia es de la base de datos. Las radiografías, fotos y firmas están en la carpeta <code>storage/app/private/clinica</code> y las fotos de la web en <code>public/media</code>: inclúyelas en la copia de archivos del hosting.</p>
            <p>Para restaurar, quien administre el hosting importa el archivo en phpMyAdmin o ejecuta <code>php artisan respaldo:restaurar</code>.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
