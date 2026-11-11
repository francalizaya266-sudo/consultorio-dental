<?php

namespace Tests\Feature\Filament;

use App\Enums\DiscountType;
use App\Enums\MessageTemplateKey;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\WhatsappMessages\Pages\ListWhatsappMessages;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\Patient;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\CampaignBroadcast;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignAnnouncementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::query()->create($attributes + [
            'name' => 'Blanqueamiento de verano',
            'slug' => 'blanqueamiento-verano',
            'summary' => 'Una sesión en consultorio con evaluación incluida.',
            'discount_type' => DiscountType::Percent,
            'discount_value' => 20,
            'starts_at' => now()->subDay(),
            'ends_at' => '2026-12-31 23:59',
            'is_active' => true,
        ]);
    }

    public function test_a_published_campaign_is_announced_only_to_who_accepts_promotions_and_apart_from_appointment_notices(): void
    {
        $this->travelTo('2026-11-11 10:00');
        $campaign = $this->campaign();
        $accepts = Account::factory()->verified()->create(['accepts_promotions' => true]);
        Patient::factory()->for($accepts)->create(['first_name' => 'Rosa']);
        Account::factory()->verified()->create(['accepts_promotions' => false]);
        Account::factory()->verified()->blocked()->create(['accepts_promotions' => true]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->assertActionVisible('copyLink')
            ->assertActionHasUrl('viewOnSite', route('site.promotion', 'blanqueamiento-verano'))
            ->mountAction('announce')
            ->assertMountedActionModalSee('Se prepara 1 aviso, uno por cada celular que aceptó recibir promociones.')
            ->callMountedAction()
            ->assertNotified('1 aviso listo para enviar');

        $message = WhatsappMessage::sole();
        $this->assertTrue($message->account->is($accepts));
        $this->assertSame(MessageTemplateKey::CampaignAnnouncement, $message->template_key);
        $text = app(WhatsAppOutbox::class)->draft($message);
        $this->assertStringContainsString('Hola Rosa', $text);
        $this->assertStringContainsString("Blanqueamiento de verano: 20\u{00A0}% de descuento.\nUna sesión en consultorio con evaluación incluida.\nVale hasta el jueves 31 de diciembre.", $text);
        $this->assertStringContainsString(route('site.promotion', 'blanqueamiento-verano'), $text);
        $this->assertStringContainsString('respóndenos «no»', $text);

        // No se le avisa dos veces la misma promoción.
        $this->assertSame(0, app(CampaignBroadcast::class)->audience($campaign)->count());

        // Va aparte: no se mezcla con lo de las citas ni suma al contador de Avisos.
        $this->assertSame(0, WhatsappMessage::query()->due()->count());
        Livewire::test(ListWhatsappMessages::class)
            ->set('activeTab', 'promotions')
            ->assertCanSeeTableRecords([$message]);
    }

    public function test_a_paused_or_finished_campaign_is_not_announced(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach ([$this->campaign(['is_active' => false]), $this->campaign(['slug' => 'pasada', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()])] as $campaign) {
            Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
                ->assertActionHidden('announce')
                ->assertActionHidden('copyLink');
        }
    }
}
