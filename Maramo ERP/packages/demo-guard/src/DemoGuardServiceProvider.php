<?php

namespace Classivo\DemoGuard;

use Classivo\DemoGuard\Http\Middleware\LimitDemoUploads;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class DemoGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/demo-guard.php', 'demo-guard');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'demo-guard');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'demo-guard');

        if (! DemoGuard::enabled()) {
            return;
        }

        $this->guardModels();
        $this->app['router']->pushMiddlewareToGroup('web', LimitDemoUploads::class);

        if (config('demo-guard.banner.enabled', true)) {
            FilamentView::registerRenderHook(
                PanelsRenderHook::BODY_START,
                fn () => view('demo-guard::banner', ['resetTime' => config('demo-guard.banner.reset_time')])->render(),
            );
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
            fn (): string => self::loginsHtml(Filament::getCurrentPanel()?->getId()),
        );
    }

    /** Demo sign-in details for a panel's login page ('' when none are configured). */
    public static function loginsHtml(?string $panelId): string
    {
        $logins = $panelId ? (array) config('demo-guard.logins.'.$panelId, []) : [];

        return $logins === [] ? '' : view('demo-guard::logins', ['logins' => $logins])->render();
    }

    /** Users holding a read-only role cannot write any record outside the allow-list. */
    private function guardReadOnlyRoles(): void
    {
        $roles = (array) config('demo-guard.read_only.roles', []);
        if ($roles === []) {
            return;
        }
        $userModel = config('demo-guard.users.model') ?: config('auth.providers.users.model');

        Event::listen(['eloquent.creating: *', 'eloquent.updating: *', 'eloquent.deleting: *'], function (string $event, array $payload) use ($roles, $userModel) {
            $model = $payload[0] ?? null;
            if (! $model instanceof Model || ! DemoGuard::active()) {
                return;
            }
            $user = Auth::user();
            if (! $user || ! method_exists($user, 'hasAnyRole') || ! $user->hasAnyRole($roles)) {
                return;
            }
            foreach ((array) config('demo-guard.read_only.allow_models', []) as $allowed) {
                if ($model instanceof $allowed) {
                    return;
                }
            }
            if ($userModel && $model instanceof $userModel && str_starts_with($event, 'eloquent.updating')
                && array_diff(array_keys($model->getDirty()), (array) config('demo-guard.read_only.allow_user_attributes', [])) === []) {
                return;
            }
            DemoGuard::deny(__('demo-guard::messages.read_only'));
        });
    }

    private function guardModels(): void
    {
        $this->guardReadOnlyRoles();

        $userModel = config('demo-guard.users.model') ?: config('auth.providers.users.model');
        if ($userModel && class_exists($userModel)) {
            $protected = (array) config('demo-guard.users.protected_attributes', []);
            $userModel::updating(function (Model $m) use ($protected) {
                if (DemoGuard::active() && $m->isDirty($protected)) {
                    DemoGuard::deny(__('demo-guard::messages.user_locked'));
                }
            });
            if (config('demo-guard.users.block_delete', true)) {
                $userModel::deleting(fn () => DemoGuard::active() ? DemoGuard::deny(__('demo-guard::messages.user_locked')) : null);
            }
        }

        foreach ((array) config('demo-guard.role_models', []) as $class) {
            if (! class_exists($class)) {
                continue;
            }
            $class::updating(fn () => DemoGuard::active() ? DemoGuard::deny(__('demo-guard::messages.roles_locked')) : null);
            $class::deleting(fn () => DemoGuard::active() ? DemoGuard::deny(__('demo-guard::messages.roles_locked')) : null);
        }

        foreach ((array) config('demo-guard.locked_models', []) as $class) {
            if (! class_exists($class)) {
                continue;
            }
            foreach (['creating', 'updating', 'deleting'] as $event) {
                $class::$event(fn () => DemoGuard::active() ? DemoGuard::deny(__('demo-guard::messages.setting_locked')) : null);
            }
        }
    }
}
