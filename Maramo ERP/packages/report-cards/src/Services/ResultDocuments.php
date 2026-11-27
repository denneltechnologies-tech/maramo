<?php

namespace Classivo\ReportCards\Services;

use Classivo\Core\Models\ClassSection;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamResult;
use Classivo\Exams\Models\Mark;
use Classivo\ReportCards\Adapters\ClassivoAdapter;
use Classivo\ReportCards\DTO\MarksheetData;
use Classivo\ReportCards\Renderer\PdfRenderer;
use Classivo\ReportCards\Support\Css;
use Classivo\Students\Models\Student;
use Illuminate\Support\Collection;
use ZipArchive;

/** Builds marksheet / tabulation documents from classivo/exams data. */
final class ResultDocuments
{
    public function __construct(
        private readonly ClassivoAdapter $adapter,
        private readonly PdfRenderer $pdf,
        private readonly InstitutionResolver $institution,
    ) {}

    public function marksheetData(Exam $exam, Student $student): MarksheetData
    {
        $result = ExamResult::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->with(['grade', 'division'])->first();
        $marks = Mark::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->with(['subject', 'grade', 'examSchedule'])->get();
        $student->loadMissing(['guardians', 'session']);

        return $this->adapter->marksheet($this->institution->school(), current_branch(), $student, $exam, $result, $marks);
    }

    public function marksheetPdf(Exam $exam, Student $student, string $template = 'cbse'): string
    {
        return $this->pdf->marksheet($this->marksheetData($exam, $student), $template);
    }

    /** ZIP of one marksheet PDF per student with a result in the class-section. Returns the file path. */
    public function marksheetsZip(Exam $exam, ClassSection $classSection, string $template = 'cbse'): string
    {
        $base = tempnam(sys_get_temp_dir(), 'rc');
        $path = $base.'.zip';
        @unlink($base);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($this->results($exam, $classSection) as $result) {
            if ($result->student) {
                $zip->addFromString('marksheet-'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $result->student->admission_no).'.pdf', $this->marksheetPdf($exam, $result->student, $template));
            }
        }
        $zip->close();

        return $path;
    }

    public function tabulationPdf(Exam $exam, ClassSection $classSection): string
    {
        $results = $this->results($exam, $classSection);
        $marks = Mark::query()->where('exam_id', $exam->id)
            ->whereIn('student_id', $results->pluck('student_id'))->with('subject')->get()->groupBy('student_id');

        $subjects = $marks->flatten()->map(fn ($m) => (string) $m->subject?->name)->unique()->filter()->values()->all();

        $rows = $results->map(function (ExamResult $r) use ($marks): array {
            $byStudent = $marks->get($r->student_id, collect());

            return [
                'roll' => $r->student?->current_roll_no,
                'name' => (string) $r->student?->full_name,
                'marks' => $byStudent->mapWithKeys(fn ($m) => [(string) $m->subject?->name => $m->is_absent ? 'AB' : Css::num((float) $m->marks_obtained)])->all(),
                'total' => Css::num((float) $r->total_marks).' / '.Css::num((float) $r->total_full_marks),
                'percentage' => Css::num((float) $r->percentage).'%',
                'rank' => $r->rank,
                'result' => $r->result,
            ];
        })->all();

        $institution = $this->institution->current();

        return $this->pdf->tabulation([
            'institution' => $institution,
            'title' => 'Tabulation '.$exam->name,
            'examName' => $exam->name,
            'className' => (string) $classSection->name,
            'subjects' => $subjects,
            'rows' => $rows,
        ]);
    }

    /** @return Collection<int, ExamResult> */
    private function results(Exam $exam, ClassSection $classSection)
    {
        return ExamResult::query()->where('exam_id', $exam->id)->where('class_section_id', $classSection->id)
            ->with('student')->orderBy('rank')->get();
    }
}
