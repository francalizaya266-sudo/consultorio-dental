<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Filament\Resources\SmileCases\Pages\ManageSmileCases;
use App\Models\Faq;
use App\Models\SmileCase;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Web\PublicImages;
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
