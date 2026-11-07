<?php

namespace App\Services\Web;

use App\Filament\Pages\WebSetup;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\SmileCases\SmileCaseResource;
use App\Filament\Resources\Treatments\TreatmentResource;
use App\Models\Doctor;
use App\Models\Setting;
use App\Models\SmileCase;
use App\Models\Treatment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que falta configurar para que la web y los avisos den confianza: la foto de cada doctor, la del consultorio,
 * quién atiende lo que se reserva, el enlace a las opiniones de Google, la ubicación y un caso de antes y después.
 * Cada cosa con su enlace.
 */
final class SetupChecklist
{
    /**
     * @return list<array{key: string, title: string, why: string, done: bool, url: string, link: string}>
     */
    public function items(): array
    {
        // Marcado para la reserva pero sin un doctor que lo atienda por la web: no aparece y nadie se entera.
        $unattended = Treatment::query()->onlineBookable()
            ->whereDoesntHave('doctors', fn (Builder $doctors) => $doctors->active()->where('accepts_online_booking', true))
            ->orderBy('sort_order')->orderBy('name')->pluck('name');
        $withoutPhoto = Doctor::query()->active()->where('is_public', true)->whereNull('photo_path')->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);

        return [
            [
                'key' => 'doctors',
                'title' => 'La foto de cada doctor',
                'why' => $withoutPhoto->isEmpty()
                    ? 'Todos tienen su foto en la web.'
                    : 'Falta la de '.self::joinNames($withoutPhoto->pluck('name')).'. Quien reserva elige con quién atenderse viéndole la cara.',
                'done' => $withoutPhoto->isEmpty(),
                'url' => $withoutPhoto->count() === 1 ? DoctorResource::getUrl('edit', ['record' => $withoutPhoto->first()]) : DoctorResource::getUrl('index'),
                'link' => $withoutPhoto->count() === 1 ? 'Subir su foto' : 'Ver doctores',
            ],
            [
                'key' => 'hero',
                'title' => 'Una foto del consultorio en el inicio',
                'why' => 'Una foto real del consultorio o del equipo da confianza antes de reservar.',
                'done' => filled(Setting::get('web.hero_image')),
                'url' => WebSetup::getUrl().'#inicio',
                'link' => 'Subir la foto',
            ],
            [
                'key' => 'treatments',
                'title' => 'Quién atiende cada tratamiento de la reserva',
                'why' => $unattended->isEmpty()
                    ? 'Todo lo que se reserva por la web tiene quién lo atienda.'
                    : self::joinNames($unattended).($unattended->count() === 1 ? ' no aparece' : ' no aparecen').' en la reserva: marca qué doctor lo atiende.',
                'done' => $unattended->isEmpty(),
                'url' => TreatmentResource::getUrl('index'),
                'link' => 'Ver tratamientos',
            ],
            [
                'key' => 'reviews',
                'title' => 'El enlace a sus opiniones en Google',
                'why' => 'Sin él no salen las estrellas en la web ni se le pide su opinión a quien sale contento.',
                'done' => filled(Setting::get('clinic.reviews_url')),
                'url' => WebSetup::getUrl().'#opiniones',
                'link' => 'Pegar el enlace',
            ],
            [
                'key' => 'maps',
                'title' => 'La ubicación en Google Maps',
                'why' => 'Para el botón «Cómo llegar» de la web.',
                'done' => filled(Setting::get('clinic.maps_url')),
                'url' => WebSetup::getUrl().'#como-llegar',
                'link' => 'Pegar el enlace',
            ],
            [
                'key' => 'cases',
                'title' => 'Un caso de antes y después',
                'why' => 'Es lo que más convence a quien duda en hacerse un tratamiento. Va con el permiso del paciente.',
                'done' => SmileCase::query()->published()->exists(),
                'url' => SmileCaseResource::getUrl('index'),
                'link' => 'Agregar un caso',
            ],
        ];
    }

    /** @return list<array{key: string, title: string, why: string, done: bool, url: string, link: string}> */
    public function missing(): array
    {
        return array_values(array_filter($this->items(), fn (array $item) => ! $item['done']));
    }

    /** @param  Collection<int, string>  $names  "Dra. Ana Torres", "Dra. Ana Torres y Dr. Luis Ramos", "… y 2 más". */
    private static function joinNames(Collection $names): string
    {
        return match (true) {
            $names->count() <= 2 => $names->join(' y '),
            default => $names->take(2)->join(', ').' y '.($names->count() - 2).' más',
        };
    }
}
