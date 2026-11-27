<?php

namespace Classivo\ReportCards\Services;

use Classivo\Core\Models\School;
use Classivo\ReportCards\DTO\Institution;

/** Builds the document header from the current tenancy context. */
final class InstitutionResolver
{
    public function current(): Institution
    {
        $school = School::find(current_school_id());
        $branch = current_branch();

        return new Institution(
            (string) ($school?->name ?? config('app.name')),
            $branch?->name,
            $branch?->address ?? null,
            $branch?->phone ?? null,
        );
    }

    public function school(): ?School
    {
        return School::find(current_school_id());
    }
}
