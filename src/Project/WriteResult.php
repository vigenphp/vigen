<?php

declare(strict_types=1);

namespace Vigen\Project;

/**
 * The outcome of applying a single planned FileChange to disk.
 *
 * Reported per file so the CLI and GUI can say what actually happened
 * (created / modified / unchanged / rejected / failed) instead of claiming
 * success for every planned path.
 */
final class WriteResult
{
    public const STATUS_CREATED = 'created';
    public const STATUS_MODIFIED = 'modified';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $path,
        public readonly string $action,
        public readonly string $status,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * False when the change was refused or could not be written.
     */
    public function ok(): bool
    {
        return ! in_array($this->status, [self::STATUS_REJECTED, self::STATUS_FAILED], true);
    }

    /**
     * True when this result represents an actual modification of the tree.
     */
    public function changed(): bool
    {
        return in_array($this->status, [
            self::STATUS_CREATED,
            self::STATUS_MODIFIED,
            self::STATUS_DELETED,
        ], true);
    }
}
