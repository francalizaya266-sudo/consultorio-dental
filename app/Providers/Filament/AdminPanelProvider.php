<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Controllers\Clinical\ClinicalFileController;
use App\Http\Controllers\Clinical\PrintController;
use App\Http\Controllers\Reports\EarningsExportController;
use App\Models\Charge;
use App\Models\Setting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Panel interno único: lo que ve cada persona depende de su rol (políticas en app/Policies). */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('panel')
            ->unsavedChangesAlerts()
            ->login()
            ->brandName(fn () => Setting::get('clinic.name'))
            ->colors([
                'primary' => Color::Teal,
            ])
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->strictAuthorization()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Agenda'),
                NavigationGroup::make('Pacientes'),
                NavigationGroup::make('Caja'),
                NavigationGroup::make('Web')->collapsed(),
                NavigationGroup::make('Configuración')->collapsed(),
            ])
            ->navigationItems([
                // La lista de pacientes filtrada: quién debe y cuánto.
                NavigationItem::make('Saldos pendientes')
                    ->group('Caja')
                    ->sort(2)
                    ->icon(Heroicon::OutlinedScale)
                    ->url(fn () => PatientResource::getUrl('index', ['tab' => 'owing']))
                    ->isActiveWhen(fn () => request()->routeIs(PatientResource::getRouteBaseName().'.index') && request()->query('tab') === 'owing')
                    ->visible(fn () => auth()->user()?->can('viewAny', Charge::class) ?? false),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authenticatedRoutes(function () {
                Route::get('imprimir/receta/{prescription}', [PrintController::class, 'prescription'])->name('print.prescription');
                Route::get('imprimir/plan/{plan}', [PrintController::class, 'plan'])->name('print.plan');
                Route::get('imprimir/consentimiento/{consent}', [PrintController::class, 'consent'])->name('print.consent');
                Route::get('imprimir/historia/{patient}', [PrintController::class, 'history'])->name('print.history');
                Route::get('archivos/{file}', [ClinicalFileController::class, 'show'])->name('clinical-files.show');
                Route::get('reportes/ganancias/imprimir', [EarningsExportController::class, 'print'])->name('reports.earnings.print');
                Route::get('reportes/ganancias/excel', [EarningsExportController::class, 'excel'])->name('reports.earnings.excel');
            });
    }
}
