<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Doctor;
use App\Models\Faq;
use App\Models\Review;
use App\Models\SmileCase;
use App\Models\Treatment;
use App\Services\Booking\WebAvailability;
use App\Services\Web\StructuredData;
use Illuminate\View\View;

/** Inicio: próximos horarios libres, tratamientos con precio, equipo, casos, opiniones y contacto. */
class HomeController extends Controller
{
    public function __invoke(WebAvailability $availability, StructuredData $structuredData): View
    {
        $bookable = $availability->treatments();
        $featured = $bookable->firstWhere('is_evaluation', true) ?? $bookable->first();

        return view('site.home', [
            'featured' => $featured,
            'nextFree' => $featured ? $availability->nextFree($featured) : null,
            'campaigns' => Campaign::query()->published()->where('show_on_home', true)->with('treatment')->latest('starts_at')->get(),
            'treatments' => Treatment::query()->active()->where('is_public', true)->orderByDesc('is_evaluation')->orderBy('sort_order')->get(),
            'doctors' => Doctor::query()->active()->where('is_public', true)->orderBy('sort_order')->get(),
            'cases' => SmileCase::query()->published()->with('treatment')->limit(3)->get(),
            'reviews' => Review::query()->published()->limit(3)->get(),
            'faqs' => Faq::query()->published()->whereNull('treatment_id')->limit(5)->get(),
            'jsonLd' => [$structuredData->dentist()],
        ]);
    }
}
