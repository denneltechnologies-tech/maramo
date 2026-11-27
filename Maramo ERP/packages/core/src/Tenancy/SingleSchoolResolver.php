<?php

namespace Classivo\Core\Tenancy;

/**
 * Default resolver: the whole install is one school, id from
 * `config('classivo.school_id')`.
 */
class SingleSchoolResolver implements TenantResolver
{
    public function schoolId(): ?int
    {
        return (int) config('classivo.school_id', 1);
    }
}
