<?php

namespace Classivo\DemoGuard\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class DemoRestricted extends HttpException
{
    public function __construct(?string $what = null)
    {
        parent::__construct(403, $what ?: __('demo-guard::messages.restricted'));
    }
}
