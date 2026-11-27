<?php

namespace Classivo\ReportCards\Tests;

use Classivo\ReportCards\Adapters\ClassivoAdapter;
use Classivo\ReportCards\Renderer\PdfRenderer;

class AdapterTest extends TestCase
{
    private function student(): array
    {
        return [
            'id' => 1, 'full_name' => 'Test Student', 'admission_no' => 'A-1', 'current_roll_no' => 4,
            'date_of_birth' => new \DateTimeImmutable('2011-03-14'), 'classSection' => ['name' => 'X - A'],
            'session' => ['name' => '2026-27'],
            'guardians' => [['name' => 'Test Father', 'relation' => 'father'], ['name' => 'Test Mother', 'relation' => 'mother']],
        ];
    }

    public function test_marksheet_from_records(): void
    {
        $a = new ClassivoAdapter;
        $marks = [
            ['subject' => ['name' => 'Maths'], 'marks_obtained' => 80, 'is_absent' => false, 'grade' => ['name' => 'A'], 'examSchedule' => ['full_marks' => 100, 'pass_marks' => 33]],
            ['subject' => ['name' => 'Art'], 'marks_obtained' => null, 'is_absent' => true, 'examSchedule' => ['full_marks' => 50, 'pass_marks' => 17]],
        ];
        $result = ['total_marks' => 80, 'total_full_marks' => 150, 'percentage' => 53.33, 'rank' => 2, 'result' => 'fail'];
        $data = $a->marksheet(['name' => 'Demo School'], ['name' => 'Main'], $this->student(), ['name' => 'Term 1'], $result, $marks);

        $this->assertSame('Test Father', $data->student->fatherName);
        $this->assertSame('14 Mar 2011', $data->student->dateOfBirth);
        $this->assertTrue($data->subjects[1]->absent);
        $this->assertPdf(app(PdfRenderer::class)->marksheet($data, 'cbse'));
    }

    public function test_marks_required_without_exams_package(): void
    {
        $this->expectException(\Throwable::class);
        (new ClassivoAdapter)->marksheet(['name' => 'S'], null, $this->student(), ['id' => 1, 'name' => 'T']);
    }

    public function test_id_cards_from_records(): void
    {
        $a = new ClassivoAdapter;
        $sheet = $a->idCards(['name' => 'Demo School'], null, [$this->student()], ['fields' => ['admission_no', 'father_name'], 'header_color' => '#112233']);
        $this->assertCount(2, $sheet->cards[0]->rows);
        $this->assertPdf(app(PdfRenderer::class)->idCards($sheet));
    }

    public function test_transfer_certificate_from_records(): void
    {
        $a = new ClassivoAdapter;
        $tc = $a->transferCertificate(['name' => 'Demo School'], null, $this->student(), ['tc_number' => 'TC0007', 'dues_cleared' => true]);
        $this->assertPdf(app(PdfRenderer::class)->transferCertificate($tc));
    }
}
