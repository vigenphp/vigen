<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\Project\Conventions;

class ConventionsTest extends TestCase
{
    public function test_defaults_are_used_when_nothing_configured(): void
    {
        $this->assertSame(Conventions::DEFAULTS, Conventions::resolve(null));
    }

    public function test_configured_paths_override_defaults(): void
    {
        $resolved = Conventions::resolve(['models' => 'src/Domain/Models']);

        $this->assertSame('src/Domain/Models', $resolved['models']);
        // Untouched keys keep their default.
        $this->assertSame(Conventions::DEFAULTS['controllers'], $resolved['controllers']);
    }
}
