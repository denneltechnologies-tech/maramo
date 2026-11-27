<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

use InvalidArgumentException;

/** Thrown when generator input is malformed (unknown ids, bad numbers, ...). */
final class InvalidProblem extends InvalidArgumentException {}
