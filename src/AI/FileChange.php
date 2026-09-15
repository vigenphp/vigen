<?php

declare(strict_types=1);

namespace Vigen\AI;

/**
 * A single planned or applied change to a project file.
 */
final class FileChange
{
    public const ACTION_CREATE = 'create';
    public const ACTION_MODIFY = 'modify';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        public readonly string $path,
        public readonly string $action,
        public readonly ?string $contents = null,
        public readonly ?string $reason = null,
    ) {
    }
}
