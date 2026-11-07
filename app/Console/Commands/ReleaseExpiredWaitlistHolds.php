<?php

namespace App\Console\Commands;

use App\Enums\WaitlistStatus;
use App\Models\WaitlistEntry;
use App\Services\Booking\WaitlistService;
use App\Services\StaffAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lista-espera:liberar-apartados')]
#[Description('Libera los horarios ofrecidos a la lista de espera que nadie confirmó a tiempo y avisa a recepción a quién ofrecérselos ahora')]
class ReleaseExpiredWaitlistHolds extends Command
{
    public function handle(WaitlistService $waitlist, StaffAlerts $alerts): int
    {
        $expired = WaitlistEntry::query()
            ->where('status', WaitlistStatus::Offered)
            ->whereNotNull('held_until')
            ->where('held_until', '<=', now())
            ->with(['patient', 'offeredDoctor'])
            ->get();

        foreach ($expired as $entry) {
            $waitlist->release($entry);

            if (! $entry->offeredDoctor || ! $entry->patient || ! $entry->offered_starts_at?->isFuture()) {
                continue;
            }

            $next = $waitlist->freedSlotNotice($entry->offeredDoctor, $entry->offered_starts_at, $entry->patient_id);

            $alerts->toReception(
                "{$entry->patient->full_name} no respondió al horario ofrecido",
                ucfirst($entry->offered_starts_at->translatedFormat('D j M, g:i a'))." con {$entry->offeredDoctor->name} ya no está apartado."
                    .($next ? ' '.$next['text'] : ' Queda libre para la agenda y la web.'),
                link: $next['link'] ?? null,
            );
        }

        $this->info("Horarios liberados: {$expired->count()}");

        return self::SUCCESS;
    }
}
