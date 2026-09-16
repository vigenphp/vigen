<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every provider wraps the model's answer in its own envelope. These tests
 * pin the one place that knows about those shapes - this was the bug that
 * made every prompt silently produce zero changes.
 */
class ProviderResponseTest extends TestCase
{
    private FakeProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider();
    }

    public function test_it_extracts_an_ollama_response(): void
    {
        $text = $this->provider->extractText([
            'model' => 'qwen2.5-coder:14b',
            'message' => ['role' => 'assistant', 'content' => '{"files":[]}'],
            'done' => true,
        ]);

        $this->assertSame('{"files":[]}', $text);
    }

    public function test_it_extracts_an_openai_response(): void
    {
        $text = $this->provider->extractText([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => '{"files":[]}']],
            ],
        ]);

        $this->assertSame('{"files":[]}', $text);
    }

    public function test_it_extracts_and_joins_claude_content_blocks(): void
    {
        $text = $this->provider->extractText([
            'content' => [
                ['type' => 'text', 'text' => '{"files":'],
                ['type' => 'text', 'text' => '[]}'],
            ],
        ]);

        $this->assertSame("{\"files\":\n[]}", $text);
    }

    public function test_it_extracts_a_gemini_response(): void
    {
        $text = $this->provider->extractText([
            'candidates' => [
                ['content' => ['parts' => [['text' => '{"files":[]}']]]],
            ],
        ]);

        $this->assertSame('{"files":[]}', $text);
    }

    /**
     * Ollama reports an unknown model with an HTTP 200 and an "error" body.
     * That must surface as a failure, not as an empty answer that looks like
     * "the model had nothing to change".
     */
    public function test_it_throws_on_an_ollama_error_body(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('model "nope" not found');

        $this->provider->extractText(['error' => 'model "nope" not found']);
    }

    public function test_it_throws_on_a_structured_error_body(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('quota exceeded');

        $this->provider->extractText(['error' => ['message' => 'quota exceeded', 'code' => 429]]);
    }

    public function test_it_throws_when_the_response_has_no_text(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned no text');

        $this->provider->extractText(['done' => true]);
    }

    public function test_it_throws_when_the_text_is_blank(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned no text');

        $this->provider->extractText(['message' => ['role' => 'assistant', 'content' => "  \n "]]);
    }
}
