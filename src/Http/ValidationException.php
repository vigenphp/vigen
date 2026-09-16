<?php

declare(strict_types=1);

namespace Vigen\Http;

use RuntimeException;

/**
 * Thrown by Request::validate() when the submitted data does not satisfy the
 * rules. The Kernel turns this into a redirect back with the errors flashed
 * into the session (or a 422 JSON body for a client that asked for JSON),
 * so a controller never has to handle its own invalid-input path.
 */
class ValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors field => messages
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data was invalid.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The first message for each field - what a view usually renders.
     *
     * @return array<string, string>
     */
    public function firstErrors(): array
    {
        return array_map(static fn (array $messages): string => $messages[0], $this->errors);
    }
}
