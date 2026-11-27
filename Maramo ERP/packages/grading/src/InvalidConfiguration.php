<?php

declare(strict_types=1);

namespace Classivo\Grading;

use InvalidArgumentException;

/** Thrown when a scheme, scale, weightage or policy is configured inconsistently. */
final class InvalidConfiguration extends InvalidArgumentException {}
