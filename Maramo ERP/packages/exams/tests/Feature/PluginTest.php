<?php

namespace Classivo\Exams\Tests\Feature;

use Classivo\Exams\ExamsPlugin;
use Classivo\Exams\Tests\TestCase;
use Filament\Panel;

class PluginTest extends TestCase
{
    public function test_plugin_registers_resources_and_pages_that_autoload(): void
    {
        $panel = Panel::make()->id('t')->plugin(new ExamsPlugin);

        $this->assertNotEmpty($panel->getResources());
        $this->assertNotEmpty($panel->getPages());

        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            $this->assertTrue(class_exists($class), "{$class} must autoload");
        }
    }
}
