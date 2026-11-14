<?php

namespace Tests\Feature\Livewire\Site;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\DiscountType;
use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Enums\TreatmentPlanStatus;
use App\Livewire\Site\BookingWizard;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Patient;
use App\Models\RememberedDevice;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
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
            ->assertSee('El celular tiene 9 dígitos y escribiste 5.')
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

    public function test_the_birth_date_is_typed_in_three_fields_and_an_incomplete_one_is_rejected(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);

        Livewire::test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->assertSee(['Día', 'Mes', 'Año', 'Febrero'])
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe Rojas')
            ->set('acceptsPrivacy', true)
            ->set('birthDay', '31')
            ->set('birthMonth', '2')
            ->set('birthYear', '1985')
            ->call('book')
            ->assertHasErrors('birthDate')
            ->assertSee('Completa el día, el mes y el año de nacimiento.')
            ->set('birthDay', '7')
            ->call('book')
            ->assertHasNoErrors()
            ->assertSet('step', 4);

        $this->assertSame('1985-02-07', Patient::sole()->birth_date->toDateString());
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
            ->assertSee('Precio con la promoción Limpieza de verano: desde S/ 120 (normal desde S/ 150).')
            ->assertSee('No se cobra nada ahora. Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.')
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe')
            ->set('acceptsPrivacy', true)
            ->call('book')
            ->assertSet('step', 4)
            ->assertSee('Precio con la promoción Limpieza de verano: desde S/ 120 (normal desde S/ 150). Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.');

        $this->assertTrue(Appointment::sole()->campaign->is($campaign));

        $confirmation = app(WhatsAppOutbox::class)->render(WhatsappMessage::where('template_key', MessageTemplateKey::BookingConfirmation)->sole());
        $this->assertStringContainsString("a las 10:00 am con {$cleaning->doctors->first()->name}.\nPrecio con la promoción Limpieza de verano: desde S/ 120 (normal desde S/ 150). Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.\n\nConfirma tu asistencia", $confirmation);
    }

    public function test_someone_in_treatment_is_told_at_the_first_step_and_can_still_book_for_the_family(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $doctor = $this->doctorOffering($evaluation);
        $account = Account::factory()->verified()->create(['phone' => '+51987654321']);
        $mother = Patient::factory()->for($account)->create(['first_name' => 'Rosa']);
        $child = Patient::factory()->dependentOf($account)->create(['first_name' => 'Mateo']);
        TreatmentPlan::create(['patient_id' => $mother->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::InProgress]);
        RememberedDevice::create(['account_id' => $account->id, 'token_hash' => hash('sha256', 'celular-de-rosa'), 'expires_at' => now()->addMonth()]);

        Livewire::withCookie(DeviceService::COOKIE, 'celular-de-rosa')
            ->test(BookingWizard::class)
            ->assertSet('step', 1)
            ->assertSee('Rosa, como estás en tratamiento, tu doctor programa tus próximas sesiones.')
            ->assertSee('Si la cita es para otra persona, sigue: la eliges en el último paso.')
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->assertSee('Rosa · en tratamiento')
            ->assertSeeHtml('wire:target="book" disabled')
            ->call('choosePatient', $child->id)
            ->assertDontSee('como estás en tratamiento')
            ->call('book')
            ->assertSet('step', 4);

        $this->assertTrue(Appointment::sole()->patient->is($child));
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

    public function test_missing_data_is_named_next_to_the_button_and_the_page_is_told_to_show_it(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);

        Livewire::test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->call('book')
            ->assertDispatched('datos-por-corregir')
            ->assertSee('Para reservar, revisa tu celular, los nombres, los apellidos y la política de privacidad.')
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe Rojas')
            ->call('book')
            ->assertSee('Para reservar, revisa la política de privacidad.');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_promo_code_that_does_not_exist_stops_the_booking_and_a_valid_one_is_confirmed(): void
    {
        $this->freezeMonday();
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 150]);
        $this->doctorOffering($cleaning);
        $campaign = Campaign::query()->create(['name' => 'Limpieza de verano', 'slug' => 'limpieza-verano', 'promo_code' => 'VERANO30', 'treatment_id' => $cleaning->id, 'discount_type' => DiscountType::Amount, 'discount_value' => 30, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

        $wizard = Livewire::test(BookingWizard::class)
            ->call('chooseTreatment', $cleaning->id)
            ->call('chooseTime', '10:00')
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe Rojas')
            ->set('acceptsPrivacy', true)
            ->set('promoCode', 'SONRISA50')
            ->assertSee('Ese código no existe o ya no está disponible. Corrígelo o bórralo para reservar.')
            ->call('book')
            ->assertHasErrors('promoCode')
            ->assertSee('Para reservar, revisa el código de promoción.')
            ->assertSet('step', 3);

        $this->assertDatabaseCount('appointments', 0);

        $wizard->set('promoCode', 'verano30')
            ->assertHasNoErrors()
            ->assertDontSee(['Ese código no existe', 'Para reservar, revisa'])
            ->assertSee('Código aplicado: Limpieza de verano.')
            ->call('book')
            ->assertHasNoErrors()
            ->assertSet('step', 4);

        $this->assertTrue(Appointment::sole()->campaign->is($campaign));
    }

    public function test_going_back_two_steps_returns_to_the_start_and_a_booked_appointment_does_not_reopen(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);

        Livewire::test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->call('back', 2)
            ->assertSet('step', 1)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe Rojas')
            ->set('acceptsPrivacy', true)
            ->call('book')
            ->assertSet('step', 4)
            ->call('back')
            ->assertSet('step', 4)
            ->assertSee('¡Tu cita quedó reservada!');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_after_booking_the_patient_is_told_what_comes_next_and_can_save_the_appointment(): void
    {
        $this->freezeMonday();
        Setting::set('clinic.address', 'Jr. Apurímac 648');
        Setting::set('clinic.district', 'Juliaca');
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);

        $wizard = Livewire::test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->set('phone', '987 654 321')
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe Rojas')
            ->set('acceptsPrivacy', true)
            ->call('book')
            ->assertSet('step', 4)
            ->assertSeeHtmlInOrder([
                'Qué sigue',
                'Confirma tu asistencia', 'Tu cita queda por confirmar', '987 654 321',
                'Guardar en mi calendario',
                'Jr. Apurímac 648, Juliaca', 'Cómo llegar',
            ]);

        // El archivo del calendario es el de la cita recién reservada.
        $this->get($wizard->instance()->calendarUrl())
            ->assertOk()
            ->assertSee('DTSTART:20261102T150000Z');
    }

    public function test_how_they_found_us_is_asked_once_after_booking_instead_of_in_the_form(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);
        $book = fn (array $query, string $time, string $phone) => Livewire::withQueryParams($query)
            ->test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', $time)
            ->assertDontSee('¿Cómo nos conociste?')
            ->set('phone', $phone)
            ->set('firstName', 'María')
            ->set('lastName', 'Quispe Rojas')
            ->set('acceptsPrivacy', true)
            ->call('book')
            ->assertSet('step', 4);

        $book([], '10:00', '987 654 321')
            ->assertSee('¿cómo nos conociste?')
            // "Campaña" la pone el sistema cuando reserva con una promoción: no se acepta desde aquí.
            ->call('tellOrigin', AppointmentOrigin::Campaign->value)
            ->assertSee('¿cómo nos conociste?')
            ->call('tellOrigin', AppointmentOrigin::Facebook->value)
            ->assertSee('¡Gracias por contarnos!')
            ->assertDontSee('¿cómo nos conociste?')
            // Ya contestó: otro toque no lo cambia.
            ->call('tellOrigin', AppointmentOrigin::Google->value);

        $appointment = Appointment::with('patient')->sole();
        $this->assertSame(AppointmentOrigin::Facebook, $appointment->origin);
        $this->assertSame(AppointmentOrigin::Facebook, $appointment->patient->origin);

        // Quien llega desde un anuncio ya trae la respuesta en el enlace: no se le pregunta.
        $book(['origen' => 'instagram'], '11:00', '912 345 678')->assertDontSee('¿cómo nos conociste?');
        $this->assertSame(AppointmentOrigin::Instagram, Appointment::query()->latest('id')->first()->origin);
    }

    public function test_a_family_that_already_takes_promotions_is_not_asked_again(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);
        $account = Account::factory()->verified()->create(['accepts_promotions' => false]);
        Patient::factory()->for($account)->create();
        RememberedDevice::create(['account_id' => $account->id, 'token_hash' => hash('sha256', 'celular-de-rosa'), 'expires_at' => now()->addMonth()]);
        $atTheForm = fn () => Livewire::withCookie(DeviceService::COOKIE, 'celular-de-rosa')
            ->test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00');

        $atTheForm()->assertSee('Quiero recibir promociones por WhatsApp');

        $account->update(['accepts_promotions' => true]);

        $atTheForm()
            ->assertDontSee('Quiero recibir promociones por WhatsApp')
            ->call('book')
            ->assertSet('step', 4)
            // Una cita desde el celular recordado no vuelve a preguntar cómo nos conoció.
            ->assertDontSee('¿cómo nos conociste?');

        $this->assertTrue($account->fresh()->accepts_promotions);
    }

    public function test_each_step_has_a_name_and_the_first_one_offers_whatsapp_when_it_hurts(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);
        Setting::set('clinic.whatsapp', '912 345 678');

        Livewire::test(BookingWizard::class)
            ->assertSeeHtmlInOrder(['aria-current="step"', 'Motivo</li>', 'Día y hora</li>', 'Tus datos</li>'])
            ->assertSee('Tengo dolor o es urgente')
            ->assertSeeHtml('href="https://wa.me/51912345678?text=Hola%2C%20tengo%20dolor%20y%20necesito%20una%20cita%20lo%20antes%20posible."')
            ->call('chooseTreatment', $evaluation->id)
            ->assertSeeHtmlInOrder(['Motivo</li>', 'aria-current="step"', 'Día y hora</li>', 'Tus datos</li>'])
            ->assertDontSee('Tengo dolor o es urgente');
    }

    public function test_relatives_with_the_same_first_name_are_told_apart_by_their_surname(): void
    {
        $this->freezeMonday();
        $evaluation = Treatment::factory()->evaluation()->create();
        $this->doctorOffering($evaluation);
        $account = Account::factory()->verified()->create(['phone' => '+51987654321']);
        Patient::factory()->for($account)->create(['first_name' => 'María', 'last_name' => 'Quispe Huamán']);
        Patient::factory()->dependentOf($account)->create(['first_name' => 'Maria', 'last_name' => 'Flores Ticona']);
        Patient::factory()->dependentOf($account)->create(['first_name' => 'Mateo', 'last_name' => 'Quispe Flores']);
        RememberedDevice::create(['account_id' => $account->id, 'token_hash' => hash('sha256', 'celular-de-maria'), 'expires_at' => now()->addMonth()]);

        Livewire::withCookie(DeviceService::COOKIE, 'celular-de-maria')
            ->test(BookingWizard::class)
            ->call('chooseTreatment', $evaluation->id)
            ->call('chooseTime', '10:00')
            ->assertSeeHtmlInOrder(['María Quispe</button>', 'Maria Flores</button>', 'Mateo</button>', 'Otra persona</button>']);
    }
}
