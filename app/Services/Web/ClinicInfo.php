<?php

namespace App\Services\Web;

use App\Models\Campaign;
use App\Models\Faq;
use App\Models\Review;
use App\Models\Setting;
use App\Models\SmileCase;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Datos del consultorio para la web, tal como se configuran en Ajustes. */
final class ClinicInfo
{
    private ?OpeningHours $hours = null;

    public function name(): string
    {
        return (string) Setting::get('clinic.name');
    }

    public function district(): ?string
    {
        return $this->text('clinic.district');
    }

    public function address(): ?string
    {
        return $this->text('clinic.address');
    }

    /** Dirección con el distrito, si no lo incluye ya. */
    public function fullAddress(): ?string
    {
        $address = $this->address();
        $district = $this->district();

        if ($address === null || $district === null || Str::contains($address, $district, ignoreCase: true)) {
            return $address ?? $district;
        }

        return $address.', '.$district;
    }

    public function phone(): ?string
    {
        return $this->text('clinic.phone');
    }

    public function phoneHref(): ?string
    {
        return $this->phone() ? 'tel:'.preg_replace('/[^\d+]/', '', $this->phone()) : null;
    }

    /** Celular de WhatsApp normalizado (+51…), o null si no es válido. */
    public function whatsapp(): ?string
    {
        $number = (string) Setting::get('clinic.whatsapp');

        return Phone::isValid($number) ? Phone::normalize($number) : null;
    }

    public function whatsappUrl(string $text = 'Hola, quisiera información.'): ?string
    {
        return $this->whatsapp() ? 'https://wa.me/'.Phone::forWhatsApp($this->whatsapp()).'?text='.rawurlencode($text) : null;
    }

    public function email(): ?string
    {
        return $this->text('clinic.email');
    }

    /** "Cómo llegar": el enlace de Google Maps del consultorio o una búsqueda de su dirección. */
    public function directionsUrl(): ?string
    {
        if ($url = $this->text('clinic.maps_url')) {
            return $url;
        }

        return $this->fullAddress() ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->fullAddress().', Perú') : null;
    }

    /** Mapa para incrustar (no necesita clave de Google). */
    public function mapEmbedUrl(): ?string
    {
        return $this->fullAddress() ? 'https://www.google.com/maps?q='.rawurlencode($this->name().', '.$this->fullAddress().', Perú').'&output=embed' : null;
    }

    public function reviewsUrl(): ?string
    {
        return $this->text('clinic.reviews_url');
    }

    /** @return array<string, string> Red social => enlace. */
    public function socials(): array
    {
        return array_filter([
            'Instagram' => $this->text('clinic.instagram'),
            'Facebook' => $this->text('clinic.facebook'),
            'TikTok' => $this->text('clinic.tiktok'),
        ]);
    }

    public function hours(): OpeningHours
    {
        return $this->hours ??= OpeningHours::fromSchedules();
    }

    public function hoursNote(): ?string
    {
        return $this->text('clinic.hours_note');
    }

    public function headline(): string
    {
        return $this->text('web.headline') ?? $this->name();
    }

    public function intro(): ?string
    {
        return $this->text('web.intro');
    }

    public function about(): ?string
    {
        return $this->text('web.about');
    }

    /**
     * Secciones con contenido publicado, para el menú (se revisa cada minuto).
     *
     * @return array{promotions: bool, cases: bool, reviews: bool, faq: bool}
     */
    public function sections(): array
    {
        return Cache::remember('web.secciones', 60, fn () => [
            'promotions' => Campaign::query()->published()->exists(),
            'cases' => SmileCase::query()->published()->exists(),
            'reviews' => Review::query()->published()->exists(),
            'faq' => Faq::query()->published()->exists(),
        ]);
    }

    private function text(string $key): ?string
    {
        $value = trim((string) Setting::get($key));

        return $value === '' ? null : $value;
    }
}
