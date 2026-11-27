<?php

namespace Classivo\Hr\Console;

use Classivo\Core\Models\School;
use Classivo\Hr\Services\Payroll\StatutoryRuleSetProvisioner;
use Illuminate\Console\Command;

/**
 * Creates the active preset's statutory rule set (all kinds DISABLED) for the
 * current financial year. Idempotent.
 */
class InstallStatutoryPreset extends Command
{
    protected $signature = 'hr:install-statutory-preset {--school= : School id (default: every school)}';

    protected $description = 'Install the regional preset statutory rule set (every kind disabled) for the current financial year';

    public function handle(StatutoryRuleSetProvisioner $provisioner): int
    {
        $schools = $this->option('school')
            ? School::withoutGlobalScopes()->whereKey((int) $this->option('school'))->pluck('id')
            : School::withoutGlobalScopes()->orderBy('id')->pluck('id');

        foreach ($schools as $id) {
            $setId = $provisioner->ensureFor((int) $id);
            $this->info("School {$id}: rule set #{$setId}");
        }

        return self::SUCCESS;
    }
}
