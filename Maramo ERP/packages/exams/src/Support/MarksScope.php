<?php

namespace Classivo\Exams\Support;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Models\ClassTeacher;
use Classivo\Core\Models\Subject;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which class-sections (and subjects) a user may enter marks for.
 *
 * A user with the `exams.marks.any_class` permission, or one of the roles in
 * `classivo-exams.marks_scope.bypass_roles` (Admin by default), may enter marks for
 * any class-section. Everyone else is limited to the class-sections they teach:
 *
 * - class teacher of the class-section (class_teachers): every subject;
 * - subject teacher in class_subjects (for the class, all sections or this section): that subject;
 * - teacher of a timetable period of the class-section (when the timetable package is
 *   installed): that subject.
 */
final class MarksScope
{
    public const ANY_CLASS = 'exams.marks.any_class';

    public static function bypasses(?Authenticatable $user): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'can') && $user->can(self::ANY_CLASS)) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole')) {
            $roles = (array) config('classivo-exams.marks_scope.bypass_roles', ['Admin', 'Super Admin']);

            return $roles !== [] && $user->hasAnyRole($roles);
        }

        return false;
    }

    /** May the user enter (or load) marks for this class-section and, when given, subject? */
    public static function allows(?Authenticatable $user, ClassSection $classSection, ?Subject $subject = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::bypasses($user)) {
            return true;
        }

        $userId = $user->getAuthIdentifier();

        if (ClassTeacher::query()->where('class_section_id', $classSection->getKey())->where('teacher_id', $userId)->exists()) {
            return true;
        }

        $subjectTeacher = ClassSubject::query()
            ->where('class_id', $classSection->class_id)
            ->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', $classSection->section_id))
            ->where('teacher_id', $userId)
            ->when($subject, fn ($q) => $q->where('subject_id', $subject->getKey()))
            ->exists();

        if ($subjectTeacher) {
            return true;
        }

        return self::timetableQuery($userId)
            ?->where('t.class_section_id', $classSection->getKey())
            ->when($subject, fn ($q) => $q->where('p.subject_id', $subject->getKey()))
            ->exists() ?? false;
    }

    /**
     * Class-section ids the user teaches, or null when the user may use any.
     *
     * @return list<int>|null
     */
    public static function classSectionIds(?Authenticatable $user): ?array
    {
        if (self::bypasses($user)) {
            return null;
        }

        if (! $user) {
            return [];
        }

        $userId = $user->getAuthIdentifier();

        $ids = ClassTeacher::query()->where('teacher_id', $userId)->pluck('class_section_id')->all();

        $subjectRows = ClassSubject::query()->where('teacher_id', $userId)->get(['class_id', 'section_id']);
        foreach ($subjectRows as $row) {
            $ids = array_merge($ids, ClassSection::query()
                ->where('class_id', $row->class_id)
                ->when($row->section_id, fn ($q) => $q->where('section_id', $row->section_id))
                ->pluck('id')->all());
        }

        $ids = array_merge($ids, self::timetableQuery($userId)?->pluck('t.class_section_id')->all() ?? []);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private static function timetableQuery(int|string $userId): ?Builder
    {
        if (! Schema::hasTable('timetable_periods') || ! Schema::hasTable('timetables')) {
            return null;
        }

        return DB::table('timetable_periods as p')
            ->join('timetables as t', 't.id', '=', 'p.timetable_id')
            ->whereNull('p.deleted_at')
            ->whereNull('t.deleted_at')
            ->where('p.teacher_id', $userId)
            ->where('t.school_id', current_school_id());
    }
}
