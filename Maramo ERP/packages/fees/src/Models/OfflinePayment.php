<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Fees\Support\Users;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * OfflinePayment — a student/parent submitted
 * payment (with proof) awaiting admin review. Approving posts a real fee_payment and
 * links it via `fee_payment_id`. Tenant + branch + session scoped, soft-deleting.
 */
class OfflinePayment extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'student_id',
        'amount',
        'payment_date',
        'mode',
        'reference_no',
        'attachment_path',
        'note',
        'status',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'fee_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'reviewed_by');
    }
}
