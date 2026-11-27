<?php

namespace Classivo\Fees\Tests\Support;

use Classivo\Fees\FeesPlugin;
use Classivo\Fees\FeesPortalPlugin;
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
            ->plugin(FeesPlugin::make())
            ->plugin(FeesPortalPlugin::guardian());
    }
}
