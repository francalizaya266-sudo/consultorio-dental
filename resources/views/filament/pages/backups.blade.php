<x-filament-panels::page>
    @if ($stale)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">No hay una copia de las últimas horas</x-slot>
            Las copias se crean solas cada madrugada y hoy no se creó. Por ahora toca "Crear copia ahora", y avisa a quien
            te ayuda con el hosting: la tarea automática del servidor no está corriendo.
        </x-filament::section>
    @endif

    @if ($due)
        <x-filament::section icon="heroicon-o-arrow-down-tray" icon-color="warning">
            <x-slot name="heading">Toca la descarga de esta semana</x-slot>
            {{ $due }}. Descarga aquí abajo lo que falta y guárdalo fuera del sistema, por ejemplo en el Google Drive del consultorio.
        </x-filament::section>
    @endif

    <x-filament::section heading="Copia de los datos" :description="'Pacientes, citas, historias y pagos. Se crea una cada día a las 3:00 a. m. y se guardan las últimas '.$keep.'.'">
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
                        {{-- La descarga es un enlace normal: la página se vuelve a leer sola para mostrar que ya se bajó. --}}
                        <x-filament::button tag="a" :href="$backup['url']" :color="$loop->first ? 'primary' : 'gray'" size="sm" icon="heroicon-o-arrow-down-tray" x-on:click="[3000, 15000].forEach((wait) => setTimeout(() => $wire.$refresh(), wait))">
                            Descargar
                        </x-filament::button>
                    </div>
                @endforeach
            </div>

            <p class="backup-meta backup-note">
                {{ $databaseDownloaded ? 'Última descarga: '.$databaseDownloaded.'.' : 'Todavía no se ha descargado ninguna.' }}
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Radiografías, fotos, documentos y firmas" description="Son archivos aparte de los datos. Cada descarga trae solo lo nuevo desde la anterior, en un archivo .zip.">
        @if ($pending['parts'] === [])
            <p class="backup-meta">No hay archivos nuevos por descargar.</p>
        @else
            <div class="backup-list">
                @foreach ($pending['parts'] as $part)
                    <div class="backup-row">
                        <div>
                            <div class="backup-date">Archivos nuevos{{ $part['of'] > 1 ? ', parte '.$part['number'].' de '.$part['of'] : '' }}</div>
                            <div class="backup-meta">{{ $part['summary'] }}{{ $part['done'] ? ' · Ya descargada' : '' }}</div>
                        </div>
                        <x-filament::button tag="a" :href="$part['url']" :color="$part['done'] ? 'gray' : 'primary'" size="sm" icon="heroicon-o-arrow-down-tray" x-on:click="[3000, 15000].forEach((wait) => setTimeout(() => $wire.$refresh(), wait))">
                            Descargar
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($last)
            <div class="backup-note">
                <p class="backup-meta">
                    Última descarga: {{ $last['when'] }} ({{ $last['summary'] }}). Si ese archivo se perdió, vuelve a bajarlo:
                    @foreach ($last['parts'] as $part)
                        <x-filament::link :href="$part['url']" size="sm">{{ $part['of'] > 1 ? 'Parte '.$part['number'] : 'Descargar de nuevo' }}</x-filament::link>
                    @endforeach
                </p>
                <p class="backup-meta">
                    Para una computadora nueva, todos los archivos desde el principio ({{ $everything['summary'] }}):
                    @foreach ($everything['parts'] as $part)
                        <x-filament::link :href="$part['url']" size="sm">{{ $part['of'] > 1 ? 'Parte '.$part['number'] : 'Descargar todos' }}</x-filament::link>
                    @endforeach
                </p>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Para que sirva de verdad" collapsible>
        <div class="backup-help">
            <p>Una copia que queda en el mismo hosting no sirve si el hosting falla. Una vez por semana descarga la copia de los datos más reciente y los archivos nuevos, y guárdalos en otro lugar seguro (por ejemplo, la cuenta de Google Drive del consultorio). Si pasa una semana sin hacerlo, el sistema lo recuerda en "Hoy".</p>
            <p>Tienen datos clínicos de los pacientes: no los compartas ni los envíes por WhatsApp.</p>
            <p>La copia de los datos trae pacientes, citas, historias y pagos. Las radiografías, fotos, documentos y firmas se bajan aparte, aquí mismo. Guarda todas esas descargas: cada una trae solo lo nuevo.</p>
            <p>Si alguna vez hay que recuperar los datos, quien te ayuda con el hosting lo hace con estos archivos.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
