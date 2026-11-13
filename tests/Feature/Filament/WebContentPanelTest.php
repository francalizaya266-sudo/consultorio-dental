<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Filament\Resources\SmileCases\Pages\ManageSmileCases;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Doctor;
use App\Models\Faq;
use App\Models\SmileCase;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Booking\WebAvailability;
use App\Services\Web\PublicImages;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WebContentPanelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_before_and_after_case_needs_the_patients_written_consent(): void
    {
        Storage::fake(PublicImages::DISK);
        $admin = User::factory()->admin(isDentist: true)->create();
        $this->actingAs($admin);
        $case = [
            'title' => 'Blanqueamiento en 2 sesiones',
            'before_path' => UploadedFile::fake()->image('antes.jpg', 3000, 2000),
            'after_path' => UploadedFile::fake()->image('despues.jpg', 800, 600),
            'is_published' => true,
        ];

        Livewire::test(ManageSmileCases::class)
            ->callAction('create', $case)
            ->assertHasFormErrors(['consent' => 'accepted']);

        $this->assertSame(0, SmileCase::count());

        Livewire::test(ManageSmileCases::class)
            ->callAction('create', $case + ['consent' => true])
            ->assertHasNoFormErrors();

        $saved = SmileCase::sole();
        $this->assertNotNull($saved->consent_confirmed_at);
        $this->assertTrue($saved->consentConfirmedBy->is($admin));

        // La foto grande se achica para la web; la chica queda de su tamaño.
        $this->assertStringStartsWith('casos/', $saved->before_path);
        $this->assertSame([1600, 1067], array_slice(getimagesizefromstring(Storage::disk(PublicImages::DISK)->get($saved->before_path)), 0, 2));
        $this->assertSame([800, 600], array_slice(getimagesizefromstring(Storage::disk(PublicImages::DISK)->get($saved->after_path)), 0, 2));
    }

    public function test_a_treatment_booked_on_the_website_asks_who_does_it_and_warns_when_nobody_does(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $torres = Doctor::factory()->create(['name' => 'Dra. Torres', 'accepts_online_booking' => true]);
        $ramos = Doctor::factory()->create(['name' => 'Dr. Ramos', 'accepts_online_booking' => false]);
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $ramos->treatments()->attach($cleaning, ['duration_minutes' => 40]);

        // Marcado para la web, pero solo lo atiende un doctor que no recibe reservas por la web.
        Livewire::test(ManageTreatments::class)
            ->assertTableColumnStateSet('presence', 'Web y reserva', $cleaning)
            ->assertSee('No aparece en la reserva: ningún doctor que reciba reservas en línea lo atiende.')
            ->mountAction(TestAction::make('edit')->table($cleaning))
            ->assertSchemaStateSet(['doctors' => [(string) $ramos->id]])
            ->assertMountedActionModalSee(['¿Quién lo atiende?', 'Dr. Ramos (no recibe reservas en línea)', 'Así no aparece en la reserva'])
            ->fillForm(['doctors' => [(string) $ramos->id, (string) $torres->id]])
            ->assertMountedActionModalDontSee('Así no aparece en la reserva')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing([$ramos->id, $torres->id], $cleaning->doctors()->pluck('doctors.id')->all());
        // La duración propia del doctor que ya lo hacía se conserva.
        $this->assertSame(40, $cleaning->doctors()->whereKey($ramos->id)->sole()->pivot->duration_minutes);
        $this->assertTrue(app(WebAvailability::class)->treatments()->contains('id', $cleaning->id));

        Livewire::test(ManageTreatments::class)
            ->assertDontSee('No aparece en la reserva');
    }

    public function test_a_question_can_belong_to_a_treatment_page(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Blanqueamiento']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageFaqs::class)
            ->callAction('create', [
                'question' => '¿El blanqueamiento daña el esmalte?',
                'answer' => 'No, si lo hace un dentista con la concentración adecuada.',
                'treatment_id' => $treatment->id,
                'is_published' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertTrue(Faq::sole()->treatment->is($treatment));

        $this->get(route('site.treatment', $treatment->slug))->assertSee('¿El blanqueamiento daña el esmalte?');
    }
}
