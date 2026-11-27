<?php

namespace Classivo\Students\Services;

use Classivo\Core\Models\Branch;
use Classivo\Core\Models\ClassSection;
use Classivo\Students\Events\StudentAdmitted;
use Classivo\Students\Models\Guardian;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentDocument;
use Classivo\Students\Models\StudentSessionRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Admits a student in one transaction:
 *
 *   1. resolve the canonical class-section from (class, section)
 *   2. admission number from the branch series (or a manual value)
 *   3. roll number (explicit, else auto max+1)
 *   4. the student row + its current-session enrolment record
 *   5. guardians: typed father/mother/guardian blocks, a sibling's guardians,
 *      or an existing guardian (search by phone) — exactly one primary
 *   6. documents
 *
 * Then fires {@see StudentAdmitted}. Logins, fees and notifications are left to
 * listeners so this package has no dependency on those modules.
 */
class StudentAdmissionService
{
    public function __construct(private AdmissionNumberGenerator $admissionNumbers) {}

    /** @param  array<string, mixed>  $data */
    public function admit(array $data): Student
    {
        $student = DB::transaction(function () use ($data): Student {
            $schoolId = current_school_id();

            $branch = isset($data['branch_id']) ? Branch::find($data['branch_id']) : null;
            $branch ??= current_branch();
            $branchId = $branch?->id ?? current_branch_id();

            $sessionId = $data['session_id'] ?? current_session_id();

            $classId = $data['class_id'] ?? null;
            $sectionId = $data['section_id'] ?? null;
            $classSectionId = $this->resolveClassSectionId($classId, $sectionId);

            $manual = (bool) ($data['admission_no_manual'] ?? false);
            $admissionNo = ($manual && ! empty($data['admission_no']))
                ? (string) $data['admission_no']
                : $this->admissionNumbers->next($branch, $schoolId);

            $rollNo = $this->resolveRoll($data, $classSectionId, $sessionId);

            $student = Student::create(array_merge(Arr::only($data, $this->studentColumns()), [
                'school_id' => $schoolId,
                'branch_id' => $branchId,
                'session_id' => $sessionId,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'class_section_id' => $classSectionId,
                'admission_no' => $admissionNo,
                'roll_no' => $rollNo,
                'admission_date' => $data['admission_date'] ?? now()->toDateString(),
                'status' => $data['status'] ?? 'active',
            ]));

            StudentSessionRecord::create([
                'school_id' => $schoolId,
                'branch_id' => $branchId,
                'session_id' => $sessionId,
                'student_id' => $student->id,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'class_section_id' => $classSectionId,
                'roll_no' => $rollNo,
                'status' => 'studying',
            ]);

            $this->handleGuardians($student, $data, $schoolId, $branchId);
            $this->storeDocuments($student, $data['documents'] ?? [], $schoolId);

            return $student->refresh();
        });

        StudentAdmitted::dispatch($student, $data);

        return $student;
    }

    /** @return array<int, string> */
    private function studentColumns(): array
    {
        return [
            'student_category_id', 'registration_no',
            'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth',
            'blood_group', 'religion', 'caste', 'mother_tongue', 'nationality',
            'mobile', 'email', 'national_id', 'photo_path',
            'current_address', 'permanent_address', 'city', 'state', 'country', 'pincode',
            'previous_school_name', 'previous_class', 'previous_school_address', 'tc_number', 'tc_date',
            'rte', 'physically_challenged', 'special_needs_note', 'medical_conditions', 'note',
            'social_category', 'minority_group', 'is_bpl', 'is_ews', 'is_cwsn', 'disability_type',
        ];
    }

    private function resolveClassSectionId(mixed $classId, mixed $sectionId): ?int
    {
        if (! $classId || ! $sectionId) {
            return null;
        }

        return ClassSection::where('class_id', $classId)->where('section_id', $sectionId)->value('id');
    }

    /** @param  array<string, mixed>  $data */
    private function resolveRoll(array $data, ?int $classSectionId, ?int $sessionId): ?string
    {
        if (! empty($data['roll_no'])) {
            return (string) $data['roll_no'];
        }

        if (! ($data['roll_no_auto'] ?? true) || $classSectionId === null) {
            return null;
        }

        return $this->admissionNumbers->nextRoll($classSectionId, $sessionId);
    }

    /** @param  array<string, mixed>  $data */
    private function handleGuardians(Student $student, array $data, int $schoolId, ?int $branchId): ?Guardian
    {
        // A typed primary-guardian block ALWAYS wins over inherited/linked ones.
        $typedPrimary = filled($data['guardian']['name'] ?? null);

        if ($siblingId = $data['sibling_student_id'] ?? null) {
            $inherited = $this->copyFromSibling($student, (int) $siblingId, demoteToSecondary: $typedPrimary);

            return $typedPrimary ? $this->createGuardians($student, $data, $schoolId, $branchId) : $inherited;
        }

        if ($existingId = $data['link_existing_guardian_id'] ?? null) {
            $existing = Guardian::find($existingId);

            if ($existing) {
                $student->guardians()->syncWithoutDetaching([
                    $existing->id => ['is_primary' => ! $typedPrimary, 'relation' => $existing->relation],
                ]);

                $otherStudentId = $existing->students()->where('students.id', '!=', $student->id)->value('students.id');

                if ($otherStudentId) {
                    $student->forceFill(['admission_sibling_id' => $otherStudentId])->save();
                }

                if ($typedPrimary) {
                    return $this->createGuardians($student, $data, $schoolId, $branchId);
                }

                $this->createParentGuardians($student, $data, $schoolId, $branchId);

                return $existing;
            }
        }

        return $this->createGuardians($student, $data, $schoolId, $branchId);
    }

    private function copyFromSibling(Student $student, int $siblingId, bool $demoteToSecondary = false): ?Guardian
    {
        $sibling = Student::find($siblingId);

        if (! $sibling) {
            return null;
        }

        foreach ($sibling->guardians as $guardian) {
            $student->guardians()->syncWithoutDetaching([
                $guardian->id => [
                    'is_primary' => $demoteToSecondary ? false : (bool) $guardian->pivot->is_primary,
                    'relation' => $guardian->pivot->relation,
                ],
            ]);
        }

        $copy = [];

        foreach (['current_address', 'permanent_address', 'city', 'state', 'country', 'pincode'] as $field) {
            if (blank($student->{$field}) && filled($sibling->{$field})) {
                $copy[$field] = $sibling->{$field};
            }
        }

        $copy['admission_sibling_id'] = $sibling->id;
        $student->forceFill($copy)->save();

        return $demoteToSecondary ? null : $student->primaryGuardian();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, Guardian> keyed by phone
     */
    private function createParentGuardians(Student $student, array $data, int $schoolId, ?int $branchId): array
    {
        $byPhone = [];

        foreach (['father', 'mother'] as $relation) {
            $block = $data[$relation] ?? [];

            if (empty($block['name'])) {
                continue;
            }

            $guardian = Guardian::create([
                'school_id' => $schoolId,
                'branch_id' => $branchId,
                'name' => $block['name'],
                'relation' => $relation,
                'phone' => $block['phone'] ?? null,
                'email' => $block['email'] ?? null,
                'occupation' => $block['occupation'] ?? null,
            ]);

            $student->guardians()->syncWithoutDetaching([$guardian->id => ['is_primary' => false, 'relation' => $relation]]);

            if (! empty($block['phone'])) {
                $byPhone[(string) $block['phone']] = $guardian;
            }
        }

        return $byPhone;
    }

    /** @param  array<string, mixed>  $data */
    private function createGuardians(Student $student, array $data, int $schoolId, ?int $branchId): ?Guardian
    {
        $byPhone = $this->createParentGuardians($student, $data, $schoolId, $branchId);

        $block = $data['guardian'] ?? [];
        $primary = null;

        if (! empty($block['name'])) {
            $relation = $block['relation'] ?? 'other';
            $phone = $block['phone'] ?? null;

            // Reuse the father/mother row when the primary shares its phone.
            if ($phone && isset($byPhone[(string) $phone])) {
                $primary = $byPhone[(string) $phone];
                $student->guardians()->updateExistingPivot($primary->id, ['is_primary' => true, 'relation' => $relation]);
            } else {
                $primary = Guardian::create([
                    'school_id' => $schoolId,
                    'branch_id' => $branchId,
                    'name' => $block['name'],
                    'relation' => $relation,
                    'phone' => $phone,
                    'email' => $block['email'] ?? null,
                    'occupation' => $block['occupation'] ?? null,
                    'address' => $block['address'] ?? null,
                    'annual_income' => $block['annual_income'] ?? null,
                ]);

                $student->guardians()->syncWithoutDetaching([$primary->id => ['is_primary' => true, 'relation' => $relation]]);
            }
        }

        if (! $primary) {
            $primary = $student->guardians()->orderBy('student_guardian.id')->first();

            if ($primary) {
                $student->guardians()->updateExistingPivot($primary->id, ['is_primary' => true]);
            }
        }

        // Exactly one primary per student.
        if ($primary) {
            DB::table('student_guardian')
                ->where('student_id', $student->id)
                ->where('guardian_id', '!=', $primary->id)
                ->update(['is_primary' => false]);
        }

        return $primary;
    }

    /** @param  array<int, array<string, mixed>>  $documents */
    private function storeDocuments(Student $student, array $documents, int $schoolId): void
    {
        foreach ($documents as $document) {
            if (empty($document['title'])) {
                continue;
            }

            $file = $document['file'] ?? null;

            StudentDocument::create([
                'school_id' => $schoolId,
                'student_id' => $student->id,
                'title' => $document['title'],
                'file_path' => is_array($file) ? (reset($file) ?: null) : $file,
                'remark' => $document['remark'] ?? null,
            ]);
        }
    }
}
