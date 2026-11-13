<?php

namespace App\Providers;

use App\Services\Dashboard\TodayPulse;
use App\Services\Inventory\SupplyForecast;
use App\Services\Web\ClinicInfo;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una vez por petición: el horario se arma con los horarios de los doctores.
        $this->app->scoped(ClinicInfo::class);
        $this->app->scoped(SupplyForecast::class);
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

        // Una sola regla para toda contraseña del panel (usuarios, doctores, "Mi perfil" y recuperación):
        // "12345678" o "password" ya no pasan.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        Event::listen(Login::class, fn (Login $event) => $event->user->forceFill(['last_login_at' => now()])->saveQuietly());

        // Quien pone su contraseña con el enlace del correo ya la eligió: no se le vuelve a pedir al entrar.
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => $event->user->forceFill(['must_change_password' => false])->saveQuietly());

        // Lo que guarda una persona lo ven las demás en pocos segundos, sin recargar.
        TodayPulse::watch();
    }
}
