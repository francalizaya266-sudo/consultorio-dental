<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Treatments\TreatmentResource;
use App\Models\Account;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Filament\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PanelNavigationTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_the_menu_has_eight_entries_and_the_rest_goes_inside(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Hoy', 'Agenda', 'Pacientes', 'Caja', 'Avisos', 'Reportes', 'Configuración', 'Página web'])
            ->assertDontSee(['Bloqueos de horario', 'Lista de espera', 'Presupuestos y planes', 'Cuotas por cobrar', 'Cierre del día', 'Copias de seguridad', 'Preguntas frecuentes']);

        // Lo de la página web va aparte de la configuración de la clínica.
        $this->get(TreatmentResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Configuración', 'Ajustes', 'Tratamientos', 'Textos de consentimiento', 'Página web'])
            ->assertDontSee(['Campañas', 'Antes y después', 'Opiniones', 'Preguntas frecuentes']);

        $this->get(CampaignResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Página web', 'Campañas', 'Antes y después', 'Opiniones', 'Preguntas frecuentes', 'Ver la página'])
            ->assertSee(route('site.home'));

        $this->get(AppointmentResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Agenda', 'Citas', 'Solicitudes de cambio', 'Lista de espera', 'Bloqueos de horario', 'Pacientes']);

        $this->get(PatientResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Pacientes', 'Presupuestos y planes', 'Celulares y familias', 'Caja']);

        $this->get(PaymentResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Caja', 'Saldos pendientes', 'Cuotas por cobrar', 'Cierre del día', 'Avisos']);
    }

    public function test_reception_only_sees_its_entries(): void
    {
        $this->actingAs(User::factory()->reception()->create());

        $this->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Hoy', 'Agenda', 'Pacientes', 'Caja', 'Avisos'])
            ->assertDontSee(['Reportes', 'Configuración', 'Página web']);
    }

    public function test_a_doctor_reaches_their_own_settings_inside_configuracion(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $this->actingAs($doctor->user);

        $this->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee(TreatmentResource::getUrl('index'))
            ->assertSee('Configuración')
            ->assertDontSee('Caja');
    }

    public function test_each_patient_found_with_ctrl_k_offers_a_new_appointment_and_whatsapp(): void
    {
        $patient = Patient::factory()->for(Account::factory()->state(['phone' => '+51987654321']))->create(['first_name' => 'María', 'last_name' => 'Quispe']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(GlobalSearch::class)
            ->set('search', 'Quispe')
            ->assertSee(['María Quispe', 'Nueva cita', 'WhatsApp'])
            ->assertSeeHtml(e(AppointmentResource::getUrl('create', ['patient' => $patient->id])))
            ->assertSeeHtml('https://wa.me/51987654321');
    }
}
