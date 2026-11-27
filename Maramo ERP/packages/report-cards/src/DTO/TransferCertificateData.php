<?php

namespace Classivo\ReportCards\DTO;

final readonly class TransferCertificateData
{
    public function __construct(
        public Institution $institution,
        public StudentInfo $student,
        public string $tcNumber,
        public ?string $issuedOn = null,
        public ?string $dateOfLeaving = null,
        public ?string $lastClassAttended = null,
        public ?string $reasonForLeaving = null,
        public ?string $conduct = null,
        public ?int $totalWorkingDays = null,
        public ?int $daysPresent = null,
        public ?string $promotionStatus = null,
        public bool $duesCleared = false,
        public ?string $remarks = null,
        public ?string $issuedBy = null,
    ) {}
}
