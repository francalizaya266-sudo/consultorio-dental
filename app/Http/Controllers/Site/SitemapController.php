<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Faq;
use App\Models\Review;
use App\Models\SmileCase;
use App\Models\Treatment;
use Illuminate\Http\Response;

/** sitemap.xml y robots.txt para que Google encuentre todas las páginas públicas. */
class SitemapController extends Controller
{
    public function sitemap(): Response
    {
        $treatments = Treatment::query()->active()->where('is_public', true)->orderBy('sort_order')->get(['slug', 'updated_at']);
        $campaigns = Campaign::query()->published()->get(['slug', 'updated_at']);

        $urls = collect([
            ['loc' => route('site.home'), 'lastmod' => null],
            ['loc' => route('site.treatments'), 'lastmod' => $treatments->max('updated_at')],
            ...$treatments->map(fn (Treatment $t) => ['loc' => route('site.treatment', $t->slug), 'lastmod' => $t->updated_at]),
            ['loc' => route('site.team'), 'lastmod' => null],
            $campaigns->isNotEmpty() ? ['loc' => route('site.promotions'), 'lastmod' => $campaigns->max('updated_at')] : null,
            ...$campaigns->map(fn (Campaign $c) => ['loc' => route('site.promotion', $c->slug), 'lastmod' => $c->updated_at]),
            SmileCase::query()->published()->exists() ? ['loc' => route('site.cases'), 'lastmod' => null] : null,
            Review::query()->published()->exists() ? ['loc' => route('site.reviews'), 'lastmod' => null] : null,
            Faq::query()->published()->exists() ? ['loc' => route('site.faq'), 'lastmod' => null] : null,
            ['loc' => route('site.contact'), 'lastmod' => null],
            ['loc' => route('site.booking'), 'lastmod' => null],
        ])->filter();

        return response()->view('site.sitemap', ['urls' => $urls], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /panel',
            'Disallow: /mi-cuenta',
            'Disallow: /c/',
            'Disallow: /livewire',
            '',
            'Sitemap: '.route('site.sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
