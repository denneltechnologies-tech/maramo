<?php

namespace Classivo\Students\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Student master record.
 *
 * Tenant + branch scoped but deliberately NOT session-scoped: a student spans
 * many academic years. Per-year enrolment lives in {@see StudentSessionRecord};
 * the class/section/roll columns here mirror the CURRENT enrolment.
 */
class Student extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

    public const STATUSES = [
        'applicant' => 'Applicant',
        'active' => 'Active',
        'completed' => 'Completed',
        'withdrawn' => 'Withdrawn',
        'suspended' => 'Suspended',
    ];

    /** India (UDISE+) social category; ignored by non-India installs. */
    public const SOCIAL_CATEGORIES = ['general' => 'General', 'obc' => 'OBC', 'sc' => 'SC', 'st' => 'ST'];

    protected $fillable = [
        'school_id', 'branch_id', 'session_id',
        'class_id', 'section_id', 'class_section_id',
        'admission_no', 'roll_no', 'admission_date', 'student_category_id', 'registration_no',
        'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'blood_group',
        'religion', 'caste', 'mother_tongue', 'nationality', 'mobile', 'email', 'national_id', 'user_id',
        'photo_path',
        'current_address', 'permanent_address', 'city', 'state', 'country', 'pincode',
        'previous_school_name', 'previous_class', 'previous_school_address', 'tc_number', 'tc_date',
        'training_start_date', 'completion_date', 'emergency_contact_name', 'emergency_contact_phone',
        'rte', 'physically_challenged', 'special_needs_note', 'medical_conditions', 'note',
        // UDISE+ (India) — nullable, additive.
        'social_category', 'minority_group', 'is_bpl', 'is_ews', 'is_cwsn', 'disability_type',
        'admission_sibling_id', 'status', 'disabled_at', 'disable_note', 'disabled_by',
    ];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'training_start_date' => 'date',
            'completion_date' => 'date',
            'date_of_birth' => 'date',
            'tc_date' => 'date',
            'disabled_at' => 'datetime',
            'rte' => 'boolean',
            'physically_challenged' => 'boolean',
            'is_bpl' => 'boolean',
            'is_ews' => 'boolean',
            'is_cwsn' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Auto-fill the admission session (no SessionScope on reads, see above).
        static::creating(function (Student $student): void {
            if (empty($student->getAttribute('session_id')) && current_session_id() !== null) {
                $student->setAttribute('session_id', current_session_id());
            }
        });

        // Never purge a student with attendance history; soft-delete instead.
        static::forceDeleting(fn (Student $student): ?bool => $student->hasProtectedHistory() ? false : null);
    }

    public function hasProtectedHistory(): bool
    {
        return DB::table('attendances')->where('student_id', $this->getKey())->exists();
    }

    // Relationships ------------------------------------------------------

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function classSection(): BelongsTo
    {
        return $this->belongsTo(ClassSection::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function studentCategory(): BelongsTo
    {
        return $this->belongsTo(StudentCategory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'));
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian')
            ->withPivot(['id', 'is_primary', 'relation'])
            ->withTimestamps();
    }

    /** Session records in the ACTIVE session (SessionScope applies). */
    public function sessionRecords(): HasMany
    {
        return $this->hasMany(StudentSessionRecord::class);
    }

    /** Full enrolment history across every session. */
    public function allSessionRecords(): HasMany
    {
        return $this->hasMany(StudentSessionRecord::class)
            ->withoutGlobalScope(SessionScope::class)
            ->orderBy('session_id');
    }

    public function currentSessionRecord(): HasOne
    {
        return $this->hasOne(StudentSessionRecord::class)
            ->withoutGlobalScope(SessionScope::class)
            ->whereIn('session_id', array_filter([current_session_id()]));
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class)->latest('id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class)->orderBy('date');
    }

    public function subjectAttendances(): HasMany
    {
        return $this->hasMany(SubjectAttendance::class);
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'), 'disabled_by');
    }

    // Query helpers ------------------------------------------------------

    /** Students with an enrolment record in the given (default: active) session. */
    public function scopeEnrolledInSession(Builder $query, ?int $sessionId = null): Builder
    {
        $sessionId ??= current_session_id();

        if ($sessionId === null) {
            return $query;
        }

        return $query->whereHas('sessionRecords', function (Builder $q) use ($sessionId): void {
            $q->withoutGlobalScope(SessionScope::class)
                ->where('student_session_records.session_id', $sessionId);
        });
    }

    // Derived attributes -------------------------------------------------

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(implode(' ', array_filter([
            $this->first_name, $this->middle_name, $this->last_name,
        ]))));
    }

    /** The primary guardian (stable tie-break on pivot id), else the first one. */
    public function primaryGuardian(): ?Guardian
    {
        if ($this->relationLoaded('guardians')) {
            $loaded = $this->guardians->sortBy(fn (Guardian $g) => $g->pivot->id ?? 0);

            return $loaded->firstWhere(fn (Guardian $g): bool => (bool) $g->pivot->is_primary) ?? $loaded->first();
        }

        return $this->guardians()->wherePivot('is_primary', true)->orderBy('student_guardian.id')->first()
            ?? $this->guardians()->orderBy('student_guardian.id')->first();
    }

    /** Other students sharing this student's primary guardian. */
    public function siblings(): Collection
    {
        $guardian = $this->primaryGuardian();

        if (! $guardian) {
            return collect();
        }

        return $guardian->students()->where('students.id', '!=', $this->getKey())->get();
    }

    /** Class-section in the ACTIVE session (falls back to the denormalised column). */
    public function getCurrentClassSectionAttribute(): ?ClassSection
    {
        $record = $this->relationLoaded('currentSessionRecord')
            ? $this->getRelation('currentSessionRecord')
            : $this->currentSessionRecord;

        return $record?->classSection ?? $this->classSection;
    }

    public function getCurrentRollNoAttribute(): ?string
    {
        $record = $this->relationLoaded('currentSessionRecord')
            ? $this->getRelation('currentSessionRecord')
            : $this->currentSessionRecord;

        return $record?->roll_no ?? $this->roll_no;
    }

    // Disable / re-enable ------------------------------------------------

    public function disableStudent(?string $note = null, mixed $date = null, ?int $causerId = null): void
    {
        $this->discardChanges();

        $this->update([
            'status' => 'inactive',
            'disable_note' => $note,
            'disabled_at' => $date ?: now(),
            'disabled_by' => $causerId ?? auth()->id(),
        ]);
    }

    public function reEnable(): void
    {
        $this->discardChanges();

        $this->update([
            'status' => 'active',
            'disable_note' => null,
            'disabled_at' => null,
            'disabled_by' => null,
        ]);
    }
}
