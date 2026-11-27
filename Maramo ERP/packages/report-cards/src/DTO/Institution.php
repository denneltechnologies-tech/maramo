<?php

namespace Classivo\ReportCards\DTO;

/** The school header printed on every document. */
final readonly class Institution
{
    public function __construct(
        public string $name,
        public ?string $branch = null,
        public ?string $address = null,
        public ?string $phone = null,
        /** data: URI or absolute file path of a logo image (optional). */
        public ?string $logo = null,
        /** e.g. "Affiliation No. 0000000" (optional, printed by the CBSE-style template). */
        public ?string $affiliation = null,
    ) {}

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['name'] ?? ''),
            $a['branch'] ?? null,
            $a['address'] ?? null,
            $a['phone'] ?? null,
            $a['logo'] ?? null,
            $a['affiliation'] ?? null,
        );
    }
}
