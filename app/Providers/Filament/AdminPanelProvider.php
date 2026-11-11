<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Backups;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Filament\Pages\WebSetup;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Resources\SmileCases\SmileCaseResource;
use App\Filament\Resources\TextSnippets\TextSnippetResource;
use App\Filament\Resources\TreatmentPackages\TreatmentPackageResource;
use App\Filament\Resources\Treatments\TreatmentResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\MobileBar;
use App\Http\Controllers\Backup\BackupDownloadController;
use App\Http\Controllers\Billing\PaymentReceiptController;
use App\Http\Controllers\Clinical\ClinicalFileController;
use App\Http\Controllers\Clinical\PrintController;
use App\Http\Controllers\Reports\CashClosingController;
use App\Http\Controllers\Reports\EarningsExportController;
use App\Http\Middleware\KeepRemindersUpToDate;
use App\Models\Setting;
use App\Services\Web\ClinicInfo;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
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

    /** @var list<class-string> Todo lo de la página web, en un solo lugar: primero su puesta a punto. */
    private const WEB = [
        WebSetup::class,
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

    /**
     * La escala de Filament corrida un tono hacia lo oscuro. Con la de fábrica, el verde de "Confirmar" o
     * "WhatsApp" no llegaba al contraste del texto blanco y salía fosforescente con letras negras, y el azul
     * de "Llegó" era eléctrico al lado del azul de la marca. Así quedan sobrios y con texto blanco.
     *
     * @param  array<int, string>  $palette
     * @return array<int, string>
     */
    private static function oneShadeDarker(array $palette): array
    {
        return [
            50 => $palette[50], 100 => $palette[100], 200 => $palette[200], 300 => $palette[300], 400 => $palette[500],
            500 => $palette[600], 600 => $palette[700], 700 => $palette[800], 800 => $palette[900], 900 => $palette[950], 950 => $palette[950],
        ];
    }

    /**
     * Lo que vale para todas las pantallas: cada "Nuevo …" abre una ventana con ese mismo nombre y se guarda con
     * "Guardar"; y una fecha con hora se elige con el calendario del panel y se lee dd/mm/aaaa hh:mm, sea cual sea
     * el idioma de la computadora (el del navegador la mostraba mm/dd/aaaa en una en inglés). Las fechas solas
     * (nacimiento, por ejemplo) siguen con el del navegador, que deja escribirlas de corrido.
     */
    public function boot(): void
    {
        CreateAction::configureUsing(fn (CreateAction $action) => $action
            ->modalHeading(fn (CreateAction $action): string => (string) $action->getLabel())
            ->modalSubmitActionLabel('Guardar'));

        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker instanceof DatePicker ? $picker : $picker
            ->native(false)
            ->displayFormat('d/m/Y H:i')
            ->firstDayOfWeek(1));
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('panel')
            ->unsavedChangesAlerts()
            ->login()
            // "Mi perfil" en el menú del usuario: cada uno cambia su propia clave sin pedírsela al administrador.
            ->profile(isSimple: false)
            ->brandName(fn () => Setting::get('clinic.name'))
            // Con logo subido en la puesta a punto de la web, el panel lo muestra; si no, el diente de la web junto al nombre.
            ->brandLogo(fn () => app(ClinicInfo::class)->logoUrl() ?? view('filament.brand', ['name' => Setting::get('clinic.name')]))
            ->brandLogoHeight('2.25rem')
            ->colors([
                // El azul del recetario, el mismo de la página web, en el tono 600 (el de los botones, con texto blanco).
                'primary' => self::brandPalette(),
                // Grises azulados en vez de neutros: el claro acompaña al azul de la marca y el oscuro es azul noche, no negro.
                'gray' => Color::Slate,
                'success' => self::oneShadeDarker(Color::Emerald),
                'info' => self::oneShadeDarker(Color::Sky),
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
                    ->sort(1)
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn () => route('site.home'), shouldOpenInNewTab: true)
                    ->visible(fn () => self::firstUrl(self::WEB) !== null),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.panel-styles'))
            // Quién está conectado y con qué rol: junto a la foto y al abrir su menú.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => auth()->check() ? view('filament.user-badge', ['user' => auth()->user()]) : '')
            ->renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE, fn () => auth()->check() ? view('filament.user-menu-header', ['user' => auth()->user()]) : '')
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
                KeepRemindersUpToDate::class,
            ])
            ->authenticatedRoutes(function () {
                Route::get('imprimir/receta/{prescription}', [PrintController::class, 'prescription'])->name('print.prescription');
                Route::get('imprimir/plan/{plan}', [PrintController::class, 'plan'])->name('print.plan');
                Route::post('imprimir/plan/{plan}/entregado', [PrintController::class, 'planDelivered'])->name('print.plan.delivered');
                Route::get('imprimir/consentimiento/{consent}', [PrintController::class, 'consent'])->name('print.consent');
                Route::get('imprimir/historia/{patient}', [PrintController::class, 'history'])->name('print.history');
                Route::get('archivos/{file}', [ClinicalFileController::class, 'show'])->name('clinical-files.show');
                Route::get('reportes/ingresos/imprimir', [EarningsExportController::class, 'print'])->name('reports.earnings.print');
                Route::get('reportes/ingresos/excel', [EarningsExportController::class, 'excel'])->name('reports.earnings.excel');
                Route::get('caja/cierre/imprimir', [CashClosingController::class, 'print'])->name('cash-closing.print');
                Route::get('imprimir/constancia/{payment}', [PaymentReceiptController::class, 'show'])->name('print.receipt');
                Route::post('imprimir/constancia/{payment}/whatsapp', [PaymentReceiptController::class, 'whatsapp'])->name('print.receipt.whatsapp');
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
