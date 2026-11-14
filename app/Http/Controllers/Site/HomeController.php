<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Doctor;
use App\Models\Faq;
use App\Models\Review;
use App\Models\SmileCase;
use App\Models\Treatment;
use App\Services\Access\PatientAccess;
use App\Services\Booking\WebAvailability;
use App\Services\Web\ReturningPatient;
use App\Services\Web\StructuredData;
use App\Support\SiteArt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inicio: próximos horarios libres, tratamientos con precio, la especialidad, equipo, casos, opiniones y contacto. A quien vuelve
 * desde su celular recordado lo saluda primero con lo suyo: su próxima cita, sus sesiones y su control.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, WebAvailability $availability, StructuredData $structuredData, PatientAccess $access, ReturningPatient $returning): View
    {
        $device = $access->device($request);

        $bookable = $availability->treatments();
        $featured = $bookable->firstWhere('is_evaluation', true) ?? $bookable->first();
        $treatments = Treatment::query()->active()->where('is_public', true)->orderByDesc('is_evaluation')->orderBy('sort_order')->get();
        $doctors = Doctor::query()->active()->where('is_public', true)->orderBy('sort_order')->get();

        return view('site.home', [
            'welcome' => $device ? $returning->for($device->account) : null,
            'featured' => $featured,
            'nextFree' => $featured ? $availability->nextFree($featured) : null,
            'campaigns' => Campaign::query()->published()->where('show_on_home', true)->with('treatment')->latest('starts_at')->get(),
            'treatments' => $treatments,
            'specialty' => $this->specialty($doctors, $treatments),
            'doctors' => $doctors,
            'cases' => SmileCase::query()->published()->with('treatment')->limit(3)->get(),
            'reviews' => Review::query()->published()->limit(3)->get(),
            'faqs' => Faq::query()->published()->whereNull('treatment_id')->limit(5)->get(),
            'jsonLd' => [$structuredData->dentist()],
        ]);
    }

    /**
     * El tratamiento que el inicio destaca como "Nuestra especialidad": el de la especialidad que declara un
     * doctor (un implantólogo y el tratamiento de implantes). Sin especialidad o sin ese tratamiento, ninguno.
     *
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, Treatment>  $treatments
     */
    private function specialty(Collection $doctors, Collection $treatments): ?Treatment
    {
        foreach ($doctors->map(fn (Doctor $doctor) => SiteArt::specialty($doctor->specialty))->filter()->unique() as $image) {
            if ($treatment = $treatments->first(fn (Treatment $treatment) => SiteArt::keyFor($treatment) === $image)) {
                return $treatment;
            }
        }

        return null;
    }
}
