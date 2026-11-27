<?php

namespace Classivo\ReportCards\Http\Controllers;

use Classivo\Core\Models\ClassSection;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamResult;
use Classivo\Exams\Models\Mark;
use Classivo\ReportCards\Services\ResultDocuments;
use Classivo\ReportCards\Support\Permissions;
use Classivo\Students\Models\Student;
use Illuminate\Routing\Controller;

class ResultDocumentController extends Controller
{
    public function __construct(private readonly ResultDocuments $documents)
    {
        $this->middleware(function ($request, $next) {
            abort_unless(Permissions::allows('marksheets.download'), 403);

            return $next($request);
        });
    }

    public function marksheet(int $exam, int $student)
    {
        $exam = $this->exam($exam);
        $student = Student::query()->where('school_id', current_school_id())->findOrFail($student);

        // Only a student with marks or a result in this exam.
        abort_unless(
            ExamResult::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->exists()
                || Mark::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->exists(),
            404
        );

        return response($this->documents->marksheetPdf($exam, $student), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="marksheet-'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $student->admission_no).'.pdf"',
        ]);
    }

    public function marksheets(int $exam, int $classSection)
    {
        $exam = $this->exam($exam);
        $classSection = $this->classSection($classSection);

        return response()->download($this->documents->marksheetsZip($exam, $classSection), 'marksheets.zip')->deleteFileAfterSend(true);
    }

    public function tabulation(int $exam, int $classSection)
    {
        $exam = $this->exam($exam);
        $classSection = $this->classSection($classSection);

        return response($this->documents->tabulationPdf($exam, $classSection), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tabulation.pdf"',
        ]);
    }

    /*
     * Records are looked up by hand with an explicit school filter: implicit route
     * binding runs before the tenant context is resolved, while the scopes are off.
     * Earlier sessions stay printable, so only the session scope is lifted.
     */
    private function exam(int $id): Exam
    {
        return Exam::query()->withoutGlobalScope(SessionScope::class)
            ->where('school_id', current_school_id())->findOrFail($id);
    }

    private function classSection(int $id): ClassSection
    {
        return ClassSection::query()->withoutGlobalScope(SessionScope::class)
            ->where('school_id', current_school_id())->findOrFail($id);
    }
}
