# classivo/grading

A grading engine written in plain PHP. It turns marks into grades, pass or fail, grace marks, weighted totals and CGPA. It has no screens.

## What it does

This is a library. It has no menu, no pages and no database tables. Developers call it from code, and other packages can use it to work out grades.

What it can do:

- Grade marks with a scheme. A scheme is a set of grade bands (for example A1 to E2) with a pass percentage and a rounding rule.
- Built-in India schemes (the default): CBSE classes 1-8 (`cbse.1-8`), CBSE classes 9-12 (`cbse.9-12`, nine-point with grade points), ICSE (`icse`, pass 33%), ISC (`isc`, pass 35%), and a generic state-board example (`state.generic`). A neutral preset gives one plain A to F scale (`generic.letter`, pass at 40%).
- Grade by rank (positional grading), where the top students get the top grades.
- Weighted totals from several parts (for example periodic tests, internal marks and a term exam). A part can use best-of-N, average, sum, best or latest.
- Rounding: half up, half even, up, down, or none, at any number of decimals.
- Pass or fail for each subject, with separate minimums for theory and practical if you want them.
- Grace marks with limits: per subject, in total, by number of subjects, and an all-or-nothing option. The default policy gives no grace.
- CGPA, and an indicative percentage from grade points (CBSE multiplier 9.5 in the preset).
- Best-of-N totals (for example English plus the best four).
- Co-scholastic scales (CBSE 3-point and 5-point presets).
- An adapter that turns a school's own grade table rows into a scheme.

The board presets are starting points. They have not been checked against official board text. Check them against your board's current rules before you rely on them. The package ships notes on each uncertain rule (VERIFICATION.md) and the sources (SOURCES.md).

Example:

```php
use Classivo\Grading\Presets\India\IndiaPreset;

$result = IndiaPreset::cbseSecondary()->gradeMarks(68, 80);
```

## Screens

None. This package is library only. It adds nothing to the Filament panel and has no plugin class.

## Permissions

None.

## Configuration

Publish with:

```
php artisan vendor:publish --tag=grading-config
```

File: `config/grading.php`. The Laravel service provider is optional. Without Laravel, build a registry in code with `Presets::make()`.

| Key | Default | Meaning |
|---|---|---|
| `preset` | `india` (env `GRADING_PRESET`) | `india` (CBSE 1-8, CBSE 9-12, ICSE, ISC, state.generic) or `neutral` (one generic A to F scale). Any other name throws an error. |
| `default` | `null` (env `GRADING_DEFAULT_SCHEME`) | Default scheme key. `null` uses the preset's default, which is `cbse.9-12` for India. |
| `schemes` | `[]` | Extra schemes, or schemes that replace a preset with the same key. |

Each entry in `schemes` can have:

- `name`
- `pass_percentage` (`null` means the lowest non-fail band)
- `rounding` with `mode` (`half_up`, `half_even`, `up`, `down`, `none`) and `precision` (default 0)
- `gap_policy` (`threshold` or `strict`)
- `bands`: a list of `label`, `min`, `max`, `grade_point`, `remark`, `is_fail`

Use the registry in code:

```php
app(\Classivo\Grading\Schemes\SchemeRegistry::class)->get('cbse.9-12')->grade(87.5);
```

The registry is also available as `classivo.grading`.

Use a school's own grade table rows:

```php
use Classivo\Grading\Adapters\GradeTableAdapter;

$scheme = GradeTableAdapter::toScheme($grades, 'school.1');
```

Gap handling between bands is strict by default. Pass `GradeScale::THRESHOLD` to close gaps between whole-number bands.

## Events

Emitted: none.

Listened: none.

## Install

```
composer require classivo/grading
```

No migration and no plugin to register. It needs only PHP 8.2 or newer. If `illuminate/support` is installed, the service provider `Classivo\Grading\Laravel\GradingServiceProvider` is discovered automatically.

## Known limitations

- Library only. No screens, tables, routes or permissions.
- Board presets are unverified defaults. Rules that are uncertain are listed in the package's VERIFICATION.md, each with the setting that controls it.
- ICSE and ISC presets have Pass and Fail bands only, because no letter-grade table is published for them.
- classivo/exams does not read this package at present. It uses its own grade table. TODO: confirm if a link is planned.
