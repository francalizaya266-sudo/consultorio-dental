<?php

namespace Tests\Feature\Livewire\Site;

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\DiscountType;
use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Livewire\Site\BookingWizard;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Patient;
use App\Models\RememberedDevice;
use App\Models\Treatment;
use App\Models\WhatsappMessage;
use App\Services\Access\DeviceService;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class BookingWizardTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_a_new_patient_books_in_three_steps_with_spanish_errors(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $endodontics = Treatment::factory()->clinicOnly()->create(['name' => 'Endodoncia']);
        $doctor = $this->doctorOffering($evaluation, $cleaning, $endodontics);

        Livewire::test(BookingWizard::class)
            ->assertSee('No sé qué necesito: evaluación')
            ->assertSee('Limpieza dental')
            ->assertDontSee('Endodoncia')
            ->call('chooseTreatment', $evaluation->id)
            ->assertSet('step', 2)
            // Con 2 horas de anticipación mínima, lo primero es hoy a las 10:00.
            ->assertSet('date', '2026-11-02')
            ->assertSee('10:00')
            ->call('chooseTime', '10:00')
            ->assertSet('step', 3)
            ->call('book')
            ->assertHasErrors(['phone', 'firstName', 'lastName', 'acceptsPrivacy'])
            ->assertSee('Para reservar, acepta la política de privacidad.')
            ->set('phone', '98765')
            ->call('book')
            ->assertSee('Escribe tu celular de 9 dígitos, por ejemplo 987 654 321.')
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe Rojas')
            ->set('acceptsPrivacy', true)
            ->call('book')
            ->assertHasNoErrors()
            ->assertSet('step', 4)
            ->assertSee('¡Tu cita quedó reservada!')
            ->assertSee('987 654 321');

        $appointment = Appointment::with('patient.account')->sole();
        $this->assertTrue($appointment->doctor->is($doctor));
        $this->assertSame('2026-11-02 10:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame(BookingChannel::Web, $appointment->booked_via);
        $this->assertSame('María', $appointment->patient->first_name);
        $this->assertSame('+51987654321', $appointment->patient->account->phone);
        $this->assertSame(PatientRelationship::Self, $appointment->patient->relationship);
    }

    public function test_the_first_available_doctor_takes_the_time_and_links_can_preselect_a_doctor(): void
    {
        $this->freezeMonday();
        $cleaning = Treatment::factory()->create(['duration_minutes' => 30]);
        $busy = $this->doctorOffering($cleaning);
        $free = $this->doctorOffering($cleaning);
        Appointment::factory()->for($busy)->at('2026-11-02 10:00')->create();

        Livewire::withQueryParams(['tratamiento' => $cleaning->slug])
            ->test(BookingWizard::class)
            ->assertSet('step', 2)
            ->assertSet('doctorId', null)
            ->call('chooseTime', '10:00')
            ->set('phone', '987 654 321')
            ->set('firstName', 'Rosa')
            ->set('lastName', 'Huamán')
            ->set('acceptsPrivacy', true)
            ->call('book')
            ->assertSet('step', 4);

        $this->assertTrue(Appointment::latest('id')->first()->doctor->is($free));

        // Desde la ficha de un doctor: solo sus horarios (a las 10:00 ya no tiene).
        Livewire::withQueryParams(['tratamiento' => $cleaning->slug, 'doctor' => $busy->slug])
            ->test(BookingWizard::class)
            ->assertSet('doctorId', $busy->id)
            ->call('chooseTime', '10:00')
            ->assertSet('step', 2);
    }

    public function test_a_free_time_tapped_on_the_website_arrives_already_chosen(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($evaluation);
        Appointment::factory()->for($doctor)->at('2026-11-03 11:00')->create();

        Livewire::withQueryParams(['tratamiento' => $evaluation->slug, 'fecha' => '2026-11-03', 'hora' => '10:00'])
            ->test(BookingWizard::class)
            ->assertSet('step', 3)
            ->assertSet('date', '2026-11-03')
            ->assertSet('time', '10:00');

        // Si ya se ocupó, queda en ese día para elegir otra hora.
        Livewire::withQueryParams(['tratamiento' => $evaluation->slug, 'fecha' => '2026-11-03', 'hora' => '11:00'])
            ->test(BookingWizard::class)
            ->assertSet('step', 2)
            ->assertSet('date', '2026-11-03')
            ->assertSet('time', null);

        // Un día sin atención (domingo) no se impone: se ofrece el primero libre.
        Livewire::withQueryParams(['tratamiento' => $evaluation->slug, 'fecha' => '2026-11-08', 'hora' => '10:00'])
            ->test(BookingWizard::class)
            ->assertSet('step', 2)
            ->assertSet('date', '2026-11-02');
    }

    public function test_arriving_from_a_campaign_shows_its_price_books_with_it_and_the_whatsapp_says_it(): void
    {
        $this->freezeMonday();
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 150]);
        $evaluation = Treatment::factory()->evaluation()->create(['price_from' => 50]);
        $this->doctorOffering($cleaning, $evaluation);
        $campaign = Campaign::query()->create(['name' => 'Limpieza de verano', 'slug' => 'limpieza-verano', 'treatment_id' => $cleaning->id, 'discount_type' => DiscountType::Amount, 'discount_value' => 30, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

        Livewire::withQueryParams(['promo' => 'limpieza-verano'])
            ->test(BookingWizard::class)
            ->assertSet('step', 2)
            ->assertSet('treatmentId', $cleaning->id)
            ->call('back')
            ->assertSeeHtml('<strong>Limpieza dental</strong>')
            ->assertSeeHtml('<span class="price"><s>S/ 150</s> desde S/ 120</span>')
            ->call('chooseTreatment', $cleaning->id)
            ->call('chooseTime', '10:00')
            ->assertSee('Precio con la campaña Limpieza de verano: desde S/ 120 (normal desde S/ 150).')
            ->assertSee('No se cobra nada ahora. Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.')
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe')
            ->set('acceptsPrivacy', true)
            ->call('book')
            ->assertSet('step', 4)
            ->assertSee('Precio con la campaña Limpieza de verano: desde S/ 120 (normal desde S/ 150). Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.');

        $this->assertTrue(Appointment::sole()->campaign->is($campaign));

        $confirmation = app(WhatsAppOutbox::class)->render(WhatsappMessage::where('template_key', MessageTemplateKey::BookingConfirmation)->sole());
        $this->assertStringContainsString("a las 10:00 am con {$cleaning->doctors->first()->name}.\nPrecio con la campaña Limpieza de verano: desde S/ 120 (normal desde S/ 150). Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.\n\nConfirma tu asistencia", $confirmation);
    }

    public function test_a_time_taken_meanwhile_sends_the_patient_back_to_choose_another(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($evaluation);

        $wizard = Livewire::test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe')
            ->set('acceptsPrivacy', true);

        Appointment::factory()->for($doctor)->at('2026-11-02 10:00')->create();

        $wizard->call('book')
            ->assertSet('step', 2)
            ->assertSet('time', null)
            ->assertSee('Ese horario se acaba de ocupar. Por favor elige otro.');

        $this->assertSame(1, Appointment::count());
    }

    public function test_a_remembered_phone_books_for_the_family_without_typing_the_number(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);
        $account = Account::factory()->verified()->create(['phone' => '+51987654321']);
        $mother = Patient::factory()->for($account)->create(['first_name' => 'Rosa']);
        $child = Patient::factory()->dependentOf($account)->create(['first_name' => 'Mateo']);
        RememberedDevice::create(['account_id' => $account->id, 'token_hash' => hash('sha256', 'celular-de-rosa'), 'expires_at' => now()->addMonth()]);

        Livewire::withCookie(DeviceService::COOKIE, 'celular-de-rosa')
            ->test(BookingWizard::class)
            ->assertSet('patientId', $mother->id)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->assertSee('¿Para quién es la cita?')
            ->assertSee('Mateo')
            ->assertDontSee('Tu celular')
            ->call('choosePatient', Patient::factory()->create()->id)
            ->assertSet('patientId', $mother->id)
            ->call('choosePatient', $child->id)
            ->call('book')
            ->assertHasNoErrors()
            ->assertSet('step', 4)
            ->assertSee('Mi cuenta');

        $appointment = Appointment::sole();
        $this->assertTrue($appointment->patient->is($child));
        $this->assertTrue($appointment->from_verified_device);

        // "Otra persona": un familiar nuevo queda en el mismo celular.
        Livewire::withCookie(DeviceService::COOKIE, 'celular-de-rosa')
            ->test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '11:00')
            ->call('forSomeoneElse')
            ->assertSet('who', 'family')
            ->set('relationship', PatientRelationship::Parent->value)
            ->set('firstName', 'Juana')
            ->set('lastName', 'Rojas')
            ->call('book')
            ->assertHasNoErrors()
            ->assertSet('step', 4);

        $this->assertSame(PatientRelationship::Parent, $account->patients()->where('first_name', 'Juana')->sole()->relationship);
    }
}
