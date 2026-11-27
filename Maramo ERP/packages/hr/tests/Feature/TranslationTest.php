<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Hr\Models\Payroll;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Services\PayrollService;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Tests\TestCase;

class TranslationTest extends TestCase
{
    private const FILES = ['ui', 'messages', 'views', 'enums', 'hr'];

    private function flatten(array $a, string $p = ''): array
    {
        $o = [];
        foreach ($a as $k => $v) {
            is_array($v) ? $o += $this->flatten($v, $p.$k.'.') : $o[$p.$k] = $v;
        }

        return $o;
    }

    public function test_hindi_covers_every_english_key_with_the_same_placeholders(): void
    {
        foreach (self::FILES as $file) {
            $en = $this->flatten(include __DIR__."/../../lang/en/{$file}.php");
            $hi = $this->flatten(include __DIR__."/../../lang/hi/{$file}.php");

            $this->assertSame([], array_diff_key($en, $hi), "{$file}: missing in hi");
            $this->assertSame([], array_diff_key($hi, $en), "{$file}: extra in hi");

            foreach ($en as $key => $text) {
                preg_match_all('/:\w+/', (string) $text, $a);
                preg_match_all('/:\w+/', (string) $hi[$key], $b);
                $this->assertEqualsCanonicalizing($a[0], $b[0], "{$file}.{$key}: placeholders differ");
                $this->assertNotSame('', trim((string) $hi[$key]), "{$file}.{$key}: empty");
            }
        }
    }

    public function test_every_key_used_in_code_exists(): void
    {
        $en = [];
        foreach (self::FILES as $file) {
            foreach ($this->flatten(include __DIR__."/../../lang/en/{$file}.php") as $k => $_) {
                $en[$file.'.'.$k] = true;
            }
        }

        $roots = [__DIR__.'/../../src', __DIR__.'/../../resources'];
        $missing = [];

        foreach ($roots as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
                if (! in_array($f->getExtension(), ['php'], true)) {
                    continue;
                }
                preg_match_all("/hr::(ui|messages|views|hr)\.([a-z0-9_.]+)'/", file_get_contents($f->getPathname()), $m, PREG_SET_ORDER);
                foreach ($m as [$whole, $file, $key]) {
                    if (! isset($en[$file.'.'.$key]) && ! $this->prefixExists($en, $file.'.'.$key)) {
                        $missing[] = "{$file}.{$key} ({$f->getFilename()})";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    private function prefixExists(array $en, string $prefix): bool
    {
        foreach (array_keys($en) as $k) {
            if (str_starts_with($k, $prefix.'.') || str_starts_with($k, rtrim($prefix, '.'))) {
                return true;
            }
        }

        return false;
    }

    public function test_enum_labels_follow_the_locale(): void
    {
        app()->setLocale('hi');

        $this->assertSame('पुरुष', HrLabels::enum(Staff::GENDERS, 'staff.genders')['male']);
        $this->assertSame('भुगतान किया', HrLabels::enum(Payroll::STATUSES, 'payroll.statuses')['paid']);
        $this->assertSame('मानव संसाधन', __('hr::hr.navigation_group'));

        app()->setLocale('en');
        $this->assertSame('Male', HrLabels::enum(Staff::GENDERS, 'staff.genders')['male']);
    }

    public function test_service_notes_and_period_label_follow_the_locale(): void
    {
        [$school] = $this->foundation();
        $this->adminUser($school);
        $staff = Staff::create(['first_name' => 'Loc', 'joining_date' => '2028-01-01', 'basic_salary' => 10000, 'status' => 'active']);

        app()->setLocale('hi');
        $payroll = app(PayrollService::class)->generate($staff, 3, 2030);

        $this->assertSame('मार्च 2030', $payroll->period_label);

        app()->setLocale('en');
        $this->assertSame('March 2030', $payroll->fresh()->period_label);
    }
}
