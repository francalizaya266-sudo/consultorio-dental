<?php

namespace Tests\Feature\Services\Inventory;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\InventoryException;
use App\Models\Appointment;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Clinical\ConsultationService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->reception = User::factory()->reception()->create();
    }

    public function test_closing_a_consultation_uses_the_recipe_taking_first_what_expires_first(): void
    {
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $gloves = Supply::factory()->create(['name' => 'Guantes', 'unit' => 'par', 'min_stock' => 0]);
        $paste = Supply::factory()->create(['name' => 'Pasta profiláctica', 'unit' => 'g', 'min_stock' => 0]);
        $cleaning->supplies()->attach([$gloves->id => ['quantity' => 2], $paste->id => ['quantity' => 5]]);
        $later = $this->inventory()->receive($gloves, 10, $this->reception, ['lot' => 'B', 'expires_on' => '2027-06-30']);
        $sooner = $this->inventory()->receive($gloves, 1, $this->reception, ['lot' => 'A', 'expires_on' => '2027-01-31']);
        $this->inventory()->receive($paste, 100, $this->reception);

        $doctor = $this->doctorOffering($cleaning);
        $appointment = Appointment::factory()->for($doctor)->for($cleaning)->at('2026-11-02 10:00')->confirmed()->create();
        $consultation = $this->consultations()->startFromAppointment($appointment, $doctor->user);

        $this->assertSame([
            ['supply_id' => $gloves->id, 'quantity' => 2.0, 'batch_id' => null],
            ['supply_id' => $paste->id, 'quantity' => 5.0, 'batch_id' => null],
        ], $this->inventory()->plannedUsage($consultation));

        $this->consultations()->close($consultation, $doctor->user);

        // El par que vencía antes salió primero; el otro, del lote siguiente.
        $this->assertSame(['0.00', '9.00'], [$sooner->fresh()->remaining, $later->fresh()->remaining]);
        $this->assertSame('9.00', $gloves->fresh()->stock);
        $this->assertSame('95.00', $paste->fresh()->stock);
        $uses = StockMovement::query()->used()->where('consultation_id', $consultation->id)->with('batch')->orderBy('id')->get();
        $this->assertSame([['A', '-1.00'], ['B', '-1.00'], [null, '-5.00']], $uses->map(fn (StockMovement $use) => [$use->batch?->lot, $use->quantity])->all());
        $this->assertTrue($uses->every(fn (StockMovement $use) => $use->patient_id === $appointment->patient_id && $use->user_id === $doctor->user->id));
    }

    public function test_the_doctor_corrects_what_was_used_and_reopening_returns_it_to_stock(): void
    {
        $treatment = Treatment::factory()->create();
        $anesthesia = Supply::factory()->create(['name' => 'Lidocaína 2 %', 'unit' => 'carpule', 'min_stock' => 0]);
        $treatment->supplies()->attach($anesthesia->id, ['quantity' => 1]);
        $this->inventory()->receive($anesthesia, 50, $this->reception, ['lot' => 'L1']);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->confirmed()->create();
        $consultation = $this->consultations()->startFromAppointment($appointment, $doctor->user);

        // Hizo falta un carpule más.
        $this->consultations()->close($consultation, $doctor->user, [['supply_id' => $anesthesia->id, 'quantity' => 2]]);
        $this->assertSame('48.00', $anesthesia->fresh()->stock);

        $this->consultations()->reopen($consultation->fresh(), $doctor->user);
        $this->assertSame('50.00', $anesthesia->fresh()->stock);
        $this->assertSame(['-2.00', '2.00'], StockMovement::query()->where('consultation_id', $consultation->id)->orderBy('id')->pluck('quantity')->all());
        // Al volver a terminar, se propone lo que había registrado, no la receta.
        $this->assertSame([['supply_id' => $anesthesia->id, 'quantity' => 2.0, 'batch_id' => null]], $this->inventory()->plannedUsage($consultation->fresh()));

        $this->consultations()->close($consultation->fresh(), $doctor->user);
        $this->assertSame('48.00', $anesthesia->fresh()->stock);
        $this->assertSame(1, StockMovement::query()->used()->where('consultation_id', $consultation->id)->count());
    }

    public function test_plan_sessions_worked_today_add_their_recipe_once(): void
    {
        $resin = Treatment::factory()->create(['name' => 'Curación con resina']);
        $composite = Supply::factory()->create(['name' => 'Resina A2', 'unit' => 'g', 'min_stock' => 0]);
        $resin->supplies()->attach($composite->id, ['quantity' => 0.5]);
        $doctor = $this->doctorOffering($resin);
        $appointment = Appointment::factory()->for($doctor)->for($resin)->at('2026-11-02 10:00')->confirmed()->create();
        $plan = TreatmentPlan::create(['patient_id' => $appointment->patient_id, 'doctor_id' => $doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted]);
        $first = $plan->items()->create(['treatment_id' => $resin->id, 'description' => 'Resina 16', 'unit_price' => 120]);
        $second = $plan->items()->create(['treatment_id' => $resin->id, 'description' => 'Resina 26', 'unit_price' => 120]);
        $consultation = $this->consultations()->startFromAppointment($appointment, $doctor->user);

        // Dos resinas del plan hoy: dos veces la receta; la cita es de lo mismo y no suma una tercera.
        $this->assertSame([['supply_id' => $composite->id, 'quantity' => 1.0, 'batch_id' => null]], $this->inventory()->plannedUsage($consultation, [$first->id, $second->id]));
    }

    public function test_an_appointment_marked_as_attended_by_reception_uses_its_recipe_but_not_implants(): void
    {
        $treatment = Treatment::factory()->create();
        $gauze = Supply::factory()->create(['name' => 'Gasas', 'min_stock' => 0]);
        $implant = Supply::factory()->serialized()->create();
        $treatment->supplies()->attach([$gauze->id => ['quantity' => 4], $implant->id => ['quantity' => 1]]);
        $this->inventory()->receive($gauze, 100, $this->reception);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->confirmed()->create();

        app(AppointmentService::class)->markCompleted($appointment, $this->reception);
        // Nunca se descuenta dos veces.
        $this->inventory()->useForAppointment($appointment->fresh(), $this->reception);

        $this->assertSame('96.00', $gauze->fresh()->stock);
        $this->assertSame('0.00', $implant->fresh()->stock);
        $this->assertSame(1, StockMovement::query()->where('appointment_id', $appointment->id)->count());
    }

    public function test_using_more_than_registered_goes_negative_until_the_next_purchase_covers_it(): void
    {
        $gloves = Supply::factory()->create(['min_stock' => 0]);
        $this->inventory()->take($gloves, 3, StockMovementType::Use, $this->reception);

        $this->assertSame('-3.00', $gloves->fresh()->stock);

        $batch = $this->inventory()->receive($gloves, 10, $this->reception, ['lot' => 'N1']);

        // Llegaron 10, pero 3 ya se habían usado: el lote dice lo que de verdad queda.
        $this->assertSame(['7.00', '7.00'], [$gloves->fresh()->stock, $batch->fresh()->remaining]);
    }

    public function test_an_implant_records_its_lot_and_serial_on_the_patient(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Implante']);
        $implant = Supply::factory()->serialized()->create(['name' => 'Implante 4.1 x 10 mm']);
        $treatment->supplies()->attach($implant->id, ['quantity' => 1]);
        $units = $this->inventory()->receiveSerials($implant, ["SN-001\nSN-002", 'SN-002'], $this->reception, ['lot' => 'LT77', 'expires_on' => '2030-01-01']);

        $this->assertSame(['SN-001', 'SN-002'], $units->pluck('serial')->all());
        $this->expectExceptionObject(InventoryException::duplicateSerial('SN-001'));

        try {
            $this->inventory()->receive($implant, 1, $this->reception, ['serial' => 'SN-001']);
        } finally {
            $doctor = $this->doctorOffering($treatment);
            $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->confirmed()->create();
            $consultation = $this->consultations()->startFromAppointment($appointment, $doctor->user);

            $this->assertSame([['supply_id' => $implant->id, 'quantity' => 1.0, 'batch_id' => null]], $this->inventory()->plannedUsage($consultation));
            $this->assertSame([$units[0]->id => 'Serie SN-001 · lote LT77 · vence 01/2030', $units[1]->id => 'Serie SN-002 · lote LT77 · vence 01/2030'], $this->inventory()->availableUnits($implant));

            $this->consultations()->close($consultation, $doctor->user, [['supply_id' => $implant->id, 'quantity' => 1, 'batch_id' => $units[1]->id]]);

            $use = StockMovement::query()->used()->where('consultation_id', $consultation->id)->with('batch')->sole();
            $this->assertSame([$appointment->patient_id, 'SN-002', 'LT77'], [$use->patient_id, $use->batch->serial, $use->batch->lot]);
            $this->assertSame([$units[0]->id], array_keys($this->inventory()->availableUnits($implant)));
        }
    }

    public function test_going_below_the_minimum_alerts_reception_once(): void
    {
        $anesthesia = Supply::factory()->create(['name' => 'Lidocaína 2 %', 'unit' => 'carpule', 'min_stock' => 10]);
        $this->inventory()->receive($anesthesia, 12, $this->reception);

        $this->inventory()->take($anesthesia, 1, StockMovementType::Use, $this->reception);
        $this->assertSame(0, DatabaseNotification::count());

        $this->inventory()->take($anesthesia, 2, StockMovementType::Use, $this->reception);
        $this->inventory()->take($anesthesia, 1, StockMovementType::Use, $this->reception);

        $notification = DatabaseNotification::sole();
        $this->assertSame('Queda poco: Lidocaína 2 %', $notification->data['title']);
        $this->assertSame('Hay 9 carpules y el mínimo es 10 carpules. Pídelo por WhatsApp desde Compras.', $notification->data['body']);
        $this->assertTrue(Supply::query()->belowMinimum()->whereKey($anesthesia->id)->exists());
    }

    public function test_a_count_sets_what_there_is_and_keeps_who_and_why(): void
    {
        $gloves = Supply::factory()->create(['min_stock' => 0]);
        $old = $this->inventory()->receive($gloves, 5, $this->reception, ['lot' => 'OLD', 'expires_on' => '2026-12-01']);
        $new = $this->inventory()->receive($gloves, 5, $this->reception, ['lot' => 'NEW', 'expires_on' => '2027-12-01']);

        // Faltan 3: salen de lo que vencía antes.
        $this->inventory()->adjust($gloves, 7, 'Conteo de noviembre', $this->reception);
        $this->assertSame(['7.00', '2.00', '5.00'], [$gloves->fresh()->stock, $old->fresh()->remaining, $new->fresh()->remaining]);

        // Aparecen 2 más: se suman al lote más nuevo.
        $this->inventory()->adjust($gloves->fresh(), 9, 'Estaban en el otro cajón', $this->reception);
        $this->assertSame(['9.00', '7.00'], [$gloves->fresh()->stock, $new->fresh()->remaining]);

        // Contar lo mismo no deja movimiento.
        $this->inventory()->adjust($gloves->fresh(), 9, 'Conteo', $this->reception);

        $adjustments = StockMovement::query()->where('type', StockMovementType::Adjustment)->orderBy('id')->get();
        $this->assertSame([['-3.00', 'Conteo de noviembre'], ['2.00', 'Estaban en el otro cajón']], $adjustments->map(fn (StockMovement $m) => [$m->quantity, $m->reason])->all());
        $this->assertTrue($adjustments->every(fn (StockMovement $m) => $m->user_id === $this->reception->id));
    }

    public function test_counting_a_material_that_was_never_loaded_creates_its_first_batch(): void
    {
        $gloves = Supply::factory()->create();
        $this->inventory()->take($gloves, 2, StockMovementType::Use, $this->reception);

        $this->inventory()->adjust($gloves->fresh(), 40, 'Conteo inicial', $this->reception);

        $this->assertSame('40.00', $gloves->fresh()->stock);
        $this->assertSame('40.00', $gloves->batches()->sole()->remaining);
        // El ajuste cubre también los 2 que se habían usado sin estar cargados.
        $this->assertSame(42.0, (float) StockMovement::query()->where('type', StockMovementType::Adjustment)->sum('quantity'));
    }

    public function test_an_order_goes_by_whatsapp_and_arrives_with_its_lots(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Dental Perú', 'contact_name' => 'Rocío', 'phone' => '987654321']);
        $gloves = Supply::factory()->for($supplier)->create(['name' => 'Guantes de nitrilo M', 'unit' => 'caja', 'min_stock' => 4]);
        $implant = Supply::factory()->serialized()->for($supplier)->create(['name' => 'Implante 4.1 x 10 mm']);
        $this->inventory()->receive($gloves, 4, $this->reception);
        $this->inventory()->take($gloves, 3, StockMovementType::Use, $this->reception);
        $this->inventory()->adjust($implant, 0, 'Conteo', $this->reception);

        $this->assertSame([$gloves->id], $this->inventory()->toReorder($supplier)->modelKeys());
        $this->assertSame(7.0, $gloves->fresh()->suggestedOrder());

        $order = $this->inventory()->placeOrder($supplier, [['supply_id' => $gloves->id, 'quantity' => 7], ['supply_id' => $implant->id, 'quantity' => 2], ['supply_id' => null, 'quantity' => 1]], $this->reception);

        Setting::set('clinic.name', 'Clínica Sonrisa');
        $this->assertSame("Hola Rocío, le escribimos de Clínica Sonrisa. Quisiéramos pedir:\n• Guantes de nitrilo M: 7 cajas\n• Implante 4.1 x 10 mm: 2 unidades\n\n¿Nos confirma el precio y cuándo llegaría? Gracias.", $this->inventory()->orderMessage($order));
        $this->assertStringStartsWith('https://wa.me/51987654321?text=Hola%20Roc%C3%ADo', $this->inventory()->whatsAppUrl($order));

        // Llegan los guantes y un solo implante.
        [$glovesItem, $implantItem] = $order->items->all();
        $this->inventory()->receiveOrder($order, [
            $glovesItem->id => ['received' => 7, 'lot' => 'G-55', 'expires_on' => '2028-01-01', 'unit_cost' => 18.5],
            $implantItem->id => ['serials' => 'SN-9'],
        ], $this->reception);

        $this->assertSame(PurchaseOrderStatus::Partial, $order->fresh()->status);
        $this->assertSame(['8.00', '18.50'], [$gloves->fresh()->stock, $gloves->fresh()->unit_cost]);
        $this->assertSame('1.00', $implant->fresh()->stock);

        $this->inventory()->receiveOrder($order->fresh(), [$implantItem->id => ['serials' => 'SN-10']], $this->reception);

        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame(['SN-9', 'SN-10'], $implant->batches()->orderBy('id')->pluck('serial')->all());
        $this->expectExceptionObject(InventoryException::orderClosed());
        $this->inventory()->cancelOrder($order->fresh());
    }

    public function test_an_expired_batch_is_written_off_with_its_reason(): void
    {
        $resin = Supply::factory()->create(['min_stock' => 0]);
        $batch = $this->inventory()->receive($resin, 3, $this->reception, ['lot' => 'R1', 'expires_on' => '2026-10-31']);

        $this->assertTrue($batch->isExpired());
        $this->assertTrue(Supply::query()->expiringSoon()->whereKey($resin->id)->exists());

        $this->inventory()->writeOff($batch, 5, 'Venció', $this->reception);

        $this->assertSame(['0.00', '0.00'], [$resin->fresh()->stock, $batch->fresh()->remaining]);
        $this->assertFalse(Supply::query()->expiringSoon()->whereKey($resin->id)->exists());
        $this->assertSame(['quantity' => '-3.00', 'reason' => 'Venció'], StockMovement::query()->where('type', StockMovementType::WriteOff)->sole()->only(['quantity', 'reason']));
    }

    public function test_the_recipe_cost_uses_the_last_cost_of_each_material(): void
    {
        $treatment = Treatment::factory()->create();
        $treatment->supplies()->attach([
            Supply::factory()->create(['unit_cost' => 1.2])->id => ['quantity' => 2],
            Supply::factory()->create(['unit_cost' => 0.35])->id => ['quantity' => 4],
        ]);

        $this->assertSame(3.8, $this->inventory()->recipeCost($treatment->load('supplies')));
    }

    private function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    private function consultations(): ConsultationService
    {
        return app(ConsultationService::class);
    }
}
