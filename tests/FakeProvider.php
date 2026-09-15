<?php

declare(strict_types=1);

namespace Vigen\Tests;

use Vigen\Providers\AbstractProvider;

class FakeProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function complete(array $messages, array $options = []): array
    {
        return ['steps' => []];
    }
}
