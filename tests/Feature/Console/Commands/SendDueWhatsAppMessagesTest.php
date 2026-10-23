<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Models\Appointment;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class SendDueWhatsAppMessagesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_does_nothing_while_the_official_api_is_not_active(): void
    {
        Http::preventStrayRequests();
        $this->freezeMonday();
        $message = app(WhatsAppOutbox::class)->queueForAppointment(Appointment::factory()->create(), MessageTemplateKey::Reminder);

        $this->artisan('whatsapp:enviar')->assertSuccessful();

        $this->assertSame(MessageStatus::Pending, $message->fresh()->status);
    }

    public function test_sends_due_messages_through_the_cloud_api_when_active(): void
    {
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v21.0/123/messages' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        config([
            'consultorio.whatsapp.driver' => 'cloud_api',
            'consultorio.whatsapp.cloud_api.token' => 'token',
            'consultorio.whatsapp.cloud_api.phone_number_id' => '123',
        ]);
        $this->freezeMonday();
        $message = app(WhatsAppOutbox::class)->queueForAppointment(Appointment::factory()->create(), MessageTemplateKey::Reminder);

        $this->artisan('whatsapp:enviar')->assertSuccessful();

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertSame('wamid.1', $message->fresh()->provider_message_id);
        Http::assertSent(fn ($request) => $request['to'] === ltrim($message->phone, '+') && $request['type'] === 'text');
    }
}
