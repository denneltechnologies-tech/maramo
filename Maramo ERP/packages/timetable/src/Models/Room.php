<?php

namespace Classivo\Timetable\Models;

use Classivo\Core\Concerns\BelongsToBranch;
use Classivo\Core\Concerns\BelongsToTenant;
use Classivo\Timetable\Generator\Room as GeneratorRoom;
use Illuminate\Database\Eloquent\Model;

/** A shared room the generator can book (e.g. a lab). Its name is saved on the period. */
class Room extends Model
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'timetable_rooms';

    protected $fillable = [
        'school_id',
        'branch_id',
        'name',
        'type',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function toGeneratorRoom(): GeneratorRoom
    {
        return new GeneratorRoom($this->name, $this->type);
    }
}
