<?php

namespace Classivo\ReportCards\Tests\Support;

use Classivo\ReportCards\ReportCardsPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')->plugin(ReportCardsPlugin::make());
    }
}
