<?php

use App\Http\Controllers\Patient\AppointmentLinkController;
use App\Http\Controllers\Site\AccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('reservar', 'site.booking')->name('site.booking');
Route::view('privacidad', 'site.privacy')->name('site.privacy');

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
