<?php

namespace Classivo\Students\Services;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Students\Events\StudentPromoted;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promotes a student into the next session ("continue") or marks them as
 * leaving ("leave"). History is never rewritten: the old session record is
 * stamped promoted/left and a new record is appended for the next session.
 *
 * Fee carry-forward is NOT done here; the {@see StudentPromoted} event carries
 * the admin's choice so a fees module can act on it.
 */
class StudentPromotionService
{
    /**
     * @param  array{from_session_id:int, result?:?string, action?:string, next_session_id?:int,
     *               next_class_id?:?int, next_section_id?:?int, new_roll?:?string, carry_forward_fees?:bool}  $data
     */
    public function promoteStudent(Student $student, array $data): ?StudentSessionRecord
    {
        $carryForward = (bool) ($data['carry_forward_fees'] ?? false);

        $record = DB::transaction(function () use ($student, $data, $carryForward): ?StudentSessionRecord {
            $fromSessionId = (int) $data['from_session_id'];
            $leaving = ($data['action'] ?? 'continue') === 'leave';

            $current = $student->sessionRecords()
                ->withoutGlobalScope(SessionScope::class)
                ->where('session_id', $fromSessionId)
                ->latest('id')
                ->first();

            $current?->update([
                'result' => $data['result'] ?? null,
                'status' => $leaving ? 'left' : 'promoted',
            ]);

            if ($leaving) {
                $student->update(['status' => 'inactive']);

                return null;
            }

            $nextSessionId = (int) $data['next_session_id'];
            $nextClassId = $data['next_class_id'] ?? null;
            $nextSectionId = $data['next_section_id'] ?? null;
            $classSectionId = $this->nextClassSectionId($nextSessionId, $nextClassId, $nextSectionId);

            // Never write a null class_section_id: the student would vanish from
            // every roster. Roll the class structure over first.
            if ($classSectionId === null) {
                throw ValidationException::withMessages([
                    'rows' => [__('classivo-students::students.promotion.missing_class_section')],
                ]);
            }

            $record = StudentSessionRecord::withoutGlobalScope(SessionScope::class)->updateOrCreate(
                ['student_id' => $student->id, 'session_id' => $nextSessionId],
                [
                    'school_id' => $student->school_id,
                    'branch_id' => $student->branch_id,
                    'class_id' => $nextClassId,
                    'section_id' => $nextSectionId,
                    'class_section_id' => $classSectionId,
                    'roll_no' => $data['new_roll'] ?? null,
                    'status' => 'studying',
                    'result' => null,
                    'remark' => $carryForward ? 'Carry-forward of dues requested.' : null,
                ],
            );

            $student->update([
                'session_id' => $nextSessionId,
                'class_id' => $nextClassId,
                'section_id' => $nextSectionId,
                'class_section_id' => $classSectionId,
                'roll_no' => $data['new_roll'] ?? $student->roll_no,
                'status' => 'active',
            ]);

            return $record;
        });

        StudentPromoted::dispatch(
            $student,
            (int) $data['from_session_id'],
            isset($data['next_session_id']) ? (int) $data['next_session_id'] : null,
            $record,
            $carryForward,
        );

        return $record;
    }

    public function nextClassSectionId(int $sessionId, mixed $classId, mixed $sectionId): ?int
    {
        if (! $classId || ! $sectionId) {
            return null;
        }

        return ClassSection::withoutGlobalScope(SessionScope::class)
            ->where('session_id', $sessionId)
            ->where('class_id', (int) $classId)
            ->where('section_id', (int) $sectionId)
            ->value('id');
    }
}
