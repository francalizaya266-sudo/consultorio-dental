<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Backups;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Resources\SmileCases\SmileCaseResource;
use App\Filament\Resources\TextSnippets\TextSnippetResource;
use App\Filament\Resources\TreatmentPackages\TreatmentPackageResource;
use App\Filament\Resources\Treatments\TreatmentResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Controllers\Backup\BackupDownloadController;
use App\Http\Controllers\Clinical\ClinicalFileController;
use App\Http\Controllers\Clinical\PrintController;
use App\Http\Controllers\Reports\EarningsExportController;
use App\Models\Charge;
use App\Models\Setting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
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
    /** @var list<class-string> Páginas y secciones que van dentro de "Configuración". */
    private const SETTINGS = [
        Settings::class,
        TreatmentResource::class,
        TreatmentPackageResource::class,
        DoctorResource::class,
        UserResource::class,
        TextSnippetResource::class,
        ConsentTemplateResource::class,
        CampaignResource::class,
        FaqResource::class,
        ReviewResource::class,
        SmileCaseResource::class,
        Backups::class,
    ];

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
                // Azul, como el recetario de la clínica (con este tono los botones llevan texto blanco).
                'primary' => Color::Blue,
            ])
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->strictAuthorization()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->sidebarCollapsibleOnDesktop()
            // Ctrl + K (o ⌘ + K) abre el buscador desde cualquier pantalla.
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchFieldKeyBindingSuffix()
            // Siete entradas en el menú; lo demás aparece dentro de cada una al entrar.
            ->navigationItems([
                // La lista de pacientes filtrada: quién debe y cuánto.
                NavigationItem::make('Saldos pendientes')
                    ->parentItem('Caja')
                    ->sort(1)
                    ->icon(Heroicon::OutlinedScale)
                    ->url(fn () => PatientResource::getUrl('index', ['tab' => 'owing']))
                    ->isActiveWhen(fn () => request()->routeIs(PatientResource::getRouteBaseName().'.index') && request()->query('tab') === 'owing')
                    ->visible(fn () => auth()->user()?->can('viewAny', Charge::class) ?? false),
                NavigationItem::make('Configuración')
                    ->sort(6)
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn () => self::firstSettingsUrl())
                    ->visible(fn () => self::firstSettingsUrl() !== null),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.panel-styles'))
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
                Route::get('copias-de-seguridad/{file}', BackupDownloadController::class)->name('backups.download');
            });
    }

    /** "Configuración" lleva a lo primero que la persona puede ver (al admin, a Ajustes; al doctor, a sus tratamientos). */
    private static function firstSettingsUrl(): ?string
    {
        foreach (self::SETTINGS as $class) {
            if ($class::canAccess()) {
                return is_subclass_of($class, Resource::class) ? $class::getUrl('index') : $class::getUrl();
            }
        }

        return null;
    }
}
