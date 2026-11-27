<?php

namespace Classivo\Students\Models;

use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document on a student's file (birth certificate, TC ...). */
class StudentDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'student_id', 'title', 'file_path', 'remark'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
