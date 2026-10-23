<?php

namespace App\Providers;

use App\Services\Web\ClinicInfo;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una vez por petición: el horario se arma con los horarios de los doctores.
        $this->app->scoped(ClinicInfo::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fechas en español: "martes 4 de noviembre".
        Carbon::setLocale(config('app.locale'));
        CarbonImmutable::setLocale(config('app.locale'));

        // En desarrollo, avisar de consultas N+1 y atributos inexistentes.
        Model::shouldBeStrict(! $this->app->isProduction());

        Event::listen(Login::class, fn (Login $event) => $event->user->forceFill(['last_login_at' => now()])->saveQuietly());
    }
}
