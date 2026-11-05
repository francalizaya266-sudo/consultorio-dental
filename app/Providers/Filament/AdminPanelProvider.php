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
use App\Filament\Support\MobileBar;
use App\Http\Controllers\Backup\BackupDownloadController;
use App\Http\Controllers\Clinical\ClinicalFileController;
use App\Http\Controllers\Clinical\PrintController;
use App\Http\Controllers\Reports\CashClosingController;
use App\Http\Controllers\Reports\EarningsExportController;
use App\Models\Charge;
use App\Models\Setting;
use App\Services\Web\ClinicInfo;
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
        Backups::class,
    ];

    /** @var list<class-string> Lo que se publica en la página web, aparte de la configuración de la clínica. */
    private const WEB = [
        CampaignResource::class,
        SmileCaseResource::class,
        ReviewResource::class,
        FaqResource::class,
    ];

    /**
     * Escala del azul de la marca armada a mano: Color::hex() deja #1e4f91 en el tono 800 y los
     * botones (tono 600) salían celestes con texto oscuro.
     *
     * @return array<int, string>
     */
    private static function brandPalette(): array
    {
        $shades = [
            50 => '#eef4fb', 100 => '#dbe6f4', 200 => '#b9cde8', 300 => '#8eadd8', 400 => '#5f87c2', 500 => '#3a68a9',
            600 => '#1e4f91', 700 => '#173e73', 800 => '#133260', 900 => '#10294f', 950 => '#0b1f3a',
        ];

        return array_map(fn (string $hex): string => Color::convertToOklch($hex), $shades);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('panel')
            ->unsavedChangesAlerts()
            ->login()
            ->brandName(fn () => Setting::get('clinic.name'))
            // Con logo subido en Ajustes, el panel lo muestra en lugar del nombre.
            ->brandLogo(fn () => app(ClinicInfo::class)->logoUrl())
            ->brandLogoHeight('2.25rem')
            ->colors([
                // El azul del recetario, el mismo de la página web, en el tono 600 (el de los botones, con texto blanco).
                'primary' => self::brandPalette(),
            ])
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->strictAuthorization()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->sidebarCollapsibleOnDesktop()
            // Ctrl + K (o ⌘ + K) abre el buscador desde cualquier pantalla.
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchFieldKeyBindingSuffix()
            // Nueve entradas en el menú; lo demás aparece dentro de cada una al entrar.
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
                    ->sort(7)
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn () => self::firstUrl(self::SETTINGS))
                    ->visible(fn () => self::firstUrl(self::SETTINGS) !== null),
                NavigationItem::make('Página web')
                    ->sort(8)
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->url(fn () => self::firstUrl(self::WEB))
                    ->visible(fn () => self::firstUrl(self::WEB) !== null),
                NavigationItem::make('Ver la página')
                    ->parentItem('Página web')
                    ->sort(9)
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn () => route('site.home'), shouldOpenInNewTab: true)
                    ->visible(fn () => self::firstUrl(self::WEB) !== null),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.panel-styles'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => auth()->check() ? view('filament.mobile-bar', ['items' => MobileBar::items()]) : '')
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
                Route::get('caja/cierre/imprimir', [CashClosingController::class, 'print'])->name('cash-closing.print');
                Route::get('copias-de-seguridad/{file}', BackupDownloadController::class)->name('backups.download');
            });
    }

    /**
     * Una entrada del menú lleva a lo primero de su grupo que la persona puede ver
     * (en "Configuración": al admin, a Ajustes; al doctor, a sus tratamientos).
     *
     * @param  list<class-string>  $classes
     */
    private static function firstUrl(array $classes): ?string
    {
        foreach ($classes as $class) {
            if ($class::canAccess()) {
                return is_subclass_of($class, Resource::class) ? $class::getUrl('index') : $class::getUrl();
            }
        }

        return null;
    }
}
