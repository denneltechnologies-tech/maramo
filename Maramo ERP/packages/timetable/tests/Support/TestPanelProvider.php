<?php

namespace Classivo\Timetable\Tests\Support;

use Classivo\Timetable\TimetablePlugin;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')->plugin(TimetablePlugin::make());
    }
}
