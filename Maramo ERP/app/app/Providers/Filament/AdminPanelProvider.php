<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
use App\Support\Modules;
use Classivo\Core\Http\Middleware\ResolveTenantContext;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Maramo Beauty Institute')
            ->databaseNotifications()
            ->profile()
            ->maxContentWidth(\Filament\Support\Enums\MaxWidth::Full)
            ->font('Plus Jakarta Sans', provider: \Filament\FontProviders\GoogleFontProvider::class)
            ->colors([
                'primary' => [
                    50 => '#fbf8f5',
                    100 => '#f5ede6',
                    200 => '#ebd7c9',
                    300 => '#ddbaa4',
                    400 => '#cb977a',
                    500 => '#be7b56',
                    600 => '#aa6544',
                    700 => '#8d5038',
                    800 => '#744230',
                    900 => '#60392b',
                    950 => '#351c14',
                ],
                'gray' => Color::Stone,
            ])
            ->navigationGroups([
                'Admissions & CRM',
                'Student Management',
                'Courses & Intakes',
                'Fees & Payments',
                'Graduation & Certificates',
                'Instructors & Staff',
                'Reports & Analytics',
                'Institute Settings',
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => view('filament.custom-theme-head')
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn () => view('filament.custom-sidebar-brand')
            )
            ->plugins(Modules::plugins(Modules::ADMIN_PLUGINS))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->pages([Dashboard::class])
            ->widgets([AccountWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
                ResolveTenantContext::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
