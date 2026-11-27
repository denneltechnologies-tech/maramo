<?php

namespace Classivo\ReportCards\DTO;

final readonly class IdCard
{
    /** @param list<array{label:string,value:string}> $rows */
    public function __construct(
        public string $name,
        public array $rows,
        public ?string $photo = null,
        /** Text encoded in the QR code (admission / employee number). */
        public ?string $qrPayload = null,
    ) {}
}
