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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** The parent portal (/parent): read-only results of the signed-in guardian's children. */
class GuardianPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('parent')
            ->path('parent')
            ->login()
            ->brandName('Classivo ERP – Parent')
            ->colors(['primary' => Color::Amber])
            ->plugins(Modules::plugins(Modules::GUARDIAN_PLUGINS))
            ->pages([Dashboard::class])
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => Blade::render(
                '<div class="text-sm flex gap-2 px-2"><a href="?lang=en">English</a><span>|</span><a href="?lang=hi">हिन्दी</a></div>'
            ))
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
