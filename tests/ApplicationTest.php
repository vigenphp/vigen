<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\Core\Application;

/**
 * A Vigen project's composer.json carries no "autoload" section, so nothing
 * ever mapped App\ to app/ - and Composer cannot map a namespace it was never
 * told about, however many times dump-autoload runs.
 *
 * The result was that every controller in every generated project was
 * unreachable. The file sat exactly where it belonged, class_exists() returned
 * false, and the Router reported "A route points at controller
 * [App\Http\Controllers\AuthController], which does not exist" for the whole
 * site. Boot now registers that mapping itself, so no dump-autoload step and
 * no re-scaffold are needed.
 *
 * Each test declares a differently named class: PHP declares a class once per
 * process, so two tests sharing a name would pass vacuously the moment either
 * one ran first, whatever the autoloader did.
 *
 * Needs no database, so it runs on any PHP build.
 */
final class ApplicationTest extends TestCase
{
    use CreatesTempProject;

    protected function setUp(): void
    {
        $this->createTempProject();
    }

    protected function tearDown(): void
    {
        Application::forget();
        $this->removeTempProject();
    }

    public function testAControllerIsLoadableAfterBoot(): void
    {
        $this->writeClass(
            'app/Http/Controllers/BootProbeController.php',
            'App\Http\Controllers',
            'BootProbeController'
        );

        new Application($this->tempProject);

        self::assertTrue(class_exists('App\\Http\\Controllers\\BootProbeController'));
    }

    /**
     * The mapping is PSR-4 for the whole App\ root, not a special case for
     * controllers - so models, middleware and anything else under app/ resolve
     * from the same rule.
     */
    public function testAModelInItsOwnSubdirectoryResolves(): void
    {
        $this->writeClass('app/Models/BootProbeModel.php', 'App\Models', 'BootProbeModel');

        new Application($this->tempProject);

        self::assertTrue(class_exists('App\\Models\\BootProbeModel'));
    }

    /**
     * Only App\ is claimed. The autoloader must not report a class as present
     * merely because a file with a plausible name exists.
     */
    public function testAnAbsentProjectClassIsStillAbsent(): void
    {
        new Application($this->tempProject);

        self::assertFalse(class_exists('App\\Models\\NothingHere'));
    }

    /**
     * Boot is idempotent: a second Application for the same project must not
     * register a duplicate autoloader.
     */
    public function testBootingTwiceForTheSameProjectIsHarmless(): void
    {
        $this->writeClass('app/Models/BootProbeTwice.php', 'App\Models', 'BootProbeTwice');

        new Application($this->tempProject);
        new Application($this->tempProject);

        self::assertTrue(class_exists('App\\Models\\BootProbeTwice'));
    }

    private function writeClass(string $path, string $namespace, string $class): void
    {
        $this->writeFixture($path, <<<PHP
            <?php

            namespace {$namespace};

            class {$class}
            {
            }

            PHP);
    }
}
