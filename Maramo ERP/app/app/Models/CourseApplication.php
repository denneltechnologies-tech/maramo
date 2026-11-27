<?php

namespace App\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\SchoolClass;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseApplication extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const STATUSES = [
        'pending' => 'Pending Review',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'enrolled' => 'Enrolled',
    ];

    public const SOURCES = [
        'website' => 'Website Form',
        'walk_in' => 'Walk-in / In-person',
        'whatsapp' => 'WhatsApp / Phone Inquiry',
        'social_media' => 'Social Media (IG/TikTok)',
    ];

    public const SCHEDULES = [
        'morning' => 'Morning Cohort',
        'afternoon' => 'Afternoon Cohort',
        'weekend' => 'Weekend Batch',
        'flexible' => 'Flexible / Part-Time',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'session_id',
        'class_id',
        'application_no',
        'first_name',
        'last_name',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'address',
        'city',
        'prior_experience',
        'preferred_schedule',
        'source',
        'status',
        'notes',
        'reviewed_by',
        'reviewed_at',
        'student_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public static function generateApplicationNumber(): string
    {
        $year = date('Y');
        $prefix = "MBI-APP-{$year}-";
        $last = static::withoutGlobalScopes()
            ->where('application_no', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('application_no');

        if (! $last) {
            return "{$prefix}0001";
        }

        $seq = (int) substr($last, strlen($prefix));

        return $prefix . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
    }
}
