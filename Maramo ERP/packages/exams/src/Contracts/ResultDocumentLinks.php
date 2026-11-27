<?php

namespace Classivo\Exams\Contracts;

use Classivo\Core\Models\ClassSection;
use Classivo\Exams\Models\Exam;

/**
 * Optional extension point for document packages (report cards, tabulation sheets,
 * merit lists). Bind an implementation in the container and the Exam Results page
 * shows its links; this package never renders a PDF itself.
 */
interface ResultDocumentLinks
{
    /**
     * Class-level documents for an exam + class-section.
     *
     * @return array<string, string> label => url
     */
    public function classLinks(Exam $exam, ClassSection $classSection): array;
}
