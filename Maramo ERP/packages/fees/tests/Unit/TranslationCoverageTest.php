<?php

namespace Classivo\Fees\Tests\Unit;

use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Services\Reports\FeeReportService;
use Classivo\Fees\Support\FeeRefunds;
use Classivo\Fees\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Every English UI string the package passes through __() has a Hindi entry. */
class TranslationCoverageTest extends TestCase
{
    public function test_every_ui_string_has_a_hindi_translation(): void
    {
        $hindi = json_decode((string) file_get_contents(__DIR__.'/../../lang/hi.json'), true);
        $this->assertIsArray($hindi);

        $strings = [];

        foreach ([__DIR__.'/../../src/Filament', __DIR__.'/../../resources/views/filament'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if (! str_ends_with((string) $file, '.php')) {
                    continue;
                }

                preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents((string) $file), $m);

                foreach ($m[1] as $s) {
                    $s = stripslashes($s);

                    if ($s !== '' && ! str_contains($s, '::')) {
                        $strings[$s] = true;
                    }
                }
            }
        }

        foreach ([FeePayment::MODES, FeeStudentAllocation::STATUSES, FeeRefunds::REASON_CODES, FeeRefunds::MODES, FeeReportService::REPORTS] as $map) {
            foreach ($map as $label) {
                $strings[$label] = true;
            }
        }

        $missing = array_values(array_filter(array_keys($strings), fn (string $s): bool => ! array_key_exists($s, $hindi)));

        $this->assertSame([], $missing, 'Missing Hindi translations: '.implode(' | ', $missing));

        app()->setLocale('hi');
        $this->assertSame('शुल्क संग्रह', __('Collect Fees'));
        $this->assertSame('शुल्क संग्रह', __('classivo-fees::fees.navigation_group'));
    }
}
