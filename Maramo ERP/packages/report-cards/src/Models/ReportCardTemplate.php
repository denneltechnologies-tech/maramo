<?php

namespace Classivo\ReportCards\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToSession;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A named print template (marksheet / report card / admit card / tabulation). */
class ReportCardTemplate extends Model
{
    use BelongsToBranch, BelongsToSession, BelongsToTenant, SoftDeletes;

    public const TYPES = [
        'marksheet' => 'Marksheet',
        'report_card' => 'Report Card',
        'admit_card' => 'Admit Card',
        'tabulation' => 'Tabulation Sheet',
    ];

    protected $fillable = [
        'school_id', 'branch_id', 'session_id', 'name', 'type', 'layout', 'header_html',
        'footer_html', 'page_size', 'orientation', 'is_default', 'status',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'status' => 'boolean'];
    }
}
