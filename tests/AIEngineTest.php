<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\AI\AIEngine;
use Vigen\Project\ProjectContext;
use Vigen\Project\WriteResult;

/**
 * End-to-end coverage of the prompt -> generate -> write -> validate pipeline
 * against a real scratch project, using a provider that replays canned
 * envelopes.
 */
class AIEngineTest extends TestCase
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

    private function engine(FakeProvider $provider): AIEngine
    {
        return new AIEngine($provider, new ProjectContext($this->tempProject));
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    private function plan(string $summary, array $files): string
    {
        return (string) json_encode(['summary' => $summary, 'files' => $files], JSON_UNESCAPED_SLASHES);
    }

    public function test_it_writes_generated_files_to_disk(): void
    {
        $provider = FakeProvider::replyingWith($this->plan('Adds a User model', [
            [
                'path' => 'app/Models/User.php',
                'action' => 'create',
                'contents' => "<?php\n\nclass User\n{\n}\n",
                'reason' => 'needed for authentication',
            ],
        ]));

        $result = $this->engine($provider)->handle('create an authentication module');

        $this->assertTrue($result->isSuccessful(), implode(', ', $result->validation->errors));
        $this->assertFileExists($this->tempProject . '/app/Models/User.php');
        $this->assertCount(1, $result->writes);
        $this->assertSame(WriteResult::STATUS_CREATED, $result->writes[0]->status);
        $this->assertCount(1, $result->changedWrites());
        $this->assertSame('Adds a User model', $result->plan->summary());
    }

    public function test_it_asks_the_provider_for_structured_output(): void
    {
        $provider = FakeProvider::replyingWith($this->plan('nothing', []));

        $this->engine($provider)->handle('anything');

        $this->assertTrue(
            $provider->lastOptions()['json'] ?? false,
            'The engine should request native JSON output from the provider.'
        );
    }

    public function test_the_system_prompt_states_the_json_contract_and_conventions(): void
    {
        $provider = FakeProvider::replyingWith($this->plan('nothing', []));

        $this->engine($provider)->handle('anything');

        $system = $provider->lastMessages()[0]['content'];

        $this->assertStringContainsString('JSON', $system);
        $this->assertStringContainsString('"files"', $system);
        $this->assertStringContainsString('app/Models', $system);
    }

    /**
     * Without the existing bodies the model would rewrite a file like
     * routes/web.php from scratch and destroy what was already in it.
     */
    public function test_the_request_includes_the_contents_of_existing_files(): void
    {
        $this->writeFixture('routes/web.php', "<?php\n\n// pre-existing route\n");

        $provider = FakeProvider::replyingWith($this->plan('nothing', []));

        $this->engine($provider)->handle('add a route to routes/web.php');

        $this->assertStringContainsString('pre-existing route', $provider->lastMessages()[1]['content']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $provider = FakeProvider::replyingWith($this->plan('Adds a User model', [
            ['path' => 'app/Models/User.php', 'action' => 'create', 'contents' => "<?php\n\nclass User\n{\n}\n"],
        ]));

        $result = $this->engine($provider)->handle('create auth', dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertSame([], $result->writes);
        $this->assertFileDoesNotExist($this->tempProject . '/app/Models/User.php');
        $this->assertCount(1, $result->changes);
    }

    public function test_a_dry_run_still_reports_broken_php(): void
    {
        $provider = FakeProvider::replyingWith($this->plan('Breaks a file', [
            ['path' => 'app/Broken.php', 'action' => 'create', 'contents' => "<?php\n\nclass {\n"],
        ]));

        $result = $this->engine($provider)->handle('break it', dryRun: true);

        $this->assertFalse($result->validation->passed);
        $this->assertFileDoesNotExist($this->tempProject . '/app/Broken.php');
    }

    /**
     * The behaviour that made this bug so hard to find: an unusable response
     * used to produce a cheerful "Done." and no explanation.
     */
    public function test_an_unusable_response_is_explained_instead_of_being_silent(): void
    {
        $provider = FakeProvider::replyingWith('I am not able to help with that.');

        $result = $this->engine($provider)->handle('create auth');

        $this->assertSame([], $result->changes);
        $this->assertSame([], $result->writes);
        $this->assertNotEmpty($result->diagnostics());
        $this->assertStringContainsString('No JSON object', $result->diagnostics()[0]);
    }

    public function test_it_retries_once_through_the_fix_loop(): void
    {
        $provider = FakeProvider::sequence(
            FakeProvider::envelope($this->plan('First attempt', [
                ['path' => 'app/Broken.php', 'action' => 'create', 'contents' => "<?php\n\nclass {\n"],
            ])),
            FakeProvider::envelope($this->plan('Corrected', [
                ['path' => 'app/Broken.php', 'action' => 'modify', 'contents' => "<?php\n\nclass Broken\n{\n}\n"],
            ])),
        );

        $result = $this->engine($provider)->handle('make a thing');

        $this->assertSame(2, $provider->callCount(), 'The engine should have retried exactly once.');
        $this->assertTrue($result->validation->passed, implode(', ', $result->validation->errors));
        $this->assertStringContainsString(
            'class Broken',
            (string) file_get_contents($this->tempProject . '/app/Broken.php')
        );
    }

    public function test_a_rejected_path_fails_the_run(): void
    {
        $provider = FakeProvider::replyingWith($this->plan('Escapes', [
            ['path' => '../escaped.php', 'action' => 'create', 'contents' => "<?php\n"],
        ]));

        $result = $this->engine($provider)->handle('escape the sandbox');

        $this->assertFalse($result->isSuccessful());
        $this->assertCount(1, $result->failedWrites());
        $this->assertSame(WriteResult::STATUS_REJECTED, $result->failedWrites()[0]->status);
    }

    public function test_it_modifies_an_existing_file_surgically(): void
    {
        $this->writeFixture('routes/web.php', "<?php\n\n// keep me\n");

        $provider = FakeProvider::replyingWith($this->plan('Adds a route', [
            [
                'path' => 'routes/web.php',
                'action' => 'modify',
                'contents' => "<?php\n\n// keep me\n\n// added by vigen\n",
            ],
        ]));

        $result = $this->engine($provider)->handle('add a route');

        $this->assertTrue($result->isSuccessful(), implode(', ', $result->validation->errors));

        $contents = (string) file_get_contents($this->tempProject . '/routes/web.php');
        $this->assertStringContainsString('// keep me', $contents);
        $this->assertStringContainsString('// added by vigen', $contents);
        $this->assertNotEmpty(glob($this->tempProject . '/.vigen/backups/*/routes/web.php') ?: []);
    }
}
