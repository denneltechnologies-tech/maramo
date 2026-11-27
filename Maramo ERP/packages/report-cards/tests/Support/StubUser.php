<?php

namespace Classivo\ReportCards\Tests\Support;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;

class StubUser extends User implements FilamentUser
{
    protected $table = 'users';

    protected $guarded = [];

    /** @var list<string> */
    public static array $grants = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function can($abilities, $arguments = []): bool
    {
        return in_array($abilities, self::$grants, true);
    }
}
