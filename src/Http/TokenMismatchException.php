<?php

declare(strict_types=1);

namespace Vigen\Http;

use RuntimeException;

/**
 * Thrown when a state-changing request arrives without a valid CSRF token.
 * The Kernel catches it and returns 419, naming the hidden field the form is
 * missing, because a silent rejection here is very hard to diagnose.
 */
class TokenMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The CSRF token did not match.');
    }
}
