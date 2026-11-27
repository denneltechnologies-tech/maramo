<?php

namespace Classivo\Hr\Support;

/** Content-Disposition-safe fragments for download filenames. */
class Filename
{
    public static function safe(string $name): string
    {
        $name = preg_replace('/[\/\\\\:*?"<>|\r\n]+/', '-', trim($name)) ?? '';

        return trim($name, '-. ') !== '' ? $name : 'file';
    }
}
