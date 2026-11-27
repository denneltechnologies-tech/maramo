<?php

namespace Classivo\ReportCards\Adapters;

use Classivo\Core\Models\ClassSection;
use Classivo\Exams\Contracts\ResultDocumentLinks;
use Classivo\Exams\Models\Exam;
use Classivo\ReportCards\Support\Permissions;

/** Adds tabulation + marksheet downloads to the Exam Results page. Empty without permission. */
class ResultLinks implements ResultDocumentLinks
{
    public function classLinks(Exam $exam, ClassSection $classSection): array
    {
        if (! Permissions::allows('marksheets.download')) {
            return [];
        }

        $params = ['exam' => $exam->getKey(), 'classSection' => $classSection->getKey()];

        return [
            __('report-cards::ui.tabulation_pdf') => route('report-cards.tabulation', $params),
            __('report-cards::ui.marksheets_zip') => route('report-cards.marksheets', $params),
        ];
    }
}
