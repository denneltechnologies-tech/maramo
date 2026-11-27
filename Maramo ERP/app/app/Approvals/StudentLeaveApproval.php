<?php

namespace App\Approvals;

use Classivo\AcademicsExtras\Models\LeaveRequest;
use Classivo\AcademicsExtras\Services\LeaveRequestService;
use Classivo\AcademicsExtras\Support\AcademicsPermissions;
use Classivo\Platform\Contracts\ApprovalType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Student leave requests (classivo/academics-extras) in the platform
 * approvals inbox. Decisions go through LeaveRequestService, so the same
 * rules and events apply as on the leave screen.
 */
class StudentLeaveApproval implements ApprovalType
{
    public function key(): string
    {
        return 'student_leave';
    }

    public function label(): string
    {
        return 'Student leave';
    }

    public function permission(): string
    {
        return AcademicsPermissions::LEAVE_APPROVE;
    }

    public function pending(): Builder
    {
        return LeaveRequest::query()->where('status', 'pending')->with(['student', 'leaveType'])->orderBy('from_date');
    }

    public function lockedFind(int $id): ?Model
    {
        return LeaveRequest::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function isPending(Model $item): bool
    {
        return $item instanceof LeaveRequest && $item->isPending();
    }

    public function describe(Model $item): string
    {
        /** @var LeaveRequest $item */
        return sprintf(
            '%s (%s): %s to %s',
            $item->student?->full_name ?? '#'.$item->student_id,
            $item->student?->admission_no ?? '-',
            $item->from_date?->format('d M Y'),
            $item->to_date?->format('d M Y'),
        );
    }

    public function approve(Model $item, Authenticatable $actor, ?string $remark): void
    {
        app(LeaveRequestService::class)->approve($item, (int) $actor->getAuthIdentifier(), $remark);
    }

    public function reject(Model $item, Authenticatable $actor, string $remark): void
    {
        app(LeaveRequestService::class)->reject($item, (int) $actor->getAuthIdentifier(), $remark);
    }
}
