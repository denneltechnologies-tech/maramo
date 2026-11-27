<?php

namespace Classivo\Core\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SchoolClass — the grade/standard master (table `classes`; PHP reserves
 * `class`). Divided into Sections through the class_sections pivot; each
 * (class, section) pair is a canonical {@see ClassSection}.
 */
class SchoolClass extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    protected $table = 'classes';

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'name',
        'numeric_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'numeric_order' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'class_sections', 'class_id', 'section_id')
            ->withPivot(['id', 'capacity'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function classSections(): HasMany
    {
        return $this->hasMany(ClassSection::class, 'class_id');
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class, 'class_id');
    }

    public function classTeachers(): HasManyThrough
    {
        return $this->hasManyThrough(ClassTeacher::class, ClassSection::class, 'class_id', 'class_section_id', 'id', 'id');
    }

    /**
     * Idempotently reconcile this class's sections into class_sections:
     * desired ones are created (or restored), the rest soft-deleted.
     *
     * @param  array<int, int|string>  $sectionIds
     */
    public function syncSections(array $sectionIds): void
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));

        foreach ($sectionIds as $sectionId) {
            $existing = ClassSection::withTrashed()
                ->where('class_id', $this->getKey())
                ->where('section_id', $sectionId)
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                continue;
            }

            ClassSection::create([
                'school_id' => $this->school_id,
                'branch_id' => $this->branch_id,
                'session_id' => $this->session_id,
                'class_id' => $this->getKey(),
                'section_id' => $sectionId,
            ]);
        }

        ClassSection::where('class_id', $this->getKey())
            ->when($sectionIds !== [], fn ($query) => $query->whereNotIn('section_id', $sectionIds))
            ->get()
            ->each->delete();
    }
}
