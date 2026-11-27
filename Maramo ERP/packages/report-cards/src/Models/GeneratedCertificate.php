<?php

namespace Classivo\ReportCards\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\ReportCards\Models\Concerns\HasNumberSeries;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One issued certificate. `data` holds the substituted variables and a snapshot of
 * the rendered HTML so the PDF stays identical even if the template is edited later.
 */
class GeneratedCertificate extends Model
{
    use BelongsToBranch, BelongsToTenant, HasNumberSeries, SoftDeletes;

    protected $fillable = [
        'school_id', 'branch_id', 'certificate_template_id', 'student_id', 'staff_id',
        'certificate_number', 'data', 'pdf_path', 'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'issued_at' => 'datetime'];
    }

    public function certificateTemplate(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class);
    }

    public function renderedHtml(): string
    {
        return (string) ($this->data['html'] ?? '');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public static function nextNumber(?int $schoolId = null): string
    {
        return static::nextInSeries('certificate_number', (string) config('report-cards.certificate_prefix', 'CERT'), 5, $schoolId);
    }
}
