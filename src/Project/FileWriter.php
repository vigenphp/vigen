<?php

declare(strict_types=1);

namespace Vigen\Project;

use Vigen\AI\FileChange;

/**
 * Applies AI-generated file changes to the project on disk.
 *
 * This is the only class in Vigen that acts on FileChange objects, and the
 * only place the AI pipeline touches the filesystem. It is deliberately
 * defensive: the paths it receives were authored by a language model, so
 * they are treated as untrusted input (see resolve()), and anything it
 * overwrites or deletes is backed up under .vigen/backups first.
 */
final class FileWriter
{
    public function __construct(
        private readonly string $basePath,
        private readonly bool $backup = true,
    ) {
    }

    /**
     * Apply every change in order.
     *
     * One change failing or being rejected never aborts the rest - each is
     * reported independently so a single bad path cannot roll back a whole
     * otherwise-good generation.
     *
     * @param list<FileChange> $changes
     * @return list<WriteResult>
     */
    public function apply(array $changes): array
    {
        $results = [];

        foreach ($changes as $change) {
            $results[] = $this->applyOne($change);
        }

        return $results;
    }

    private function applyOne(FileChange $change): WriteResult
    {
        $absolute = $this->resolve($change->path);

        if ($absolute === null) {
            return new WriteResult(
                $change->path,
                $change->action,
                WriteResult::STATUS_REJECTED,
                'Path is absolute, escapes the project directory, or targets Vigen\'s own .vigen folder - refused.'
            );
        }

        return match ($change->action) {
            FileChange::ACTION_CREATE => $this->create($change, $absolute),
            FileChange::ACTION_MODIFY => $this->modify($change, $absolute),
            FileChange::ACTION_DELETE => $this->delete($change, $absolute),
            default => new WriteResult(
                $change->path,
                $change->action,
                WriteResult::STATUS_REJECTED,
                'Unsupported action.'
            ),
        };
    }

    private function create(FileChange $change, string $absolute): WriteResult
    {
        $contents = $change->contents ?? '';

        // The model said "create" but the file is already there. Update it
        // like a modification (backup included) rather than clobbering it.
        if (is_file($absolute)) {
            if ($this->sameContents($absolute, $contents)) {
                return new WriteResult($change->path, $change->action, WriteResult::STATUS_UNCHANGED);
            }

            return $this->write($change, $absolute, $contents, WriteResult::STATUS_MODIFIED);
        }

        $dir = dirname($absolute);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return new WriteResult(
                $change->path,
                $change->action,
                WriteResult::STATUS_FAILED,
                "Could not create directory: {$dir}"
            );
        }

        return $this->write($change, $absolute, $contents, WriteResult::STATUS_CREATED);
    }

    private function modify(FileChange $change, string $absolute): WriteResult
    {
        if (! is_file($absolute)) {
            // Refuse rather than silently creating it: the model was working
            // from a picture of the project that no longer matches.
            return new WriteResult(
                $change->path,
                $change->action,
                WriteResult::STATUS_FAILED,
                'File does not exist, so it cannot be modified.'
            );
        }

        $contents = $change->contents ?? '';

        if ($this->sameContents($absolute, $contents)) {
            return new WriteResult($change->path, $change->action, WriteResult::STATUS_UNCHANGED);
        }

        return $this->write($change, $absolute, $contents, WriteResult::STATUS_MODIFIED);
    }

    private function delete(FileChange $change, string $absolute): WriteResult
    {
        if (! is_file($absolute)) {
            return new WriteResult(
                $change->path,
                $change->action,
                WriteResult::STATUS_UNCHANGED,
                'File was already absent.'
            );
        }

        $this->backup($change->path, $absolute);

        if (! @unlink($absolute)) {
            return new WriteResult(
                $change->path,
                $change->action,
                WriteResult::STATUS_FAILED,
                'Could not delete file.'
            );
        }

        return new WriteResult($change->path, $change->action, WriteResult::STATUS_DELETED);
    }

    private function write(FileChange $change, string $absolute, string $contents, string $status): WriteResult
    {
        $this->backup($change->path, $absolute);

        if (@file_put_contents($absolute, $contents) === false) {
            return new WriteResult(
                $change->path,
                $change->action,
                WriteResult::STATUS_FAILED,
                'Could not write file.'
            );
        }

        return new WriteResult($change->path, $change->action, $status);
    }

    /**
     * Copy the current contents aside before they are overwritten or removed.
     */
    private function backup(string $relativePath, string $absolute): void
    {
        if (! $this->backup || ! is_file($absolute)) {
            return;
        }

        $target = $this->basePath . '/.vigen/backups/' . date('Ymd-His') . '/' . $relativePath;
        $dir = dirname($target);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
        }

        @copy($absolute, $target);
    }

    private function sameContents(string $absolute, string $contents): bool
    {
        return @file_get_contents($absolute) === $contents;
    }

    /**
     * Resolve a model-authored relative path against the project root.
     *
     * Returns null when the path is absolute or would land outside the
     * project. The model writes these paths, so they are untrusted: a crafted
     * prompt - or a poisoned file already in the project - could otherwise
     * steer it into emitting "../../.ssh/authorized_keys". Every path is
     * normalised segment by segment, walking ".." can never climb above the
     * root, and Vigen's own .vigen bookkeeping directory is off limits.
     */
    private function resolve(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '') {
            return null;
        }

        // Absolute POSIX paths, Windows drive letters, and UNC paths.
        if (str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:#', $path) === 1) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === [] || $segments[0] === '.vigen') {
            return null;
        }

        return $this->basePath . '/' . implode('/', $segments);
    }
}
