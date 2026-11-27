<?php

namespace Classivo\Hr\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Hr\Filament\Resources\Staff\Actions\GuardedStaffDeleteActions;
use Classivo\Hr\Observers\StaffObserver;
use Classivo\Hr\Services\EmployeeIdGenerator;
use Classivo\Hr\Support\HrAuth;
use Classivo\Hr\Support\RoleGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Staff (spec §15 "Staff Directory" / §32 HR) — the core employee record. Tenant +
 * branch scoped, soft-deleting, activity-logged, photo via medialibrary.
 *
 * `employee_id` is auto-generated from a prefix series on create when left blank
 * (see {@see EmployeeIdGenerator}), unique per school. A staff member MAY link to a
 * `users` login (user_id) — {@see provisionLogin()} creates/attaches that account and
 * assigns the staff's system role. The Payroll-tab fields (basic_salary + the
 * default allowances/deductions structure) drive monthly payroll generation.
 */
#[ObservedBy(StaffObserver::class)]
class Staff extends Model implements HasMedia
{
    use BelongsToBranch, BelongsToTenant, InteractsWithMedia, SoftDeletes;

    protected $table = 'staff';

    public const GENDERS = [
        'male' => 'Male',
        'female' => 'Female',
        'other' => 'Other',
    ];

    public const CONTRACT_TYPES = [
        'permanent' => 'Permanent',
        'contract' => 'Contract',
        'part_time' => 'Part-time',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ];

    /** UDISE+ highest academic qualification (India). */
    public const ACADEMIC_QUALIFICATIONS = [
        'below_secondary' => 'Below Secondary',
        'secondary' => 'Secondary',
        'higher_secondary' => 'Higher Secondary',
        'graduate' => 'Graduate',
        'post_graduate' => 'Post Graduate',
        'mphil' => 'M.Phil',
        'phd' => 'Ph.D',
    ];

    /** UDISE+ professional qualification. */
    public const PROFESSIONAL_QUALIFICATIONS = [
        'none' => 'None',
        'diploma' => 'Diploma / Certificate in education',
        'bed' => 'B.Ed',
        'med' => 'M.Ed',
        'other' => 'Other',
    ];

    /** UDISE+ nature of appointment. */
    public const APPOINTMENT_TYPES = [
        'regular' => 'Regular',
        'contract' => 'Contract',
        'para_teacher' => 'Para-teacher',
        'other' => 'Other',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'employee_id',
        'user_id',
        'role_id',
        'department_id',
        'designation_id',
        // Personal
        'first_name',
        'last_name',
        'father_name',
        'mother_name',
        'gender',
        'blood_group',
        'marital_status',
        'date_of_birth',
        'joining_date',
        // Contact
        'phone',
        'emergency_contact',
        'email',
        'current_address',
        'permanent_address',
        'qualification',
        'work_experience',
        'note',
        // UDISE+ (India) — additive, nullable; non-India schools ignore these.
        'is_teaching_staff',
        'academic_qualification',
        'professional_qualification',
        'appointment_type',
        'classes_taught',
        'subjects_taught',
        'trained_in_computer',
        'in_service_training_days',
        'staff_disability_type',
        // Payroll
        'epf_no',
        'esi_no',
        'basic_salary',
        'contract_type',
        'work_shift',
        'work_location',
        'salary_grade',
        'allowances',
        'deductions',
        // Bank
        'account_title',
        'bank_account_no',
        'bank_name',
        'bank_branch',
        'bank_ifsc',
        'tax_id',
        // Social
        'facebook_url',
        'twitter_url',
        'linkedin_url',
        'instagram_url',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'joining_date' => 'date:Y-m-d',
            'basic_salary' => 'decimal:2',
            'allowances' => 'array',
            'deductions' => 'array',
            // UDISE+ (India)
            'is_teaching_staff' => 'boolean',
            'trained_in_computer' => 'boolean',
            'in_service_training_days' => 'integer',
        ];
    }

    /** Auto-generate a unique employee_id from the prefix series when none is set. */
    protected static function booted(): void
    {
        // A role change needs hr.staff.assign_roles and may only grant a role the actor
        // already holds (see RoleGuard) — the form hides the picker, this is the backstop.
        static::saving(function (Staff $staff): void {
            if ($staff->isDirty('role_id') && ($staff->exists || $staff->role_id !== null)
                && ! RoleGuard::canGrant($staff->role_id !== null ? (int) $staff->role_id : null)) {
                throw new AuthorizationException('You may not assign this role.');
            }
        });

        static::creating(function (Staff $staff): void {
            if (blank($staff->getAttribute('employee_id'))) {
                $staff->setAttribute('employee_id', app(EmployeeIdGenerator::class)->next($staff->school_id ?? current_school_id()));
            }
        });

        /**
         * NO FORCE DELETE WHILE THE EMPLOYEE HAS SALARY HISTORY.
         *
         * `payrolls.staff_id` is ON DELETE CASCADE, so purging a staff row does
         * not just remove the person: MySQL hard-deletes every payroll they ever
         * had, PAID ONES INCLUDED, in a statement Eloquent never sees — no model
         * events, no activity-log entry, no soft-deleted row to recover from, and
         * the payslips go with them through `payroll_details`. Five payrolls,
         * four of them paid and disbursed, went that way in one click with no
         * confirmation of what was at stake. Advance salaries cascade the same
         * way, taking the record of money the school has already handed over.
         *
         * That is not a deletion, it is the destruction of financial records the
         * school is required to keep and an auditor will ask for. An employee who
         * has left is ARCHIVED (soft-deleted) — which the Staff screen already
         * offers, which keeps their payslips reachable, and which is reversible.
         *
         * Mirrors the guards on branches and fee masters: the model
         * hook is the correctness backstop that also covers console commands,
         * seeders and the API, and it can only return false — so
         * {@see GuardedStaffDeleteActions}
         * exists alongside it to turn the refusal into a sentence a human can act
         * on. Neither is redundant.
         */
        static::forceDeleting(fn (self $staff): ?bool => $staff->hasProtectedPayrollHistory() ? false : null);
    }

    /**
     * True when purging this employee would cascade-destroy salary history.
     *
     * Queried table-first, and with no soft-delete filter, so it is correct
     * regardless of tenant scope and counts the trashed rows too — those are
     * exactly the recoverable history a force delete would burn.
     *
     * @return array{payrolls: int, paid: int, paid_total: float, advances: int}
     */
    public function payrollHistorySummary(): array
    {
        $id = $this->getKey();

        $payrolls = DB::table('payrolls')->where('staff_id', $id);

        return [
            'payrolls' => (clone $payrolls)->count(),
            'paid' => (clone $payrolls)->where('status', 'paid')->count(),
            'paid_total' => (float) ((clone $payrolls)->where('status', 'paid')->sum('net_salary') ?? 0),
            'advances' => (int) DB::table('advance_salaries')->where('staff_id', $id)->count(),
        ];
    }

    /** Shorthand for {@see payrollHistorySummary()} — is there anything to protect? */
    public function hasProtectedPayrollHistory(): bool
    {
        $summary = $this->payrollHistorySummary();

        return $summary['payrolls'] > 0 || $summary['advances'] > 0;
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** The staff member's default system role (assigned to the login on provision). */
    public function role(): BelongsTo
    {
        return $this->belongsTo(HrAuth::roleModel() ?? static::class, 'role_id');
    }

    /** The optional linked login account. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(HrAuth::userModel());
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StaffDocument::class);
    }

    /**
     * Documents whose expiry falls inside the alert window, or has already passed —
     * the visa/certification/police-check chase list for this member. Ordered
     * soonest first, so an already-expired paper leads.
     */
    public function expiringDocuments(int $withinDays = StaffDocument::ALERT_WINDOW_DAYS): HasMany
    {
        return $this->documents()->expiringWithin($withinDays)->orderBy('expires_at');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StaffAttendance::class)->orderBy('date');
    }

    public function leaveAllocations(): HasMany
    {
        return $this->hasMany(StaffLeaveAllocation::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(StaffLeaveRequest::class)->latest('from_date');
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    /**
     * The employee's salary structure — which heads they draw, how much, and
     * from when.
     *
     * Replaces the `allowances` / `deductions` JSON columns, which are kept on
     * the record as the rollback path and as the resolver's fallback for anybody
     * these rows do not cover. The rows are EFFECTIVE-DATED, so payroll can ask
     * what the salary was during the month it is paying rather than what it is
     * today — the difference between regenerating March correctly and quietly
     * restating it at April's figure.
     *
     * A raise is therefore a NEW row (close the old one with `effective_to`),
     * never an edit of the existing one.
     */
    public function salaryStructures(): HasMany
    {
        return $this->hasMany(StaffSalaryStructure::class)->orderBy('effective_from');
    }

    /** Structure rows in force on a date — the payroll period's last day, usually. */
    public function salaryStructureOn(string $date): HasMany
    {
        return $this->salaryStructures()->inForce($date);
    }

    public function advanceSalaries(): HasMany
    {
        return $this->hasMany(AdvanceSalary::class);
    }

    // ---------------------------------------------------------------------
    // Derived attributes / helpers
    // ---------------------------------------------------------------------

    /** "First Last" collapsed of any missing part. */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(implode(' ', array_filter([
            $this->first_name,
            $this->last_name,
        ]))));
    }

    /**
     * Leave balance for a staff_leave_type = allocated days − approved-request days
     * (spec §15 "Balance check against allocation"). Optionally scoped to a session.
     */
    public function leaveBalance(int $staffLeaveTypeId, ?int $sessionId = null): float
    {
        $allocated = (float) $this->leaveAllocations()
            ->where('staff_leave_type_id', $staffLeaveTypeId)
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->sum('days_allocated');

        $used = $this->leaveRequests()
            ->where('staff_leave_type_id', $staffLeaveTypeId)
            ->where('status', 'approved')
            ->get()
            ->sum(fn (StaffLeaveRequest $r): int => $r->days);

        return $allocated - $used;
    }

    /**
     * Create (or re-link) a login account for this staff member and assign its system
     * role (spec §15 Staff "Login enabled toggle"). The Staff record is the single
     * source of truth for a linked login's identity: its name/email/phone are pushed
     * onto the User here (and kept in sync by {@see StaffObserver}).
     *
     * Idempotent and safe:
     *   • an already-linked user is reused; otherwise a user with the same email is
     *     adopted ONLY when it is not already another staff member's login (so we
     *     never hijack an unrelated account), else a fresh user is created;
     *   • the role is assigned ADDITIVELY — a manually granted role (e.g. Super Admin
     *     or an extra Librarian role set in the Users screen) is never stripped. Role
     *     transitions (a changed role_id) are reconciled by {@see StaffObserver}.
     *   • re-enabling a previously disabled login reactivates it.
     *
     * Returns the User.
     */
    public function provisionLogin(?string $password = null, ?int $roleId = null): Model
    {
        $roleId ??= $this->role_id;

        $userClass = HrAuth::userModel();
        $user = $this->user;

        if ($user === null) {
            // Never adopt an existing account by email: that would let whoever can
            // enable logins take over (and reset the password of) any user, Admin
            // included. A taken email is a refusal, not a link.
            if ($this->email && $userClass::where('email', $this->email)->exists()) {
                throw new \DomainException(__('hr::messages.staff.email_in_use'));
            }

            $user = new $userClass;
            $user->fill([
                'name' => $this->full_name,
                'email' => $this->email ?: $this->employee_id.'@staff.invalid',
                'phone' => $this->phone,
                'school_id' => $this->school_id,
                'is_active' => true,
            ]);
            $user->password = Hash::make($password ?: Str::random(20));
        } else {
            // An existing linked login is only reactivated: its password and email
            // are never changed from the staff record (use the password-reset flow).
            $user->forceFill(['is_active' => true]);
        }

        $user->save();

        if ($this->branch_id && method_exists($user, 'branches')) {
            $user->branches()->syncWithoutDetaching([$this->branch_id]);
        }

        // Additive, and only a role the acting user may grant.
        if ($roleId && method_exists($user, 'assignRole') && RoleGuard::canGrant((int) $roleId)
            && ($roleClass = HrAuth::roleModel()) && ($role = $roleClass::find($roleId)) && ! $user->hasRole($role->name)) {
            $user->assignRole($role->name);
        }

        $this->user_id = $user->id;
        $this->saveQuietly();

        return $user;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /** Photo URL from the medialibrary 'photo' collection (empty string if none). */
    public function getPhotoUrlAttribute(): string
    {
        return $this->getFirstMediaUrl('photo');
    }
}
