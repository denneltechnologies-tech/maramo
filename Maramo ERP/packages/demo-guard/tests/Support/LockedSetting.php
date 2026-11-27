<?php

namespace Classivo\DemoGuard\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** Test model listed in demo-guard.locked_models. */
class LockedSetting extends Model
{
    protected $table = 'demo_guard_locked_settings';

    protected $guarded = [];
}
