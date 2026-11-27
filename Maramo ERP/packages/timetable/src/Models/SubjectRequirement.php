<?php

namespace Classivo\Timetable\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Weekly periods (and required room type) of a subject for a class, or for one
 * section of it (section_id null = all sections; a section row wins).
 */
class SubjectRequirement extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant;

    protected $table = 'timetable_subject_requirements';

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'class_id',
        'section_id',
        'subject_id',
        'weekly_periods',
        'room_type',
    ];

    protected function casts(): array
    {
        return [
            'weekly_periods' => 'integer',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
