<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\Project\ProjectContext;

class ProjectContextTest extends TestCase
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

    public function test_it_returns_the_contents_of_relevant_files(): void
    {
        $this->writeFixture('app/Models/User.php', "<?php\n\nclass User\n{\n}\n");
        $this->writeFixture('app/Models/Post.php', "<?php\n\nclass Post\n{\n}\n");

        $files = (new ProjectContext($this->tempProject))
            ->relevantFileContents('create a user authentication module');

        $this->assertArrayHasKey('app/Models/User.php', $files);
        $this->assertStringContainsString('class User', $files['app/Models/User.php']);
        $this->assertArrayNotHasKey('app/Models/Post.php', $files);
    }

    /**
     * Third-party code is never worth spending the model's context window on.
     */
    public function test_it_skips_vendor_directories(): void
    {
        $this->writeFixture('app/vendor/User.php', "<?php\n\nclass Vendor_User\n{\n}\n");

        $files = (new ProjectContext($this->tempProject))
            ->relevantFileContents('create a user module');

        $this->assertArrayNotHasKey('app/vendor/User.php', $files);
    }

    /**
     * Keywords must be matched against the project-relative path, not the
     * absolute one. Matching absolutely means the project directory's own
     * name matches every file in it - and since a Windows checkout is always
     * under \Users\, the keyword "user" would return the entire tree.
     */
    public function test_the_project_directory_name_does_not_match_every_file(): void
    {
        $project = $this->tempProject . '/user-portal';
        mkdir($project . '/app/Models', 0755, true);
        file_put_contents($project . '/app/Models/Post.php', "<?php\n\nclass Post\n{\n}\n");

        $files = (new ProjectContext($project))->relevantFileContents('create a user module');

        $this->assertArrayNotHasKey('app/Models/Post.php', $files);
    }

    public function test_it_marks_a_truncated_file(): void
    {
        $this->writeFixture('app/Models/User.php', str_repeat('x', 500));

        $files = (new ProjectContext($this->tempProject))
            ->relevantFileContents('user model', maxBytes: 100);

        $this->assertArrayHasKey('app/Models/User.php', $files);
        $this->assertStringContainsString('[truncated by Vigen]', $files['app/Models/User.php']);
    }

    public function test_it_returns_an_empty_map_when_nothing_is_relevant(): void
    {
        $this->writeFixture('app/Models/User.php', "<?php\n");

        $files = (new ProjectContext($this->tempProject))
            ->relevantFileContents('configure the mailer');

        $this->assertSame([], $files);
    }
}
