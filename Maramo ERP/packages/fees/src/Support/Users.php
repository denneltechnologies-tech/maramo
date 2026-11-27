<?php

namespace Classivo\Fees\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the host application's user model (config `classivo.user_model`).
 * Fee rows reference users as collectors, approvers and requesters; the
 * package never ships its own User class.
 */
final class Users
{
    /** @return class-string<Model> */
    public static function model(): string
    {
        return (string) config('classivo.user_model', 'App\\Models\\User');
    }
}
