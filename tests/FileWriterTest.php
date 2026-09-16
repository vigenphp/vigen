<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vigen\AI\FileChange;
use Vigen\Project\FileWriter;
use Vigen\Project\WriteResult;

class FileWriterTest extends TestCase
{
    use CreatesTempProject;

    private FileWriter $writer;

    protected function setUp(): void
    {
        $this->createTempProject();
        $this->writer = new FileWriter($this->tempProject);
    }

    protected function tearDown(): void
    {
        $this->removeTempProject();
    }

    public function test_it_creates_a_file_and_its_missing_directories(): void
    {
        $results = $this->writer->apply([
            new FileChange('app/Models/User.php', FileChange::ACTION_CREATE, "<?php\n\nclass User {}\n"),
        ]);

        $this->assertCount(1, $results);
        $this->assertSame(WriteResult::STATUS_CREATED, $results[0]->status);
        $this->assertFileExists($this->tempProject . '/app/Models/User.php');
        $this->assertStringContainsString('class User', (string) file_get_contents($this->tempProject . '/app/Models/User.php'));
    }

    public function test_creating_an_identical_file_reports_unchanged(): void
    {
        $this->writeFixture('app/Models/User.php', "<?php\n\nclass User {}\n");

        $results = $this->writer->apply([
            new FileChange('app/Models/User.php', FileChange::ACTION_CREATE, "<?php\n\nclass User {}\n"),
        ]);

        $this->assertSame(WriteResult::STATUS_UNCHANGED, $results[0]->status);
        $this->assertFalse($results[0]->changed());
    }

    public function test_creating_over_a_different_file_modifies_it_and_backs_it_up(): void
    {
        $this->writeFixture('app/Models/User.php', "<?php\n\n// original\n");

        $results = $this->writer->apply([
            new FileChange('app/Models/User.php', FileChange::ACTION_CREATE, "<?php\n\n// replaced\n"),
        ]);

        $this->assertSame(WriteResult::STATUS_MODIFIED, $results[0]->status);
        $this->assertStringContainsString('replaced', (string) file_get_contents($this->tempProject . '/app/Models/User.php'));
        $this->assertNotEmpty(
            glob($this->tempProject . '/.vigen/backups/*/app/Models/User.php') ?: [],
            'The overwritten file should have been backed up.'
        );
    }

    public function test_modifying_a_missing_file_fails_rather_than_creating_it(): void
    {
        $results = $this->writer->apply([
            new FileChange('routes/web.php', FileChange::ACTION_MODIFY, "<?php\n"),
        ]);

        $this->assertSame(WriteResult::STATUS_FAILED, $results[0]->status);
        $this->assertFalse($results[0]->ok());
        $this->assertFileDoesNotExist($this->tempProject . '/routes/web.php');
    }

    public function test_it_modifies_an_existing_file(): void
    {
        $this->writeFixture('routes/web.php', "<?php\n\n// old\n");

        $results = $this->writer->apply([
            new FileChange('routes/web.php', FileChange::ACTION_MODIFY, "<?php\n\n// new\n"),
        ]);

        $this->assertSame(WriteResult::STATUS_MODIFIED, $results[0]->status);
        $this->assertStringContainsString('// new', (string) file_get_contents($this->tempProject . '/routes/web.php'));
    }

    public function test_it_deletes_a_file_and_backs_it_up(): void
    {
        $this->writeFixture('app/Old.php', "<?php\n");

        $results = $this->writer->apply([
            new FileChange('app/Old.php', FileChange::ACTION_DELETE),
        ]);

        $this->assertSame(WriteResult::STATUS_DELETED, $results[0]->status);
        $this->assertFileDoesNotExist($this->tempProject . '/app/Old.php');
        $this->assertNotEmpty(glob($this->tempProject . '/.vigen/backups/*/app/Old.php') ?: []);
    }

    public function test_deleting_a_missing_file_is_reported_as_unchanged(): void
    {
        $results = $this->writer->apply([
            new FileChange('app/Gone.php', FileChange::ACTION_DELETE),
        ]);

        $this->assertSame(WriteResult::STATUS_UNCHANGED, $results[0]->status);
        $this->assertTrue($results[0]->ok());
    }

    /**
     * Paths come from a language model, so they are untrusted input. Anything
     * that would land outside the project must be refused.
     */
    #[DataProvider('traversalPaths')]
    public function test_it_refuses_paths_that_escape_the_project(string $path): void
    {
        $results = $this->writer->apply([
            new FileChange($path, FileChange::ACTION_CREATE, "<?php\n\n// pwned\n"),
        ]);

        $this->assertSame(WriteResult::STATUS_REJECTED, $results[0]->status);
        $this->assertFalse($results[0]->ok());
        $this->assertFileDoesNotExist(dirname($this->tempProject) . '/vigen-escaped.php');
        $this->assertFileDoesNotExist($this->tempProject . '/.vigen/pwned.php');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalPaths(): array
    {
        return [
            'parent directory' => ['../vigen-escaped.php'],
            'deep parent directory' => ['app/../../vigen-escaped.php'],
            'absolute posix path' => ['/tmp/vigen-escaped.php'],
            'windows drive path' => ['C:/Windows/Temp/vigen-escaped.php'],
            'backslash traversal' => ['..\\vigen-escaped.php'],
            'vigen bookkeeping' => ['.vigen/pwned.php'],
            'empty path' => [''],
        ];
    }

    public function test_it_keeps_a_path_that_normalises_back_inside_the_project(): void
    {
        $this->writeFixture('routes/web.php', "<?php\n\n// old\n");

        // "app/../routes/web.php" is untidy but does resolve inside the
        // project, so it should be applied rather than refused.
        $results = $this->writer->apply([
            new FileChange('app/../routes/web.php', FileChange::ACTION_MODIFY, "<?php\n\n// new\n"),
        ]);

        $this->assertSame(WriteResult::STATUS_MODIFIED, $results[0]->status);
    }

    /**
     * One bad path must not abort the whole run - everything else in the
     * batch should still land.
     */
    public function test_a_rejected_path_does_not_block_the_other_changes(): void
    {
        $results = $this->writer->apply([
            new FileChange('../escape.php', FileChange::ACTION_CREATE, "<?php\n"),
            new FileChange('app/Good.php', FileChange::ACTION_CREATE, "<?php\n\n// good\n"),
        ]);

        $this->assertSame(WriteResult::STATUS_REJECTED, $results[0]->status);
        $this->assertSame(WriteResult::STATUS_CREATED, $results[1]->status);
        $this->assertFileExists($this->tempProject . '/app/Good.php');
    }

    public function test_backups_can_be_disabled(): void
    {
        $this->writeFixture('routes/web.php', "<?php\n\n// old\n");

        $writer = new FileWriter($this->tempProject, backup: false);
        $writer->apply([
            new FileChange('routes/web.php', FileChange::ACTION_MODIFY, "<?php\n\n// new\n"),
        ]);

        $this->assertSame([], glob($this->tempProject . '/.vigen/backups/*') ?: []);
    }

    public function test_it_returns_an_empty_list_for_no_changes(): void
    {
        $this->assertSame([], $this->writer->apply([]));
    }
}
