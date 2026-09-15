<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\Providers\ClaudeProvider;
use Vigen\Providers\GeminiProvider;
use Vigen\Providers\OllamaProvider;
use Vigen\Providers\OpenAIProvider;
use Vigen\Providers\ProviderFactory;

class ProviderFactoryTest extends TestCase
{
    public function test_it_resolves_each_built_in_provider(): void
    {
        $this->assertInstanceOf(OllamaProvider::class, ProviderFactory::make('ollama', 'qwen2.5-coder:14b'));
        $this->assertInstanceOf(OpenAIProvider::class, ProviderFactory::make('openai', 'gpt-4o'));
        $this->assertInstanceOf(ClaudeProvider::class, ProviderFactory::make('claude', 'claude-sonnet-4-6'));
        $this->assertInstanceOf(GeminiProvider::class, ProviderFactory::make('gemini', 'gemini-2.0-flash'));
    }

    public function test_it_throws_on_unknown_provider(): void
    {
        $this->expectException(\RuntimeException::class);
        ProviderFactory::make('not-a-real-provider', 'x');
    }

    public function test_custom_providers_can_be_registered(): void
    {
        ProviderFactory::register('fake', FakeProvider::class);
        $this->assertInstanceOf(FakeProvider::class, ProviderFactory::make('fake', 'any-model'));
    }
}
