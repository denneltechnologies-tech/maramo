<?php

declare(strict_types=1);

namespace Classivo\Grading\Tests\Laravel;

use Classivo\Grading\Laravel\GradingServiceProvider;
use Classivo\Grading\Schemes\SchemeRegistry;
use Orchestra\Testbench\TestCase;

final class GradingServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [GradingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('grading.schemes', [
            'state.example' => [
                'pass_percentage' => 35,
                'bands' => [
                    ['label' => 'P', 'min' => 35, 'max' => 100],
                    ['label' => 'F', 'min' => 0, 'max' => 34.99, 'is_fail' => true],
                ],
            ],
        ]);
        $app['config']->set('grading.default', 'state.example');
    }

    public function test_registry_is_bound_with_preset_and_custom_scheme(): void
    {
        $reg = $this->app->make(SchemeRegistry::class);
        self::assertSame($reg, $this->app->make('classivo.grading'));
        self::assertTrue($reg->has('cbse.9-12'));
        self::assertSame('state.example', $reg->defaultKey());
        self::assertFalse($reg->get()->grade(34)->passed);
        self::assertSame('A1', $reg->get('cbse.9-12')->grade(95)->label());
    }

    public function test_translations_loaded(): void
    {
        self::assertSame('Grace marks', __('grading::grading.grace_marks'));
        $this->app->setLocale('hi');
        self::assertSame('कृपांक', __('grading::grading.grace_marks'));
    }

    public function test_build_registry_neutral_and_override(): void
    {
        $reg = GradingServiceProvider::buildRegistry([
            'preset' => 'neutral',
            'schemes' => [[
                'key' => 'generic.letter',
                'name' => 'Overridden',
                'bands' => [['label' => 'OK', 'min' => 0, 'max' => 100]],
            ]],
        ]);
        self::assertSame(['generic.letter'], $reg->keys());
        self::assertSame('Overridden', $reg->get()->name());
    }

    public function test_default_preset_is_india(): void
    {
        self::assertSame('cbse.9-12', GradingServiceProvider::buildRegistry([])->defaultKey());
    }
}
