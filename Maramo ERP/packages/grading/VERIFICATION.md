# Needs verification

The board presets are configurable defaults. None of the rules below has been checked against the official board text. Before you rely on a rule, check it against your board's current circulars and change the setting listed for it. Sources are in [SOURCES.md](SOURCES.md).

The setting for a rule takes one of two forms:

- a scheme you override from config: `config('grading.schemes.<key>')`. A scheme registered with the same key replaces the preset.
- an argument you pass when you build the object in PHP.

| # | Rule | Why it is uncertain | Setting |
|---|------|---------------------|---------|
| 1 | CBSE A1-E bands for classes VI-VIII (`cbse.1-8`) | Acad-14/2017 [S1]. A press report says CBSE later withdrew the VI-VIII scheme. CBSE sets no scale for classes I-V, so this preset reuses the VI-VIII bands there. No grade points are published. | `grading.schemes.cbse.1-8` |
| 2 | Nine-point A1-E2 bands with grade points 10-4; E1/E2 are fail bands with no grade point (`cbse.9-12`) | These are the CCE-era (2009-2017) absolute bands [S2]. Board results since 2018 use marks plus positional grades. Only secondary sources were checked. | `grading.schemes.cbse.9-12` |
| 3 | Positional (rank) grading: ties share the better grade; no small-cohort fallback | CBSE's tie and minimum-cohort rules are not confirmed [S5]. | `IndiaPreset::positionalBoardGrader($minimumCohort)`, `new PositionalGrader(...)` |
| 4 | CGPA x 9.5 indicative percentage; best 5 grade points; rounded to 1 decimal | CBSE called the CCE-era conversion approximate [S6]. The board averaged the five main subjects, excluding the additional subject. | `new CgpaCalculator($multiplier, $subjectCount, $cgpaRounding, $percentRounding)`; pass only the five main subjects' grade points |
| 5 | 33% separately in theory and in practical | Confirmed only by secondary sources [S3]. | `new PassRule($overall, ['theory' => .., 'practical' => ..])` |
| 6 | Periodic tests use the average of the best two | The reduction has changed across years [S7]. | `IndiaPreset::cbseSecondaryPlan($bestOf)`, or `WeightagePlan::fromArray()` with `reduction` / `n` |
| 7 | CISCE pass marks: ICSE 33%, ISC 35% | Reports give the start year as 2018 or 2019 [S4]. ISC practical minimums are not encoded. CISCE publishes no letter-grade table, so these presets have Pass/Fail bands only. | `grading.schemes.icse`, `grading.schemes.isc`; `PassRule` part minimums |
| 8 | ICSE best of five, ISC best of four (English compulsory) | These are conventions that schools and admissions use. The council does not compute them. | `new BestOfAggregator($n, $compulsory)` |
| 9 | Grace marks: none | No board publishes its moderation formula. | `new GracePolicy(...)` / `GracePolicy::fromArray()` |
| 10 | Rounding: half-up to a whole percent (32.5 passes at 33) | Boards do not publish their rounding. | `rounding` key on each scheme in `grading.schemes`; `PassRule` rounding |
| 11 | `state.generic` divisions (75 / 60 / 45 / 33) | This is an example only and is not any one board's rule. | `grading.schemes.state.generic`, or add your board as a new scheme |
| 12 | CBSE 5-point co-scholastic scale (class IX): no descriptors | The circular names grades A-E but gives no descriptors [S1]. | `new CoScholasticScale(...)` / `CoScholasticScale::fromArray()` |
| 13 | Two-term combination 50/50 | This is a school choice, not a CBSE rule. | `IndiaPreset::twoTermPlan($term1, $term2)` |
