<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class ChargeDueInstallmentsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_charges_the_installments_due_today_once_and_skips_cancelled_plans(): void
    {
        $this->freezeMonday();
        $patient = Patient::factory()->create();
        $doctor = Doctor::factory()->create();

        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Ortodoncia', 'status' => TreatmentPlanStatus::InProgress, 'payment_mode' => PlanPaymentMode::Installments, 'accepted_at' => now()->subMonth()]);
        $plan->installments()->createMany([
            ['number' => 1, 'due_on' => '2026-10-02', 'amount' => 250],
            ['number' => 2, 'due_on' => '2026-11-02', 'amount' => 250],
            ['number' => 3, 'due_on' => '2026-12-02', 'amount' => 250],
        ]);
        $cancelled = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Cancelled, 'payment_mode' => PlanPaymentMode::Installments]);
        $cancelled->installments()->create(['number' => 1, 'due_on' => '2026-11-02', 'amount' => 99]);

        $this->artisan('cuotas:cargar')->expectsOutput('Cuotas cargadas: 2')->assertSuccessful();
        $this->artisan('cuotas:cargar')->expectsOutput('Cuotas cargadas: 0')->assertSuccessful();

        $this->assertSame(['Ortodoncia · cuota 1 de 3', 'Ortodoncia · cuota 2 de 3'], $patient->charges()->orderBy('charged_at')->pluck('description')->all());
        $this->assertSame(500.0, $patient->balance());
        $this->assertSame($doctor->id, $patient->charges()->first()->doctor_id);
    }
}
