<?php

use App\Http\Controllers\Patient\AppointmentLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Enlace personal de la cita que viaja por WhatsApp.
Route::controller(AppointmentLinkController::class)
    ->prefix('c/{token}')
    ->where(['token' => '[A-Za-z0-9]{32}'])
    ->middleware('throttle:30,1')
    ->group(function () {
        Route::get('/', 'show')->name('patient.link');
        Route::post('confirmar', 'confirm')->name('patient.link.confirm');
        Route::post('cancelar', 'cancel')->name('patient.link.cancel');
    });
