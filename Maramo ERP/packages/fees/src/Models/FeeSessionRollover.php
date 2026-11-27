<?php

namespace Classivo\Fees\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\AcademicSession;
use Classivo\Fees\Filament\Pages\FeesSessionRollover;
use Classivo\Fees\Support\Money;
use Classivo\Fees\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FeeSessionRollover — one committed run of the fee session rollover: which
 * structure was copied where, on what terms, and what it produced.
 *
 * WHY IT EXISTS. Every write in the rollover is idempotent, so a second press is
 * already harmless — but "harmless" is invisible, and an operator who cannot tell
 * whether last week's rollover ran will run it again on a live school and hold their
 * breath. This register lets {@see FeesSessionRollover} say so
 * plainly before anything is written, and leaves finance an answer to "who re-priced
 * next year, when, and by how much".
 *
 * NOT session-scoped: a rollover spans two sessions and belongs to neither, so
 * pinning it to one would hide it from the other. Tenant + branch scoped.
 *
 * NOT soft-deleting either, deliberately — this is an audit trail. A row saying "42
 * heads were priced up 8% on 4 Sep" must not be removable from the panel; if the
 * rollover itself was wrong, the fee masters are what gets corrected.
 */
class FeeSessionRollover extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'branch_id',
        'from_session_id',
        'to_session_id',
        'percent',
        'rounding',
        'rounding_mode',
        'shift_months',
        'groups_created',
        'masters_created',
        'masters_skipped',
        'masters_blocked',
        'students_carried',
        'amount_carried',
        'committed_by',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'rounding' => 'integer',
            'shift_months' => 'integer',
            'groups_created' => 'integer',
            'masters_created' => 'integer',
            'masters_skipped' => 'integer',
            'masters_blocked' => 'integer',
            'students_carried' => 'integer',
            'amount_carried' => 'decimal:2',
        ];
    }

    public function fromSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'from_session_id');
    }

    public function toSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'to_session_id');
    }

    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'committed_by');
    }

    /** "8.00% · nearest 10 · +12 months" — the terms, for the page's warning line. */
    public function termsLabel(): string
    {
        $rounding = $this->rounding > 0
            ? 'nearest '.Money::symbol().$this->rounding.' ('.$this->rounding_mode.')'
            : 'no rounding';

        return number_format((float) $this->percent, 2).'% · '.$rounding.' · '.
            ($this->shift_months >= 0 ? '+' : '').$this->shift_months.' months';
    }
}
