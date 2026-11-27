<?php

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Tenancy\CurrentContext;

/*
| Thin global accessors over the CurrentContext singleton.
*/

if (! function_exists('current_context')) {
    function current_context(): CurrentContext
    {
        return app(CurrentContext::class);
    }
}

if (! function_exists('current_school_id')) {
    function current_school_id(): int
    {
        return app(CurrentContext::class)->schoolId();
    }
}

if (! function_exists('current_branch_id')) {
    function current_branch_id(): ?int
    {
        return app(CurrentContext::class)->branchId();
    }
}

if (! function_exists('current_session_id')) {
    function current_session_id(): ?int
    {
        return app(CurrentContext::class)->sessionId();
    }
}

if (! function_exists('current_branch')) {
    function current_branch(): ?Branch
    {
        return app(CurrentContext::class)->branch();
    }
}

if (! function_exists('current_session')) {
    function current_session(): ?AcademicSession
    {
        return app(CurrentContext::class)->session();
    }
}
