<?php

namespace App\Filament\Pages;

use App\Models\CourseApplication;
use Classivo\Core\Models\SchoolClass;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\ReportCards\Models\GeneratedCertificate;
use Classivo\Students\Models\Student;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsHub extends Page
{
    protected static \UnitEnum|string|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $title = 'Reports & Analytics Hub';

    protected static ?string $navigationLabel = 'Reports Hub';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.reports-hub';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_students')
                ->label('Export Student Roster (CSV)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->action(fn () => $this->exportStudents()),

            Action::make('export_payments')
                ->label('Export Payment Log (CSV)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->action(fn () => $this->exportPayments()),

            Action::make('export_outstanding')
                ->label('Export Outstanding Fees (CSV)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('danger')
                ->action(fn () => $this->exportOutstandingFees()),
        ];
    }

    public function exportStudents(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="maramo_students_register_' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Admission No', 'Full Name', 'Gender', 'Phone', 'Email', 'Course', 'Status', 'Admission Date', 'Training Start', 'Completion Date']);

            Student::query()->with('schoolClass')->chunk(100, function ($students) use ($handle) {
                foreach ($students as $s) {
                    fputcsv($handle, [
                        $s->admission_no,
                        $s->full_name,
                        ucfirst($s->gender ?? ''),
                        $s->mobile,
                        $s->email,
                        $s->schoolClass?->name ?? '—',
                        ucfirst($s->status),
                        $s->admission_date?->format('Y-m-d') ?? '',
                        $s->training_start_date?->format('Y-m-d') ?? '',
                        $s->completion_date?->format('Y-m-d') ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    public function exportApplications(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="maramo_applications_' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['App No', 'Applicant Name', 'Phone', 'Email', 'Course', 'Schedule', 'Source', 'Status', 'Applied Date']);

            CourseApplication::query()->with('course')->chunk(100, function ($apps) use ($handle) {
                foreach ($apps as $a) {
                    fputcsv($handle, [
                        $a->application_no,
                        $a->full_name,
                        $a->phone,
                        $a->email,
                        $a->course?->name ?? '—',
                        CourseApplication::SCHEDULES[$a->preferred_schedule] ?? $a->preferred_schedule,
                        CourseApplication::SOURCES[$a->source] ?? $a->source,
                        CourseApplication::STATUSES[$a->status] ?? $a->status,
                        $a->created_at->format('Y-m-d'),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    public function exportPayments(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="maramo_payment_transactions_' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Receipt No', 'Payment Date', 'Student Name', 'Admission No', 'Amount (GHS)', 'Payment Mode', 'Reference No', 'Status']);

            FeePayment::query()->with('student')->chunk(100, function ($payments) use ($handle) {
                foreach ($payments as $p) {
                    fputcsv($handle, [
                        $p->receipt_no,
                        $p->payment_date?->format('Y-m-d') ?? '',
                        $p->student?->full_name ?? '—',
                        $p->student?->admission_no ?? '—',
                        number_format($p->paid_amount, 2, '.', ''),
                        FeePayment::MODES[$p->mode] ?? $p->mode,
                        $p->reference_no,
                        ucfirst($p->status),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    public function exportOutstandingFees(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="maramo_outstanding_fees_' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Student Name', 'Admission No', 'Phone', 'Fee Head', 'Due Date', 'Total Amount (GHS)', 'Paid Amount (GHS)', 'Balance Due (GHS)']);

            FeeStudentAllocation::query()
                ->with(['student', 'feeType', 'feeMaster.feeType'])
                ->where('status', '!=', 'paid')
                ->chunk(100, function ($allocs) use ($handle) {
                    foreach ($allocs as $a) {
                        $balance = max(0, $a->amount - $a->paid_amount);
                        fputcsv($handle, [
                            $a->student?->full_name ?? '—',
                            $a->student?->admission_no ?? '—',
                            $a->student?->mobile ?? '',
                            $a->feeMaster?->feeType?->name ?? 'Course Fee',
                            $a->due_date?->format('Y-m-d') ?? '',
                            number_format($a->amount, 2, '.', ''),
                            number_format($a->paid_amount, 2, '.', ''),
                            number_format($balance, 2, '.', ''),
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }

    public function exportCertificates(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="maramo_certificates_register_' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Certificate No', 'Student Name', 'Admission No', 'Course', 'Issued Date']);

            GeneratedCertificate::query()->with('student')->chunk(100, function ($certs) use ($handle) {
                foreach ($certs as $c) {
                    fputcsv($handle, [
                        $c->certificate_number,
                        $c->student?->full_name ?? ($c->data['variables']['student_name'] ?? '—'),
                        $c->student?->admission_no ?? ($c->data['variables']['admission_no'] ?? '—'),
                        $c->data['variables']['class'] ?? 'Comprehensive Cosmetology',
                        $c->issued_at?->format('Y-m-d') ?? $c->created_at->format('Y-m-d'),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
