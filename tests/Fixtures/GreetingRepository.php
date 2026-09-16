<?php

declare(strict_types=1);

namespace Vigen\Tests\Fixtures;

/**
 * A constructor dependency, so a router test can prove that a controller's
 * dependencies are autowired rather than newed up inside the controller.
 */
final class GreetingRepository
{
    public function greeting(): string
    {
        return 'Hello';
    }
}
