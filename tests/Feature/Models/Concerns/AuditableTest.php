<?php

namespace Tests\Feature\Models\Concerns;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuditableTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_records_who_changed_what_with_old_and_new_values(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'Maria']);
        $doctor = User::factory()->doctor()->create();
        $this->actingAs($doctor);

        $patient->update(['first_name' => 'María']);

        $audit = AuditLog::query()->where('event', 'updated')->sole();
        $this->assertTrue($audit->user->is($doctor));
        $this->assertSame(['first_name' => 'Maria'], $audit->old_values);
        $this->assertSame(['first_name' => 'María'], $audit->new_values);
        $this->assertTrue($audit->auditable->is($patient));
    }

    public function test_keeps_the_record_and_logs_the_deletion(): void
    {
        $patient = Patient::factory()->create();

        $patient->delete();

        $this->assertSoftDeleted($patient);
        $this->assertSame(1, $patient->audits()->where('event', 'deleted')->count());
    }
}
