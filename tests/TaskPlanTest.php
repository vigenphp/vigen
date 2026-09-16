<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\AI\FileChange;
use Vigen\AI\TaskPlan;

class TaskPlanTest extends TestCase
{
    /**
     * @param array<string, mixed> $plan
     */
    private static function encode(array $plan): string
    {
        return (string) json_encode($plan, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private static function plan(string $path = 'app/Models/User.php', string $contents = "<?php\n\nclass User\n{\n}\n"): array
    {
        return [
            'summary' => 'Adds a User model',
            'files' => [
                [
                    'path' => $path,
                    'action' => 'create',
                    'contents' => $contents,
                    'reason' => 'needed for authentication',
                ],
            ],
        ];
    }

    public function test_it_parses_a_plain_json_plan(): void
    {
        $plan = TaskPlan::fromProviderText('create auth', self::encode(self::plan()));

        $this->assertCount(1, $plan->steps);
        $this->assertSame('app/Models/User.php', $plan->steps[0]['path']);
        $this->assertSame(FileChange::ACTION_CREATE, $plan->steps[0]['action']);
        $this->assertSame([], $plan->problems);
        $this->assertSame('Adds a User model', $plan->summary());
    }

    public function test_it_parses_json_inside_a_markdown_fence(): void
    {
        $text = "Here is what I'll do:\n\n```json\n" . self::encode(self::plan()) . "\n```\n\nLet me know!";

        $plan = TaskPlan::fromProviderText('create auth', $text);

        $this->assertCount(1, $plan->steps);
        $this->assertSame([], $plan->problems);
    }

    public function test_it_parses_json_surrounded_by_prose_without_a_fence(): void
    {
        $text = 'Sure! ' . self::encode(self::plan()) . ' Hope that helps.';

        $plan = TaskPlan::fromProviderText('create auth', $text);

        $this->assertCount(1, $plan->steps);
    }

    public function test_it_recovers_from_a_trailing_comma(): void
    {
        $text = '{"summary":"x","files":[{"path":"a.php","action":"create","contents":"<?php","reason":"r"},]}';

        $plan = TaskPlan::fromProviderText('create a', $text);

        $this->assertCount(1, $plan->steps);
        $this->assertSame([], $plan->problems);
    }

    public function test_it_accepts_the_legacy_steps_key(): void
    {
        $text = self::encode([
            'steps' => [
                ['path' => 'app/Foo.php', 'action' => 'create', 'contents' => "<?php\n", 'reason' => 'r'],
            ],
        ]);

        $plan = TaskPlan::fromProviderText('foo', $text);

        $this->assertCount(1, $plan->steps);
        $this->assertSame('app/Foo.php', $plan->steps[0]['path']);
    }

    public function test_it_reports_a_problem_when_no_json_is_present(): void
    {
        $plan = TaskPlan::fromProviderText('create auth', 'I cannot help with that request.');

        $this->assertSame([], $plan->steps);
        $this->assertCount(1, $plan->problems);
        $this->assertStringContainsString('No JSON object could be parsed', $plan->problems[0]);
    }

    public function test_it_reports_a_problem_when_the_files_key_is_missing(): void
    {
        $plan = TaskPlan::fromProviderText('create auth', '{"summary":"nothing here"}');

        $this->assertSame([], $plan->steps);
        $this->assertCount(1, $plan->problems);
        $this->assertStringContainsString('no "files" array', $plan->problems[0]);
    }

    /**
     * A create with an empty body would write an empty file, which is almost
     * always the model truncating its answer - it must be refused loudly
     * rather than silently applied.
     */
    public function test_it_rejects_create_entries_without_contents(): void
    {
        $text = self::encode([
            'files' => [
                ['path' => 'app/Models/User.php', 'action' => 'create', 'reason' => 'needed'],
            ],
        ]);

        $plan = TaskPlan::fromProviderText('create auth', $text);

        $this->assertSame([], $plan->steps);
        $this->assertCount(1, $plan->problems);
        $this->assertStringContainsString('refused to write an empty file', $plan->problems[0]);
    }

    public function test_it_rejects_a_whitespace_only_body(): void
    {
        $text = self::encode([
            'files' => [['path' => 'app/x.php', 'action' => 'create', 'contents' => "   \n  "]],
        ]);

        $plan = TaskPlan::fromProviderText('x', $text);

        $this->assertSame([], $plan->steps);
        $this->assertCount(1, $plan->problems);
    }

    public function test_it_keeps_delete_entries_without_contents(): void
    {
        $text = self::encode([
            'files' => [['path' => 'app/Old.php', 'action' => 'delete', 'reason' => 'unused']],
        ]);

        $plan = TaskPlan::fromProviderText('cleanup', $text);

        $this->assertCount(1, $plan->steps);
        $this->assertSame(FileChange::ACTION_DELETE, $plan->steps[0]['action']);
        $this->assertNull($plan->steps[0]['contents']);
        $this->assertSame([], $plan->problems);
    }

    public function test_it_rejects_unsupported_actions(): void
    {
        $text = self::encode([
            'files' => [['path' => 'app/x.php', 'action' => 'rewrite', 'contents' => "<?php\n"]],
        ]);

        $plan = TaskPlan::fromProviderText('x', $text);

        $this->assertSame([], $plan->steps);
        $this->assertStringContainsString('unsupported action', $plan->problems[0]);
    }

    public function test_it_rejects_entries_without_a_path(): void
    {
        $text = self::encode([
            'files' => [['action' => 'create', 'contents' => "<?php\n"]],
        ]);

        $plan = TaskPlan::fromProviderText('x', $text);

        $this->assertSame([], $plan->steps);
        $this->assertStringContainsString('had no "path"', $plan->problems[0]);
    }

    public function test_it_converts_steps_into_file_changes(): void
    {
        $plan = TaskPlan::fromProviderText('create auth', self::encode(self::plan()));
        $changes = $plan->toFileChanges();

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(FileChange::class, $changes[0]);
        $this->assertSame('app/Models/User.php', $changes[0]->path);
        $this->assertStringContainsString('class User', (string) $changes[0]->contents);
    }

    public function test_it_returns_no_summary_when_absent(): void
    {
        $plan = TaskPlan::fromProviderText('x', '{"files":[]}');

        $this->assertNull($plan->summary());
    }
}
