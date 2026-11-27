<?php

namespace App\Models\Concerns;

use Classivo\Security\Concerns\Auditable;

// Audit trail from classivo/security when that package is installed; otherwise nothing.
if (trait_exists(Auditable::class)) {
    trait AuditsWhenInstalled
    {
        use Auditable;
    }
} else {
    trait AuditsWhenInstalled {}
}
