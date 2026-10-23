<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Review;
use App\Models\SmileCase;
use App\Models\Treatment;
use App\Services\Booking\WebAvailability;
use App\Services\Web\StructuredData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

/** Tratamientos: la lista con duración y precio "desde", y una página por tratamiento. */
class TreatmentController extends Controller
{
    public function index(StructuredData $structuredData): View
    {
        return view('site.treatments.index', [
            'treatments' => $this->visible()->get(),
            'jsonLd' => [$structuredData->breadcrumbs(['Tratamientos' => route('site.treatments')])],
        ]);
    }

    public function show(string $slug, WebAvailability $availability, StructuredData $structuredData): View
    {
        $treatment = $this->visible()->where('slug', $slug)->firstOrFail();
        $faqs = Faq::query()->published()->whereBelongsTo($treatment)->get();
        $bookable = $availability->treatments();
        // Si no se reserva solo, se empieza por la evaluación.
        $bookAs = $bookable->contains('id', $treatment->getKey()) ? $treatment : $bookable->firstWhere('is_evaluation', true);

        return view('site.treatments.show', [
            'treatment' => $treatment,
            'bookAs' => $bookAs,
            'nextFree' => $bookAs ? $availability->nextFree($bookAs) : null,
            'doctors' => $treatment->doctors()->active()->where('is_public', true)->orderBy('sort_order')->get(),
            'faqs' => $faqs,
            'cases' => SmileCase::query()->published()->whereBelongsTo($treatment)->limit(4)->get(),
            'reviews' => Review::query()->published()->whereBelongsTo($treatment)->limit(3)->get(),
            'others' => $this->visible()->whereKeyNot($treatment->getKey())->limit(6)->get(),
            'jsonLd' => array_filter([
                $structuredData->breadcrumbs(['Tratamientos' => route('site.treatments'), $treatment->name => route('site.treatment', $treatment->slug)]),
                $faqs->isNotEmpty() ? $structuredData->faqPage($faqs) : null,
            ]),
        ]);
    }

    /** @return Builder<Treatment> */
    private function visible(): Builder
    {
        return Treatment::query()->active()->where('is_public', true)->orderByDesc('is_evaluation')->orderBy('sort_order');
    }
}
