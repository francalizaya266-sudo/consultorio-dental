<?php

namespace Tests\Feature\Services\WhatsApp;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Models\Appointment;
use App\Models\MagicLink;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class WhatsAppOutboxTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_click_to_chat_opens_the_clinic_whatsapp_with_the_message_written(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $appointment = Appointment::factory()->at('2026-11-04 10:30')->create();
        $appointment->patient->account->update(['phone' => '+51987654321']);
        $appointment->patient->update(['first_name' => 'María']);
        $outbox = app(WhatsAppOutbox::class);
        $message = $outbox->queueForAppointment($appointment, MessageTemplateKey::BookingConfirmation);

        $url = $outbox->clickToChatUrl($message, $reception);

        $this->assertStringStartsWith('https://wa.me/51987654321?text=', $url);
        $text = rawurldecode(substr($url, strpos($url, '=') + 1));
        $this->assertStringContainsString('Hola María', $text);
        $this->assertStringContainsString('miércoles 4 de noviembre a las 10:30 am', $text);
        $this->assertMatchesRegularExpression('#/c/[A-Za-z0-9]{32}#', $text, 'Lleva el enlace personal.');
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertTrue($message->fresh()->sentBy->is($reception));
    }

    public function test_the_booking_confirmation_says_the_price_only_if_the_treatment_shows_it(): void
    {
        $this->freezeMonday();
        $appointment = Appointment::factory()->at('2026-11-04 10:30')->create();
        $appointment->treatment->update(['price_from' => 150]);
        $outbox = app(WhatsAppOutbox::class);

        $withPrice = $outbox->render($outbox->queueForAppointment($appointment, MessageTemplateKey::BookingConfirmation));
        $this->assertStringContainsString(".\nPrecio: desde S/ 150. Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.\n\nConfirma", $withPrice);

        $appointment->treatment->update(['show_price' => false]);
        $withoutPrice = $outbox->render($outbox->queueForAppointment($appointment->fresh(), MessageTemplateKey::BookingConfirmation));
        $this->assertStringNotContainsString('Precio', $withoutPrice);
        $this->assertStringContainsString("10:30 am con {$appointment->doctor->name}.\n\nConfirma tu asistencia", $withoutPrice);
    }

    public function test_uses_the_text_edited_by_the_clinic(): void
    {
        $this->freezeMonday();
        MessageTemplate::create(['key' => MessageTemplateKey::Reminder, 'name' => 'Recordatorio', 'body' => 'Mañana a las {hora}, {nombre}.']);
        $appointment = Appointment::factory()->at('2026-11-03 16:00')->create();
        $appointment->patient->update(['first_name' => 'Jorge']);
        $outbox = app(WhatsAppOutbox::class);

        $body = $outbox->render($outbox->queueForAppointment($appointment, MessageTemplateKey::Reminder));

        $this->assertSame('Mañana a las 4:00 pm, Jorge.', $body);
        $this->assertSame(0, MagicLink::count(), 'Sin {enlace} no se genera enlace.');
    }

    public function test_keeps_only_the_latest_pending_notice_of_each_kind(): void
    {
        $this->freezeMonday();
        $appointment = Appointment::factory()->create();
        $outbox = app(WhatsAppOutbox::class);

        $first = $outbox->queueForAppointment($appointment, MessageTemplateKey::Rescheduled);
        $outbox->queueForAppointment($appointment, MessageTemplateKey::Rescheduled);

        $this->assertSame(MessageStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(1, $appointment->messages()->pending()->count());
    }

    public function test_skips_patients_without_a_phone(): void
    {
        $this->freezeMonday();
        $appointment = Appointment::factory()->create();
        $appointment->patient->update(['account_id' => null]);

        $message = app(WhatsAppOutbox::class)->queueForAppointment($appointment->fresh(), MessageTemplateKey::Reminder);

        $this->assertNull($message);
    }
}
