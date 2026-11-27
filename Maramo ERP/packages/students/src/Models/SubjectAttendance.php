<?php

namespace Classivo\Students\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Period / subject-wise attendance — one row per (student, subject, date, period). */
class SubjectAttendance extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const STATUSES = Attendance::STATUSES;

    protected $fillable = [
        'school_id', 'branch_id', 'session_id', 'student_id', 'class_section_id',
        'subject_id', 'date', 'period_no', 'status', 'remark', 'marked_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'period_no' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classSection(): BelongsTo
    {
        return $this->belongsTo(ClassSection::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'), 'marked_by');
    }
}
