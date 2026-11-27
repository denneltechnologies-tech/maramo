<?php

namespace Classivo\ReportCards\DTO;

final readonly class StudentInfo
{
    public function __construct(
        public string $name,
        public ?string $admissionNo = null,
        public ?string $className = null,
        public ?string $rollNo = null,
        public ?string $dateOfBirth = null,
        public ?string $fatherName = null,
        public ?string $motherName = null,
        public ?string $gender = null,
        public ?string $address = null,
        public ?string $bloodGroup = null,
        public ?string $session = null,
        /** data: URI or absolute file path. */
        public ?string $photo = null,
    ) {}

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['name'] ?? ''),
            $a['admission_no'] ?? null,
            $a['class_name'] ?? null,
            $a['roll_no'] ?? null,
            $a['date_of_birth'] ?? null,
            $a['father_name'] ?? null,
            $a['mother_name'] ?? null,
            $a['gender'] ?? null,
            $a['address'] ?? null,
            $a['blood_group'] ?? null,
            $a['session'] ?? null,
            $a['photo'] ?? null,
        );
    }
}
