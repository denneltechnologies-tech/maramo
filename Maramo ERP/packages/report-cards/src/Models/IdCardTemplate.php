<?php

namespace Classivo\ReportCards\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Form-driven ID card design: which fields to print, toggles, colours. */
class IdCardTemplate extends Model
{
    use BelongsToBranch, BelongsToTenant, SoftDeletes;

    public const TYPES = ['student' => 'Student', 'staff' => 'Staff'];

    public const STUDENT_FIELDS = [
        'admission_no' => 'Admission No', 'class_section' => 'Class-Section', 'roll_no' => 'Roll No',
        'father_name' => 'Father Name', 'mother_name' => 'Mother Name', 'date_of_birth' => 'Date of Birth',
        'blood_group' => 'Blood Group', 'address' => 'Address', 'mobile' => 'Phone', 'session' => 'Session',
    ];

    public const STAFF_FIELDS = [
        'employee_id' => 'Staff ID', 'designation' => 'Designation', 'department' => 'Department',
        'date_of_birth' => 'Date of Birth', 'blood_group' => 'Blood Group', 'phone' => 'Phone',
        'joining_date' => 'Joining Date',
    ];

    protected $fillable = [
        'school_id', 'branch_id', 'name', 'type', 'fields', 'show_photo', 'show_qr', 'show_logo',
        'header_color', 'text_color', 'orientation', 'card_size', 'logo_path', 'background_path',
        'is_default', 'status',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array', 'show_photo' => 'boolean', 'show_qr' => 'boolean',
            'show_logo' => 'boolean', 'is_default' => 'boolean', 'status' => 'boolean',
        ];
    }

    /** @return array<string,string> */
    public function availableFields(): array
    {
        return $this->type === 'staff' ? self::STAFF_FIELDS : self::STUDENT_FIELDS;
    }
}
