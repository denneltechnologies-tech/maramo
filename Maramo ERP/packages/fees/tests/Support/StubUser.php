<?php

namespace Classivo\Fees\Tests\Support;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Spatie\Permission\Traits\HasRoles;

class StubUser extends User implements FilamentUser
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
