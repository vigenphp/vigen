<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\Project\SyntaxValidator;

class SyntaxValidatorTest extends TestCase
{
    use CreatesTempProject;

    protected function setUp(): void
    {
        $this->createTempProject();
    }

    protected function tearDown(): void
    {
        $this->removeTempProject();
    }

    public function test_it_passes_a_valid_php_file(): void
    {
        $this->writeFixture('app/Good.php', "<?php\n\nclass Good\n{\n}\n");

        $result = (new SyntaxValidator($this->tempProject))->validateFiles(['app/Good.php']);

        $this->assertTrue($result->passed, implode(', ', $result->errors));
    }

    public function test_it_fails_a_broken_php_file(): void
    {
        $this->writeFixture('app/Broken.php', "<?php\n\nclass {\n");

        $result = (new SyntaxValidator($this->tempProject))->validateFiles(['app/Broken.php']);

        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('app/Broken.php', $result->errors[0]);
    }

    public function test_it_ignores_files_that_are_not_php(): void
    {
        $this->writeFixture('notes.txt', 'not php at all {{{');

        $result = (new SyntaxValidator($this->tempProject))->validateFiles(['notes.txt']);

        $this->assertTrue($result->passed);
    }

    public function test_it_ignores_files_that_do_not_exist(): void
    {
        $result = (new SyntaxValidator($this->tempProject))->validateFiles(['app/Nope.php']);

        $this->assertTrue($result->passed);
    }

    public function test_it_passes_when_there_is_nothing_to_check(): void
    {
        $this->assertTrue((new SyntaxValidator($this->tempProject))->validateFiles([])->passed);
    }

    /**
     * Dry runs lint proposed bodies from a scratch directory, so a preview can
     * warn about broken PHP before anything is written.
     */
    public function test_it_lints_proposed_contents_without_writing_them(): void
    {
        $validator = new SyntaxValidator($this->tempProject);

        $valid = $validator->validateContents(['app/New.php' => "<?php\n\nclass Widget\n{\n}\n"]);
        $this->assertTrue($valid->passed, implode(', ', $valid->errors));

        $broken = $validator->validateContents(['app/New.php' => "<?php\n\nclass {\n"]);
        $this->assertFalse($broken->passed);
        $this->assertStringContainsString('app/New.php', $broken->errors[0]);

        $this->assertFileDoesNotExist($this->tempProject . '/app/New.php');
    }

    public function test_it_skips_non_php_proposed_contents(): void
    {
        $result = (new SyntaxValidator($this->tempProject))
            ->validateContents(['README.md' => '# hi {{{']);

        $this->assertTrue($result->passed);
    }

    public function test_it_leaves_no_scratch_files_behind(): void
    {
        $before = glob(sys_get_temp_dir() . '/vigen-lint-*') ?: [];

        (new SyntaxValidator($this->tempProject))->validateContents(['app/A.php' => "<?php\n"]);

        $this->assertSame($before, glob(sys_get_temp_dir() . '/vigen-lint-*') ?: []);
    }
}
