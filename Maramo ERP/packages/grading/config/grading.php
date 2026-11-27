<?php

return [
    /*
    | Preset: "india" (default — CBSE 1-8, CBSE 9-12, ICSE, ISC, state.generic)
    | or "neutral" (one generic A-F scale, nothing country-specific).
    */
    'preset' => env('GRADING_PRESET', 'india'),

    /*
    | Default scheme key (null = the preset's default, cbse.9-12 for india).
    */
    'default' => env('GRADING_DEFAULT_SCHEME'),

    /*
    | Extra or overriding schemes (same key replaces a preset). Each entry:
    |   'name', 'pass_percentage' (null = lowest non-fail band),
    |   'rounding' => ['mode' => half_up|half_even|up|down|none, 'precision' => 0],
    |   'gap_policy' => threshold|strict,
    |   'bands' => [['label', 'min', 'max', 'grade_point', 'remark', 'is_fail'], ...]
    |
    | Example for a state board — edit to match the board's current notification:
    |
    | 'state.example' => [
    |     'name' => 'Example state board',
    |     'pass_percentage' => 33,
    |     'rounding' => ['mode' => 'half_up', 'precision' => 0],
    |     'bands' => [
    |         ['label' => 'A+', 'min' => 90, 'max' => 100, 'grade_point' => 10],
    |         ['label' => 'A',  'min' => 75, 'max' => 89.99, 'grade_point' => 9],
    |         ['label' => 'B',  'min' => 60, 'max' => 74.99, 'grade_point' => 8],
    |         ['label' => 'C',  'min' => 45, 'max' => 59.99, 'grade_point' => 7],
    |         ['label' => 'D',  'min' => 33, 'max' => 44.99, 'grade_point' => 6],
    |         ['label' => 'E',  'min' => 0,  'max' => 32.99, 'is_fail' => true],
    |     ],
    | ],
    */
    'schemes' => [],
];
