<?php

namespace Classivo\Hr\Tests\Support;

use Classivo\Core\CorePlugin;
use Classivo\Core\Http\Middleware\ResolveTenantContext;
use Classivo\Hr\HrPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugins([CorePlugin::make(), HrPlugin::make()])
            ->middleware([ResolveTenantContext::class]);
    }
}
