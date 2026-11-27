<?php

namespace Classivo\Students\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Parent / guardian. Shared guardians ARE the sibling relationship. */
class Guardian extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const RELATIONS = ['father' => 'Father', 'mother' => 'Mother', 'other' => 'Other'];

    protected $fillable = [
        'school_id', 'branch_id', 'name', 'relation', 'phone', 'email',
        'occupation', 'address', 'annual_income', 'user_id',
    ];

    protected function casts(): array
    {
        return ['annual_income' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('classivo.user_model'));
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardian')
            ->withPivot(['id', 'is_primary', 'relation'])
            ->withTimestamps();
    }
}
