<?php

declare(strict_types=1);

namespace Classivo\Grading\Laravel;

use Classivo\Grading\Presets\Presets;
use Classivo\Grading\Schemes\PercentageScheme;
use Classivo\Grading\Schemes\SchemeRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Optional Laravel glue. Only loaded when illuminate/support is installed (package
 * auto-discovery). Binds a SchemeRegistry singleton built from config/grading.php:
 * the chosen preset, plus any custom schemes from config, plus the default key.
 */
final class GradingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/grading.php', 'grading');

        $this->app->singleton(SchemeRegistry::class, static function ($app): SchemeRegistry {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('grading', []);

            return self::buildRegistry($config);
        });
        $this->app->alias(SchemeRegistry::class, 'classivo.grading');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'grading');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/grading.php' => $this->app->configPath('grading.php'),
            ], 'grading-config');
        }
    }

    /** @param array<string, mixed> $config */
    public static function buildRegistry(array $config): SchemeRegistry
    {
        $registry = Presets::make($config['preset'] ?? Presets::DEFAULT);

        foreach ($config['schemes'] ?? [] as $key => $scheme) {
            if (is_string($key) && ! isset($scheme['key'])) {
                $scheme['key'] = $key;
            }
            $registry->register(PercentageScheme::fromArray($scheme));
        }

        if (! empty($config['default'])) {
            $registry->setDefault((string) $config['default']);
        }

        return $registry;
    }
}
