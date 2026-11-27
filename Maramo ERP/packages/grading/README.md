# classivo/grading

A pure-PHP school grading engine. It needs no framework. An optional Laravel service provider is included.

- Pluggable schemes. Presets: CBSE classes 1-8 (A1-E), CBSE classes 9-12 (nine-point A1-E2 with grade points), ICSE (33%), ISC (35%), and a generic state-board scheme you configure from an array.
- Rank-based (positional) grading for board-style results.
- Term and component weightage: periodic tests, internal assessment, term exams. A component can reduce its scores by best-of-N, average, sum, best or latest.
- Rounding rules: half-up, half-even, up, down or none, at any precision.
- Pass/fail per subject with separate theory/practical minimums. Grace marks are capped per subject, in total and by number of subjects, with an optional all-or-nothing rule.
- CGPA with an indicative percentage (CBSE x 9.5 preset), and best-of-N aggregates (e.g. English + best four).
- Co-scholastic scales (CBSE 3-point and 5-point presets).

The core is country-neutral. The India preset is the default (`Presets::make('india')`), and `Presets::make('neutral')` gives a plain A-F scale.

Board presets are configurable defaults. Check them against your board's current circulars before relying on them.

Every board rule is cited in [SOURCES.md](SOURCES.md). Rules that were uncertain are configurable rather than hard-coded.

```php
use Classivo\Grading\Presets\India\IndiaPreset;

$result = IndiaPreset::cbseSecondary()->gradeMarks(68, 80);   // 85% -> A2, GP 9, passed
$cgpa   = IndiaPreset::cbseCgpa()->indicativePercentage([10, 9, 9, 8, 10]);  // 87.4
```

Tests: `composer install && vendor/bin/phpunit`.

MIT licensed. Copyright (c) 2026 PRS CloudTech Private Limited.

## Needs verification

The board presets have not been checked against official board text. Before you rely on them, read [VERIFICATION.md](VERIFICATION.md). It lists each uncertain rule, why it is uncertain and the setting that controls it: CBSE VI-VIII and I-V bands, the nine-point CCE bands, positional-grading ties, CGPA x 9.5, separate theory/practical minimums, best-two periodic tests, CISCE pass-mark year, best-of-N conventions, grace marks, rounding, state divisions, co-scholastic descriptors and term split.
