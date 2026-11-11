<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\Patient;
use App\Support\CampaignText;
use App\Support\Dates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Avisar una promoción por WhatsApp a quienes aceptaron recibirlas: un aviso por celular en Avisos → Promociones,
 * que recepción envía uno por uno como los demás. Nunca a un celular bloqueado ni dos veces la misma promoción.
 */
final class CampaignBroadcast
{
    public function __construct(private readonly WhatsAppOutbox $outbox) {}

    /** @return Builder<Account> Los celulares a los que todavía se les puede avisar esta promoción. */
    public function audience(Campaign $campaign): Builder
    {
        return Account::query()
            ->where('accepts_promotions', true)
            ->whereNull('blocked_at')
            ->whereDoesntHave('messages', fn (Builder $query) => $query
                ->where('template_key', MessageTemplateKey::CampaignAnnouncement)
                ->where('params->campaign_id', $campaign->getKey()));
    }

    /** Deja listo un aviso por celular y devuelve cuántos. */
    public function queue(Campaign $campaign): int
    {
        $params = [
            'campaign_id' => $campaign->getKey(),
            'promocion' => self::offer($campaign),
            'hasta' => Str::lower(Dates::day($campaign->ends_at)),
            'pagina' => route('site.promotion', $campaign->slug),
        ];
        $queued = 0;

        $this->audience($campaign)->with('patients')->chunkById(200, function (Collection $accounts) use ($params, &$queued) {
            foreach ($accounts as $account) {
                /** @var Account $account */
                // El titular primero; si no hay, alguien de la familia.
                $patient = $account->patients->sortBy(fn (Patient $patient) => $patient->relationship === PatientRelationship::Self ? 0 : 1)->first();
                $this->outbox->queueForAccount($account, MessageTemplateKey::CampaignAnnouncement, $params + ['nombre' => $patient?->first_name ?? ''], $patient?->getKey());
                $queued++;
            }
        });

        return $queued;
    }

    /** "Blanqueamiento: 20 % de descuento." y, debajo, el resumen de la promoción. */
    public static function offer(Campaign $campaign): string
    {
        $discount = CampaignText::discount($campaign);

        return collect([
            rtrim($campaign->name, '.').($discount ? ': '.Str::lcfirst($discount) : '').'.',
            $campaign->summary,
        ])->filter()->implode("\n");
    }
}
