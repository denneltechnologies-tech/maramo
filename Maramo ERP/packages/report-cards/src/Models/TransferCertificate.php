<?php

namespace Classivo\ReportCards\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\ReportCards\Models\Concerns\HasNumberSeries;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An issued Transfer Certificate with an auto, unique tc_number series. */
class TransferCertificate extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, HasNumberSeries, SoftDeletes;

    protected $fillable = [
        'school_id', 'branch_id', 'session_id', 'student_id', 'tc_number', 'date_of_leaving',
        'reason_for_leaving', 'conduct', 'remarks', 'last_class_attended', 'total_working_days',
        'days_present', 'dues_cleared', 'promotion_status', 'template', 'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_leaving' => 'date', 'total_working_days' => 'integer',
            'days_present' => 'integer', 'dues_cleared' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public static function nextNumber(?int $schoolId = null): string
    {
        return static::nextInSeries('tc_number', (string) config('report-cards.transfer_certificate_prefix', 'TC'), 4, $schoolId);
    }
}
