<?php

namespace Classivo\ReportCards\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Editable certificate design: HTML `content` with {{variable}} shortcodes. */
class CertificateTemplate extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const TYPES = ['student' => 'Student', 'staff' => 'Staff', 'custom' => 'Custom'];

    protected $fillable = [
        'school_id', 'branch_id', 'name', 'type', 'content', 'background_path',
        'page_size', 'orientation', 'is_default', 'status',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'status' => 'boolean'];
    }

    public function generatedCertificates(): HasMany
    {
        return $this->hasMany(GeneratedCertificate::class);
    }
}
