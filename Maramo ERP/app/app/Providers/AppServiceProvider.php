<?php

namespace App\Providers;

use App\Approvals\StudentLeaveApproval;
use Classivo\AcademicsExtras\Models\LeaveRequest;
use Classivo\Platform\Approvals\ApprovalRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Student leave (academics-extras) appears in the platform approvals inbox,
        // when both packages are installed.
        if (class_exists(ApprovalRegistry::class) && class_exists(LeaveRequest::class)) {
            $this->app->afterResolving(ApprovalRegistry::class, function (ApprovalRegistry $registry): void {
                $registry->register(new StudentLeaveApproval);
            });
        }
    }
}
