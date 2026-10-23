<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Faq;
use App\Models\Review;
use App\Models\SmileCase;
use App\Models\Treatment;
use App\Services\Web\StructuredData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Páginas de contenido: equipo, antes y después, opiniones, preguntas frecuentes y contacto. */
class PageController extends Controller
{
    public function __construct(private readonly StructuredData $structuredData) {}

    public function team(): View
    {
        return view('site.team', [
            'doctors' => Doctor::query()->active()->where('is_public', true)
                ->with(['treatments' => fn ($query) => $query->active()->where('is_public', true)->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get(),
            'jsonLd' => [$this->structuredData->breadcrumbs(['Equipo' => route('site.team')])],
        ]);
    }

    public function cases(Request $request): View
    {
        $treatments = Treatment::query()->whereHas('smileCases', fn (Builder $query) => $query->published())->orderBy('sort_order')->get();
        $selected = $treatments->firstWhere('slug', (string) $request->query('tratamiento'));

        return view('site.cases', [
            'cases' => SmileCase::query()->published()->with(['treatment', 'doctor'])
                ->when($selected, fn (Builder $query) => $query->whereBelongsTo($selected))
                ->get(),
            'treatments' => $treatments,
            'selected' => $selected,
            'jsonLd' => [$this->structuredData->breadcrumbs(['Antes y después' => route('site.cases')])],
        ]);
    }

    public function reviews(): View
    {
        return view('site.reviews', [
            'reviews' => Review::query()->published()->with('treatment')->get(),
            'jsonLd' => [$this->structuredData->breadcrumbs(['Opiniones' => route('site.reviews')])],
        ]);
    }

    public function faq(): View
    {
        $faqs = Faq::query()->published()->with('treatment')->get();

        return view('site.faq', [
            'general' => $faqs->whereNull('treatment_id'),
            'byTreatment' => $faqs->whereNotNull('treatment_id')->groupBy(fn (Faq $faq) => $faq->treatment->name),
            'jsonLd' => array_filter([
                $this->structuredData->breadcrumbs(['Preguntas frecuentes' => route('site.faq')]),
                $faqs->isNotEmpty() ? $this->structuredData->faqPage($faqs) : null,
            ]),
        ]);
    }

    public function contact(): View
    {
        return view('site.contact', [
            'jsonLd' => [
                $this->structuredData->dentist(),
                $this->structuredData->breadcrumbs(['Contacto' => route('site.contact')]),
            ],
        ]);
    }
}
