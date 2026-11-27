<?php

namespace Classivo\Core\Tests\Support;

use Classivo\Core\CorePlugin;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')->plugin(CorePlugin::make());
    }
}
