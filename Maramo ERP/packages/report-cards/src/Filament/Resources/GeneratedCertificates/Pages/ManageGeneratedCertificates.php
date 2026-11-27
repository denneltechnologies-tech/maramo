<?php

namespace Classivo\ReportCards\Filament\Resources\GeneratedCertificates\Pages;

use Classivo\ReportCards\Adapters\ClassivoAdapter;
use Classivo\ReportCards\Filament\Concerns\SelectsStudent;
use Classivo\ReportCards\Filament\Resources\GeneratedCertificates\GeneratedCertificateResource;
use Classivo\ReportCards\Models\CertificateTemplate;
use Classivo\ReportCards\Services\CertificateIssuer;
use Classivo\ReportCards\Services\InstitutionResolver;
use Classivo\ReportCards\Support\Permissions;
use Classivo\ReportCards\Support\QrCode;
use Classivo\Students\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageGeneratedCertificates extends ManageRecords
{
    use SelectsStudent;

    protected static string $resource = GeneratedCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label(__('report-cards::ui.issue_certificate'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => Permissions::allows('generated_certificates.issue'))
                ->schema([
                    Select::make('certificate_template_id')
                        ->label(__('report-cards::ui.template'))
                        ->options(fn (): array => CertificateTemplate::query()->where('status', true)->pluck('name', 'id')->all())
                        ->required(),
                    static::studentSelect(),
                ])
                ->action(function (array $data): void {
                    abort_unless(Permissions::allows('generated_certificates.issue'), 403);

                    $template = CertificateTemplate::findOrFail($data['certificate_template_id']);
                    $student = Student::with(['guardians', 'session'])->findOrFail($data['student_id']);
                    $institution = app(InstitutionResolver::class);
                    $variables = app(ClassivoAdapter::class)->certificateVariables($institution->school(), current_branch(), $student);
                    $variables['qr_code'] = '<img src="'.QrCode::dataUri((string) $student->admission_no).'" alt="QR" style="width:90px;height:90px;">';

                    $certificate = app(CertificateIssuer::class)->issue($template, $variables, $student->id, null, auth()->id());

                    Notification::make()->success()->title(__('report-cards::ui.issued', ['number' => $certificate->certificate_number]))->send();
                }),
        ];
    }
}
