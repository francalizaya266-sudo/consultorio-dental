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
use Illuminate\Database\Eloquent\Collection;
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

        // Cada tema junta sus preguntas: primero las generales y después las de cada servicio.
        $topics = $faqs->groupBy(fn (Faq $faq) => (string) $faq->treatment?->slug)
            ->map(fn (Collection $questions, string $slug) => [
                'id' => $slug === '' ? 'faq-generales' : 'faq-de-'.$slug,
                'name' => $questions->first()->treatment?->name ?? 'Generales',
                'treatment' => $questions->first()->treatment,
                'faqs' => $questions,
            ])
            ->sortBy(fn (array $topic) => $topic['treatment'] === null ? 0 : 1)
            ->values();

        return view('site.faq', [
            'topics' => $topics,
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
