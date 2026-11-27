<?php

namespace Classivo\Students\Tests\Support;

use Classivo\Students\StudentsPlugin;
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
            ->plugin(StudentsPlugin::make());
    }
}
