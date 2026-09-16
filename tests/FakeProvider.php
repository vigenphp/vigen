<?php

declare(strict_types=1);

namespace Vigen\Tests;

use Vigen\Providers\AbstractProvider;

/**
 * A provider that replays canned responses so the engine can be exercised
 * without a network call.
 *
 * Note that it returns a *provider envelope* - the same shape Ollama uses -
 * rather than an already-decoded plan. The previous version of this fixture
 * returned `['steps' => []]`, which is not a shape any real provider ever
 * produces, and which is exactly why the bug where every response was
 * discarded went unnoticed.
 *
 * The constructor keeps the standard provider signature so
 * ProviderFactory::make() can still instantiate it by class name.
 */
final class FakeProvider extends AbstractProvider
{
    /** @var list<array<string, mixed>> */
    private array $responses;

    private int $calls = 0;

    /** @var list<array{role: string, content: string}> */
    private array $lastMessages = [];

    /** @var array<string, mixed> */
    private array $lastOptions = [];

    /**
     * @param array<string, mixed> $config Pass 'responses' as a list of
     *   envelopes, one per expected call. The final entry is reused if the
     *   engine calls more times than that.
     */
    public function __construct(string $model = 'fake-model', array $config = [])
    {
        parent::__construct($model, $config);

        /** @var list<array<string, mixed>> $responses */
        $responses = $config['responses'] ?? [self::envelope('{"files": []}')];
        $this->responses = $responses === [] ? [self::envelope('{"files": []}')] : array_values($responses);
    }

    /**
     * Always answer with this envelope.
     *
     * @param array<string, mixed> $response
     */
    public static function returning(array $response): self
    {
        return new self('fake-model', ['responses' => [$response]]);
    }

    /**
     * Always answer with this raw assistant text, wrapped in an Ollama-shaped
     * envelope.
     */
    public static function replyingWith(string $content): self
    {
        return self::returning(self::envelope($content));
    }

    /**
     * One envelope per successive call - used to test the self-correction
     * loop, where the first answer fails validation and the second fixes it.
     *
     * @param array<string, mixed> ...$responses
     */
    public static function sequence(array ...$responses): self
    {
        return new self('fake-model', ['responses' => array_values($responses)]);
    }

    /**
     * An Ollama-shaped response envelope around raw assistant text.
     *
     * @return array<string, mixed>
     */
    public static function envelope(string $content): array
    {
        return [
            'model' => 'fake-model',
            'message' => ['role' => 'assistant', 'content' => $content],
            'done' => true,
        ];
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function complete(array $messages, array $options = []): array
    {
        $this->lastMessages = $messages;
        $this->lastOptions = $options;

        $index = min($this->calls, count($this->responses) - 1);
        $this->calls++;

        return $this->responses[$index];
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function lastMessages(): array
    {
        return $this->lastMessages;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastOptions(): array
    {
        return $this->lastOptions;
    }
}
