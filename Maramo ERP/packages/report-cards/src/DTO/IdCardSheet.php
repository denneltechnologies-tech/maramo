<?php

namespace Classivo\ReportCards\DTO;

final readonly class IdCardSheet
{
    /** @param list<IdCard> $cards */
    public function __construct(
        public Institution $institution,
        public array $cards,
        public string $headerColor = '#1e293b',
        public string $textColor = '#1e293b',
        public bool $showPhoto = true,
        public bool $showQr = true,
        public bool $showLogo = true,
    ) {}
}
