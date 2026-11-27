<?php

namespace Classivo\Core\Tenancy;

/**
 * TenantResolver — the tenancy contract.
 *
 * Classivo models are scoped to a school (`school_id`). WHICH school a request
 * belongs to is decided by an implementation of this interface:
 *
 *   - {@see SingleSchoolResolver} (the default, bound by CoreServiceProvider)
 *     answers with one configured school id, i.e. a single-school install.
 *   - A multi-school product binds its own resolver (from the logged-in user,
 *     a sub-domain, a header ...) in a service provider:
 *
 *         $this->app->bind(TenantResolver::class, MyResolver::class);
 *
 * Returning NULL means "no school could be resolved"; tenant-scoped queries
 * then match nothing instead of leaking another school's rows.
 */
interface TenantResolver
{
    public function schoolId(): ?int;
}
