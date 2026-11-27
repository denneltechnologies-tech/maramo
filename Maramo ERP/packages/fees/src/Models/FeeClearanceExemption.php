<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Fees\Support\FeeClearancePolicy;
use Classivo\Fees\Support\Users;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeClearanceExemption — one approved hardship override of the fee clearance
 * gate ({@see FeeClearancePolicy}).
 *
 * A row here releases a DOCUMENT, never a DEBT: the allocations stay exactly as
 * they are, the student keeps appearing on every defaulter report, and the money
 * is still owed. That separation is the point — a school that had to write the
 * balance off to let a child sit an exam would be destroying its own receivables
 * figure to work around a gate.
 *
 * Tenant-scoped, soft-deleting, so who granted an exemption,
 * when, for how long and why is recoverable afterwards. Deleting one is
 * reversible; the gate simply closes again.
 */
class FeeClearanceExemption extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'student_id',
        'service',
        'reason',
        'approved_by',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'approved_by');
    }

    /**
     * Exemptions that are in force for `$service` on `$onDate`.
     *
     * A NULL `service` covers every gated service — the ordinary "this family is
     * exempt, full stop" case — so the test is "unrestricted OR this one", not an
     * equality. NULL dates are unbounded at that end.
     */
    public function scopeInForce(Builder $query, string $service, string $onDate): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('service')->orWhere('service', $service))
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $onDate))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $onDate));
    }

    /** Human summary for the audit trail and the "released by" note on a document. */
    public function describe(): string
    {
        $window = match (true) {
            $this->valid_until !== null => ' until '.$this->valid_until->format('d M Y'),
            default => '',
        };

        return trim($this->reason).$window;
    }
}
