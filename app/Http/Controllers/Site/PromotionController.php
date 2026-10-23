<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Web\StructuredData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Promociones vigentes; cada una con su página para compartir en redes y WhatsApp. */
class PromotionController extends Controller
{
    public function index(StructuredData $structuredData): View
    {
        return view('site.promotions.index', [
            'campaigns' => Campaign::query()->published()->with('treatment', 'package.items.treatment')->latest('starts_at')->get(),
            'jsonLd' => [$structuredData->breadcrumbs(['Promociones' => route('site.promotions')])],
        ]);
    }

    public function show(Request $request, string $slug, StructuredData $structuredData): View|Response
    {
        $campaign = Campaign::query()->where('slug', $slug)->with('treatment', 'package.items.treatment')->first();

        // Una promoción que ya terminó no da error: ofrece las vigentes.
        if ($campaign === null || ! Campaign::query()->published()->whereKey($campaign->getKey())->exists()) {
            return response()->view('site.promotions.ended', [
                'campaign' => $campaign,
                'campaigns' => Campaign::query()->published()->with('treatment', 'package.items.treatment')->latest('starts_at')->get(),
            ], 404);
        }

        // Visitas para las métricas de la campaña (sin contar recargas de la misma sesión).
        if (! $request->session()->has("promo-vista.{$campaign->getKey()}")) {
            $request->session()->put("promo-vista.{$campaign->getKey()}", true);
            $campaign->newQuery()->whereKey($campaign->getKey())->increment('views_count');
        }

        return view('site.promotions.show', [
            'campaign' => $campaign,
            'jsonLd' => [$structuredData->breadcrumbs(['Promociones' => route('site.promotions'), $campaign->name => route('site.promotion', $campaign->slug)])],
        ]);
    }
}
