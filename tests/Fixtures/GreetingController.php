<?php

declare(strict_types=1);

namespace Vigen\Tests\Fixtures;

/**
 * A controller exactly as Vigen generates one: no base class, constructor
 * dependencies resolved by the container.
 */
final class GreetingController
{
    public function __construct(private readonly GreetingRepository $greetings)
    {
    }

    public function greet(string $name): string
    {
        return $this->greetings->greeting() . ' ' . $name;
    }

    public function greetDefault(): string
    {
        return $this->greetings->greeting() . ' world';
    }
}
