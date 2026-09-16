<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\Project\Scaffolder;
use Vigen\Providers\OllamaProvider;
use Vigen\Providers\ProviderFactory;

/**
 * The scaffold is what a brand-new project gets, so anything wrong here is
 * wrong for every user before they have written a line of code.
 */
final class ScaffolderTest extends TestCase
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

    private function scaffold(): array
    {
        return (new Scaffolder($this->tempProject))->scaffold();
    }

    public function testScaffoldCreatesTheDirectoriesTheRuntimeWritesTo(): void
    {
        $created = $this->scaffold();

        foreach (Scaffolder::DIRECTORIES as $dir) {
            self::assertDirectoryExists($this->tempProject . '/' . $dir, "{$dir} was not created");
            self::assertContains($dir, $created);
        }
    }

    public function testScaffoldPublishesTheFilesARequestNeeds(): void
    {
        $this->scaffold();

        // Every one of these is on the path a first request takes: the front
        // controller, the route file the kernel requires, the config the
        // kernel and the database connection read, and the two views a fresh
        // project renders.
        foreach ([
            'public/index.php',
            'routes/web.php',
            'config/app.php',
            'config/database.php',
            'config/auth.php',
            'resources/views/welcome.php',
            'resources/views/errors/404.php',
            '.env.example',
            '.gitignore',
        ] as $file) {
            self::assertFileExists($this->tempProject . '/' . $file, "{$file} was not published");
        }
    }

    public function testThePublishedAppConfigCarriesTheKeysTheKernelReads(): void
    {
        $this->scaffold();

        $config = require $this->tempProject . '/config/app.php';

        self::assertIsArray($config);

        // The kernel reads exactly these; a missing one silently changes
        // behaviour (csrf off, or debug on) rather than failing loudly.
        foreach (['name', 'debug', 'csrf', 'middleware', 'paths'] as $key) {
            self::assertArrayHasKey($key, $config);
        }

        // "csrf" is a literal, so asserting its value is safe. "debug" is
        // deliberately only checked for presence: it is env('APP_DEBUG', false),
        // so its value belongs to the machine running the suite.
        self::assertTrue($config['csrf']);
    }

    public function testThePublishedRouteFileRegistersAWelcomeRoute(): void
    {
        $this->scaffold();

        $routes = (string) file_get_contents($this->tempProject . '/routes/web.php');

        self::assertStringContainsString("\$router->get('/'", $routes);
        self::assertStringContainsString('welcome', $routes);
    }

    /**
     * The bug this guards: the stub wrote AI_PROVIDER / AI_MODEL while
     * ProviderFactory reads VIGEN_AI_PROVIDER / VIGEN_AI_MODEL, so a freshly
     * scaffolded .env was silently ignored - no error, just the default
     * provider and an empty model name.
     */
    public function testTheScaffoldedEnvExampleUsesTheNamesTheFactoryReads(): void
    {
        $this->scaffold();

        $values = $this->envExampleValues();

        self::assertArrayHasKey('VIGEN_AI_PROVIDER', $values);
        self::assertArrayHasKey('VIGEN_AI_MODEL', $values);
    }

    public function testTheScaffoldedEnvExampleActuallyConfiguresTheFactory(): void
    {
        $this->scaffold();

        $values = $this->envExampleValues();
        $original = $_ENV;

        $_ENV['VIGEN_AI_PROVIDER'] = $values['VIGEN_AI_PROVIDER'];
        $_ENV['VIGEN_AI_MODEL'] = $values['VIGEN_AI_MODEL'];

        try {
            $provider = ProviderFactory::make();
        } finally {
            $_ENV = $original;
        }

        self::assertInstanceOf(OllamaProvider::class, $provider);
        self::assertSame($values['VIGEN_AI_MODEL'], $provider->model());
    }

    public function testTheEnvExampleDocumentsTheDatabaseKeys(): void
    {
        $this->scaffold();

        $values = $this->envExampleValues();

        self::assertSame('sqlite', $values['DB_CONNECTION'] ?? null);
    }

    public function testScaffoldNeverOverwritesAnEditedFile(): void
    {
        $this->scaffold();

        $routeFile = $this->tempProject . '/routes/web.php';
        file_put_contents($routeFile, "<?php\n// edited by the user\n");

        $this->scaffold();

        self::assertStringContainsString(
            'edited by the user',
            (string) file_get_contents($routeFile)
        );
    }

    public function testPublishConfigReportsOnlyWhatItWrote(): void
    {
        $this->scaffold();

        // Everything is already published, so a second run writes nothing.
        self::assertSame([], (new Scaffolder($this->tempProject))->publishConfig());

        unlink($this->tempProject . '/config/auth.php');

        self::assertSame(['config/auth.php'], (new Scaffolder($this->tempProject))->publishConfig());
    }

    public function testGitignoreCoversTheGeneratedDatabaseAndLogs(): void
    {
        $this->scaffold();

        $gitignore = (string) file_get_contents($this->tempProject . '/.gitignore');

        self::assertStringContainsString('/.env', $gitignore);
        self::assertStringContainsString('/database/*.sqlite', $gitignore);
        self::assertStringContainsString('/storage/logs/', $gitignore);
        self::assertStringContainsString('/vendor/', $gitignore);
    }

    /**
     * Read the scaffolded .env.example as key => value, ignoring comments and
     * blank lines - the same shape Dotenv parses.
     *
     * @return array<string, string>
     */
    private function envExampleValues(): array
    {
        $path = $this->tempProject . '/.env.example';

        self::assertFileExists($path);

        $values = [];

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[trim($key)] = trim($value, " \t\"'");
        }

        return $values;
    }
}
