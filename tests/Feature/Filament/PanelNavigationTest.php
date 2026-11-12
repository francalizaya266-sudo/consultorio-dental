<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Filament\Resources\Treatments\TreatmentResource;
use App\Models\Account;
use App\Models\Patient;
use App\Models\Supply;
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

    public function test_the_menu_has_nine_entries_and_the_rest_goes_inside(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Hoy', 'Agenda', 'Pacientes', 'Caja', 'Inventario', 'Avisos', 'Reportes', 'Configuración', 'Página web'])
            ->assertDontSee(['Bloqueos de horario', 'Lista de espera', 'Presupuestos y planes', 'Cuotas por cobrar', 'Cierre del día', 'Copias de seguridad', 'Preguntas frecuentes', 'Proveedores']);

        // Lo de la página web va aparte de la configuración de la clínica.
        $this->get(TreatmentResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Configuración', 'Ajustes', 'Tratamientos', 'Textos de consentimiento', 'Página web'])
            ->assertDontSee(['Campañas', 'Antes y después', 'Opiniones', 'Preguntas frecuentes']);

        // La web se edita desde un solo lugar: primero su puesta a punto y el enlace para verla.
        $this->get(CampaignResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Página web', 'Puesta a punto', 'Ver la página', 'Campañas', 'Antes y después', 'Opiniones', 'Preguntas frecuentes'])
            ->assertSee(route('site.home'));

        $this->get(Settings::getUrl())
            ->assertOk()
            ->assertDontSee(['Título del inicio', 'Puntaje en Google']);

        $this->get(AppointmentResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Agenda', 'Citas', 'Cambios pedidos', 'Lista de espera', 'Bloqueos de horario', 'Pacientes']);

        $this->get(PatientResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Pacientes', 'Presupuestos y planes', 'Caja'])
            ->assertDontSee('Celulares y familias');

        $this->get(PaymentResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Caja', 'Saldos pendientes', 'Cuotas por cobrar', 'Cierre del día', 'Inventario']);

        // Conteo y Movimientos se abren desde Inventario; Proveedores, desde Compras.
        Supply::factory()->create();
        $this->get(SupplyResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Inventario', 'Compras', 'Avisos'])
            ->assertSee(['Contar', 'Buscar lote o serie'])
            ->assertDontSee(['Conteo', 'Movimientos']);

        $this->get(PurchaseOrderResource::getUrl('index'))
            ->assertOk()
            ->assertSee(SupplierResource::getUrl('index'));
    }

    public function test_a_list_that_opens_its_section_has_no_trail_above_and_the_pages_inside_keep_it(): void
    {
        $patient = Patient::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        foreach ([PatientResource::class, AppointmentResource::class, CampaignResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertOk()->assertDontSee(['fi-breadcrumbs', 'Listado'], false);
        }

        // Adentro sí sirve para volver.
        $this->get(PatientResource::getUrl('edit', ['record' => $patient]))
            ->assertOk()
            ->assertSeeInOrder(['fi-breadcrumbs', 'Pacientes', $patient->full_name], false);
    }

    public function test_reception_only_sees_its_entries(): void
    {
        $this->actingAs(User::factory()->reception()->create());

        $this->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Hoy', 'Agenda', 'Pacientes', 'Caja', 'Inventario', 'Avisos'])
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
            ->assertSeeHtml(e(AppointmentResource::newUrl(['patient' => $patient->id])))
            ->assertSeeHtml('https://wa.me/51987654321');
    }
}
