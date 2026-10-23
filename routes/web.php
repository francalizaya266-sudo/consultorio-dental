<?php

use App\Http\Controllers\Patient\AppointmentLinkController;
use App\Http\Controllers\Site\AccountController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\PromotionController;
use App\Http\Controllers\Site\SitemapController;
use App\Http\Controllers\Site\TreatmentController;
use Illuminate\Support\Facades\Route;

// Web pública.
Route::get('/', HomeController::class)->name('site.home');
Route::get('tratamientos', [TreatmentController::class, 'index'])->name('site.treatments');
Route::get('tratamientos/{slug}', [TreatmentController::class, 'show'])->name('site.treatment');
Route::get('promociones', [PromotionController::class, 'index'])->name('site.promotions');
Route::get('promociones/{slug}', [PromotionController::class, 'show'])->name('site.promotion');

Route::controller(PageController::class)->group(function () {
    Route::get('equipo', 'team')->name('site.team');
    Route::get('antes-y-despues', 'cases')->name('site.cases');
    Route::get('opiniones', 'reviews')->name('site.reviews');
    Route::get('preguntas-frecuentes', 'faq')->name('site.faq');
    Route::get('contacto', 'contact')->name('site.contact');
});

Route::view('reservar', 'site.booking')->name('site.booking');
Route::view('privacidad', 'site.privacy')->name('site.privacy');
Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('site.sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('site.robots');

// Mi cuenta: entra con el celular recordado (sin contraseña).
Route::controller(AccountController::class)
    ->prefix('mi-cuenta')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/', 'show')->name('site.account');
        Route::post('acceso', 'requestAccess')->name('site.account.access');
        Route::post('verificar', 'requestVerification')->name('site.account.verify');
        Route::post('salir', 'logout')->name('site.account.logout');
        Route::post('citas/{appointment}/confirmar', 'confirm')->name('site.account.confirm');
        Route::post('citas/{appointment}/cancelar', 'cancel')->name('site.account.cancel');
        Route::get('citas/{appointment}/cambiar', 'change')->name('site.account.change');
    });

// Enlace personal que viaja por WhatsApp (de una cita o de acceso a Mi cuenta).
Route::controller(AppointmentLinkController::class)
    ->prefix('c/{token}')
    ->where(['token' => '[A-Za-z0-9]{32}'])
    ->middleware('throttle:30,1')
    ->group(function () {
        Route::get('/', 'show')->name('patient.link');
        Route::post('confirmar', 'confirm')->name('patient.link.confirm');
        Route::post('cancelar', 'cancel')->name('patient.link.cancel');
        Route::get('cambiar', 'change')->name('patient.link.change');
        Route::post('entrar', 'enter')->name('patient.link.enter');
    });
