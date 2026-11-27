<?php

namespace Classivo\Core\Tenancy;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Tenancy\Scopes\TenantScope;
use Throwable;

/**
 * CurrentContext — the request-scoped tenancy context (singleton).
 *
 * Holds the three levels every Classivo record is filed under:
 *
 *   1. school_id  — from the bound {@see TenantResolver} (or an explicit
 *                   {@see setSchoolId()} override, e.g. in tests/console)
 *   2. branch_id  — the campus (selected one, else the school's default)
 *   3. session_id — the academic year (selected one, else the current one)
 *
 * It is hydrated once per web request by the ResolveTenantContext middleware.
 * In the console (seeders, tinker, queue) the middleware never runs, so the
 * context stays UNRESOLVED: global scopes leave reads untouched, but writes
 * still auto-fill school/branch/session so seeded rows are correctly filed.
 */
class CurrentContext
{
    protected ?int $schoolId = null;

    protected ?int $branchId = null;

    protected ?int $sessionId = null;

    /** Whether a real request context has been resolved (middleware ran). */
    protected bool $resolved = false;

    protected ?Branch $branch = null;

    protected bool $branchLoaded = false;

    protected ?AcademicSession $session = null;

    protected bool $sessionLoaded = false;

    public function __construct(protected TenantResolver $resolver) {}

    // ---------------------------------------------------------------------
    // Resolution state
    // ---------------------------------------------------------------------

    public function markResolved(bool $resolved = true): static
    {
        $this->resolved = $resolved;

        return $this;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    // ---------------------------------------------------------------------
    // School (level 1)
    // ---------------------------------------------------------------------

    /**
     * The active school id, or NULL when the resolver could not name one.
     */
    public function schoolIdOrNull(): ?int
    {
        return $this->schoolId ?? $this->resolver->schoolId();
    }

    /**
     * The active school id for callers that need an int. 0 never matches a
     * real auto-increment id, so a query filtered on it returns nothing.
     */
    public function schoolId(): int
    {
        return $this->schoolIdOrNull() ?? 0;
    }

    /**
     * Pin the school explicitly (tests, console commands, a multi-school
     * resolver's middleware). NULL hands control back to the resolver.
     */
    public function setSchoolId(?int $schoolId): static
    {
        if ($schoolId !== $this->schoolId) {
            // A branch/session id belongs to the school it was picked in.
            $this->branchId = null;
            $this->sessionId = null;
            $this->branch = null;
            $this->branchLoaded = false;
            $this->session = null;
            $this->sessionLoaded = false;
        }

        $this->schoolId = $schoolId;

        return $this;
    }

    // ---------------------------------------------------------------------
    // Branch (level 2)
    // ---------------------------------------------------------------------

    public function branchId(): ?int
    {
        return $this->branchId ?? $this->branch()?->id;
    }

    public function setBranchId(?int $branchId): static
    {
        if ($branchId !== $this->branchId) {
            $this->branch = null;
            $this->branchLoaded = false;
        }

        $this->branchId = $branchId;

        return $this;
    }

    /** The active Branch (selected one, else the school's default/first). */
    public function branch(): ?Branch
    {
        if ($this->branchLoaded) {
            return $this->branch;
        }

        $this->branchLoaded = true;

        try {
            $query = Branch::withoutGlobalScope(TenantScope::class)
                ->where('school_id', $this->schoolId())
                ->where('status', true);

            $this->branch = $this->branchId !== null
                ? ((clone $query)->find($this->branchId) ?? (clone $query)->orderByDesc('is_default')->orderBy('id')->first())
                : $query->orderByDesc('is_default')->orderBy('id')->first();
        } catch (Throwable) {
            // Table may not exist yet (early migrate) — fail soft.
            $this->branch = null;
        }

        return $this->branch;
    }

    // ---------------------------------------------------------------------
    // Academic session (level 3)
    // ---------------------------------------------------------------------

    public function sessionId(): ?int
    {
        return $this->sessionId ?? $this->session()?->id;
    }

    public function setSessionId(?int $sessionId): static
    {
        if ($sessionId !== $this->sessionId) {
            $this->session = null;
            $this->sessionLoaded = false;
        }

        $this->sessionId = $sessionId;

        return $this;
    }

    /** The active AcademicSession (selected one, else the current one). */
    public function session(): ?AcademicSession
    {
        if ($this->sessionLoaded) {
            return $this->session;
        }

        $this->sessionLoaded = true;

        try {
            $query = AcademicSession::withoutGlobalScope(TenantScope::class)
                ->where('school_id', $this->schoolId());

            $this->session = $this->sessionId !== null
                ? ((clone $query)->find($this->sessionId) ?? (clone $query)->orderByDesc('is_current')->orderByDesc('id')->first())
                : $query->orderByDesc('is_current')->orderByDesc('id')->first();
        } catch (Throwable) {
            $this->session = null;
        }

        return $this->session;
    }

    /** True when the active session is NOT the school's current session. */
    public function isViewingNonCurrentSession(): bool
    {
        $session = $this->session();

        return $session !== null && ! $session->is_current;
    }
}
