<?php

namespace Classivo\ReportCards\Support;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as Qr;
use chillerlan\QRCode\QROptions;

/** Offline QR generator returning an inline SVG data URI. */
final class QrCode
{
    public static function dataUri(string $data): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'imageBase64' => true,
            'svgUseFillAttributes' => false,
            'quietzoneSize' => 2,
        ]);

        return (new Qr($options))->render($data);
    }
}
