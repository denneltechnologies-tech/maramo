<?php

namespace Classivo\DemoGuard\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
