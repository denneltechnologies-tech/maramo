<?php

namespace Classivo\Exams\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A grouping of exams (e.g. "Term 1") with an assessment type (marks / gpa / both). */
class ExamGroup extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const TYPES = [
        'marks' => 'Marks',
        'gpa' => 'Grade (GPA)',
        'both' => 'Marks & Grade',
    ];

    /** @return array<string, string> translated type options */
    public static function typeOptions(): array
    {
        return array_map(fn (string $k): string => __("classivo-exams::exams.type.{$k}"), array_combine(array_keys(self::TYPES), array_keys(self::TYPES)));
    }

    protected $fillable = ['school_id', 'branch_id', 'session_id', 'name', 'type', 'description', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }
}
