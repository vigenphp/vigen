<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Vigen\Auth\Auth;
use Vigen\Core\Application;
use Vigen\Database\Connection;
use Vigen\Http\Kernel;
use Vigen\Http\Request;
use Vigen\Http\Response;
use Vigen\Http\Session;
use Vigen\View\View;

/**
 * The test that matters most: a real project on disk, served through the real
 * kernel.
 *
 * It writes the files Vigen is supposed to generate - a routes file, a
 * controller, a model, views - and asserts that GET /login renders a form and
 * POST /login authenticates. That is the exact journey that returned 404
 * before this runtime existed.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class KernelTest extends TestCase
{
    use CreatesTempProject;

    /** @var array<string, mixed> */
    private array $session = [];

    private Application $app;

    /** @var callable(string): void */
    private $autoloader;

    protected function setUp(): void
    {
        $basePath = $this->createTempProject();

        $this->writeProject($basePath);

        // The generated project's App\ classes live only in the scratch
        // directory, so they need an autoloader of their own.
        $autoloader = static function (string $class) use ($basePath): void {
            if (! str_starts_with($class, 'App\\')) {
                return;
            }

            // PSR-4 for a project whose root namespace is App\ but whose
            // directory is the conventional lowercase "app/".
            $relative = str_replace('\\', '/', $class);
            $file = $basePath . '/' . lcfirst($relative) . '.php';

            if (is_file($file)) {
                require $file;
            }
        };

        spl_autoload_register($autoloader);
        $this->autoloader = $autoloader;

        $this->app = new Application($basePath);

        Connection::swap(new Connection(
            ['driver' => 'sqlite', 'database' => ':memory:'],
            $basePath
        ));

        Connection::current()->statement('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at TEXT,
            updated_at TEXT
        )');

        $this->session = [];
        Session::use($this->session);
        Auth::reset();
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloader);
        Auth::reset();
        Session::reset();
        Connection::swap(null);
        Application::forget();
        $this->removeTempProject();
    }

    /**
     * The reported bug: browsing to /login returned 404 because nothing
     * matched a GET.
     */
    public function testGetLoginRendersAForm(): void
    {
        $response = $this->get('/login');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<form', $response->body());
        self::assertStringContainsString('name="email"', $response->body());
        self::assertStringContainsString('name="password"', $response->body());
    }

    public function testGetRegisterRendersAForm(): void
    {
        $response = $this->get('/register');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<form', $response->body());
    }

    /**
     * The CSRF field is not optional decoration - without it the POST is
     * rejected with a 419, so a generated form that omits it is broken.
     */
    public function testTheRenderedFormCarriesACsrfToken(): void
    {
        $response = $this->get('/login');

        self::assertStringContainsString('name="_token"', $response->body());
        self::assertStringContainsString(Session::token(), $response->body());
    }

    public function testTheWelcomePageRenders(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Vigen', $response->body());
    }

    public function testRegisteringCreatesAUserAndLogsThemIn(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);

        self::assertSame(302, $response->status());
        self::assertSame('/dashboard', $response->header('Location'));

        $rows = Connection::current()->select('select * from users');

        self::assertCount(1, $rows);
        self::assertSame('ada@example.com', $rows[0]['email']);
        self::assertNotSame('supersecret', $rows[0]['password']);
    }

    public function testLoggingInWithTheRightPasswordRedirects(): void
    {
        $this->registerUser('ada@example.com', 'supersecret');

        $response = $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'supersecret',
        ]);

        self::assertSame(302, $response->status());
        self::assertSame('/dashboard', $response->header('Location'));
    }

    public function testLoggingInWithTheWrongPasswordReRendersWithAnError(): void
    {
        $this->registerUser('ada@example.com', 'supersecret');

        $response = $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Invalid credentials', $response->body());
    }

    /**
     * A validation failure redirects back to the form with the errors and the
     * old input flashed, rather than losing what the user typed.
     */
    public function testAFailedRegistrationRedirectsBackWithErrors(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ada',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'short',
        ], ['Referer' => 'http://localhost/register']);

        self::assertSame(302, $response->status());
        self::assertSame('/register', $response->header('Location'));

        $next = $this->get('/register');

        self::assertStringContainsString('valid email', $next->body());
    }

    public function testPasswordsAreNeverFlashedBackIntoTheForm(): void
    {
        // Too short, so validation fails and the input is flashed back.
        $this->post('/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ], ['Referer' => 'http://localhost/register']);

        $old = Session::getFlash('old');

        self::assertIsArray($old);
        self::assertSame('Ada', $old['name']);
        self::assertArrayNotHasKey('password', $old);
        self::assertArrayNotHasKey('password_confirmation', $old);
    }

    public function testAnUnknownPathReturnsA404(): void
    {
        $response = $this->get('/nowhere');

        self::assertSame(404, $response->status());
    }

    /**
     * A wrong verb is a 405 naming the verbs that work, which is the
     * difference between a one-line fix and an afternoon of guessing.
     */
    public function testTheWrongVerbReturnsA405(): void
    {
        $response = $this->get('/logout');

        self::assertSame(405, $response->status());
    }

    public function testAGuestIsRedirectedAwayFromAProtectedRoute(): void
    {
        $response = $this->get('/dashboard');

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function testTheIntendedPathSurvivesTheLoginRedirect(): void
    {
        $this->get('/dashboard');

        self::assertSame('/dashboard', Session::get('url.intended'));
    }

    public function testAnAuthenticatedUserReachesTheProtectedRoute(): void
    {
        $this->registerUser('ada@example.com', 'supersecret');

        $response = $this->get('/dashboard');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('ada@example.com', $response->body());
    }

    /**
     * CSRF is enforced on state-changing verbs: a POST that carries no token
     * is rejected, not silently accepted.
     */
    public function testAPostWithoutATokenIsRejectedWith419(): void
    {
        $response = $this->postRaw('/login', [
            'email' => 'ada@example.com',
            'password' => 'supersecret',
        ]);

        self::assertSame(419, $response->status());
        self::assertStringContainsString('_token', $response->body());
    }

    public function testAPostWithABadTokenIsRejected(): void
    {
        $this->get('/login');

        $response = $this->post('/login', [
            '_token' => 'not-the-token',
            'email' => 'ada@example.com',
            'password' => 'supersecret',
        ]);

        self::assertSame(419, $response->status());
    }

    public function testJsonClientsGetJsonErrors(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ada',
            'email' => 'not-an-email',
        ], ['Accept' => 'application/json', 'Content-Type' => 'application/json']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('application/json', (string) $response->header('Content-Type'));

        $payload = json_decode($response->body(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('errors', $payload);
    }

    /**
     * The 404 page lists the registered routes, which is how a developer sees
     * that their route never made it into the router.
     */
    public function testTheDefault404ListsRegisteredRoutes(): void
    {
        $response = $this->get('/nowhere');

        self::assertStringContainsString('GET /login', $response->body());
    }

    public function testAViewThatDoesNotExistProducesA500NotABlankPage(): void
    {
        $this->writeFixture(
            'routes/web.php',
            "<?php\n\nuse Vigen\\View\\View;\n\n\$router->get('/missing-view', fn () => View::render('nope.missing'));\n"
        );

        $this->writeFixture('config/app.php', "<?php\n\nreturn ['debug' => true];\n");

        $response = $this->freshKernel()->handle(new Request('GET', '/missing-view'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('nope.missing', $response->body());
    }

    /**
     * A thrown exception must be logged, or a 500 in production is
     * undiagnosable.
     */
    public function testAThrownExceptionIsLogged(): void
    {
        $this->writeFixture(
            'routes/web.php',
            "<?php\n\n\$router->get('/boom', function (): string {\n    throw new \\RuntimeException('kaboom');\n});\n"
        );

        $this->freshKernel()->handle(new Request('GET', '/boom'));

        $log = $this->tempProject . '/storage/logs/vigen.log';

        self::assertFileExists($log);
        self::assertStringContainsString('kaboom', (string) file_get_contents($log));
    }

    private function get(string $path, array $headers = []): Response
    {
        return $this->freshKernel()->handle(new Request('GET', $path, [], [], $headers));
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function post(string $path, array $body = [], array $headers = []): Response
    {
        // A real browser posts the token from the form it was served.
        if (! isset($body['_token']) && ! str_contains($headers['Content-Type'] ?? '', 'json')) {
            $token = Session::token();

            if ($token !== '') {
                $body['_token'] = $token;
            }
        }

        return $this->freshKernel()->handle(new Request('POST', $path, [], $body, $headers));
    }

    /**
     * A POST with no CSRF token at all, as a forged cross-site request would
     * arrive.
     *
     * @param array<string, mixed> $body
     */
    private function postRaw(string $path, array $body = []): Response
    {
        return $this->freshKernel()->handle(new Request('POST', $path, [], $body));
    }

    /**
     * A kernel that re-reads the route file, since a test may have rewritten
     * it. Sessions and the database persist across these, as they do across
     * requests in a browser.
     */
    private function freshKernel(): Kernel
    {
        View::reset();
        Application::forget();
        $this->app = new Application($this->tempProject);
        Session::use($this->session);

        return new Kernel($this->app);
    }

    private function registerUser(string $email, string $password): void
    {
        $this->post('/register', [
            'name' => 'Ada',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    /**
     * Writes the project Vigen should have generated: Vigen-native routes, a
     * controller with no base class, a model, and real views.
     */
    private function writeProject(string $basePath): void
    {
        $this->writeFixture('config/app.php', <<<'PHP'
            <?php

            return [
                'name' => 'Test App',
                'debug' => false,
                'csrf' => true,
                'paths' => [
                    'models' => 'app/Models',
                    'controllers' => 'app/Http/Controllers',
                    'routes' => 'routes/web.php',
                    'views' => 'resources/views',
                ],
            ];
            PHP);

        $this->writeFixture('config/auth.php', <<<'PHP'
            <?php

            return [
                'model' => App\Models\User::class,
                'username' => 'email',
                'login_path' => '/login',
                'home_path' => '/',
            ];
            PHP);

        $this->writeFixture('app/Models/User.php', <<<'PHP'
            <?php

            namespace App\Models;

            use Vigen\Database\Model;

            class User extends Model
            {
                protected static string $table = 'users';

                protected array $fillable = ['name', 'email', 'password'];
                protected array $hidden = ['password'];
            }
            PHP);

        $this->writeFixture('app/Http/Controllers/AuthController.php', <<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use App\Models\User;
            use Vigen\Auth\Auth;
            use Vigen\Auth\Hash;
            use Vigen\Http\Request;
            use Vigen\Http\Response;
            use Vigen\View\View;

            class AuthController
            {
                public function showLogin(Request $request): Response
                {
                    return View::render('auth.login');
                }

                public function showRegister(Request $request): Response
                {
                    return View::render('auth.register');
                }

                public function register(Request $request): Response
                {
                    $data = $request->validate([
                        'name' => 'required|string|max:255',
                        'email' => 'required|email|unique:users,email',
                        'password' => 'required|string|min:8|confirmed',
                    ]);

                    $user = User::create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'password' => Hash::make($data['password']),
                    ]);

                    Auth::login($user);

                    return Response::redirect('/dashboard');
                }

                public function login(Request $request): Response
                {
                    $data = $request->validate([
                        'email' => 'required|email',
                        'password' => 'required|string',
                    ]);

                    if (! Auth::attempt($data['email'], $data['password'])) {
                        return View::render('auth.login', ['error' => 'Invalid credentials'])
                            ->withStatus(422);
                    }

                    return Response::redirect('/dashboard');
                }

                public function logout(Request $request): Response
                {
                    Auth::logout();

                    return Response::redirect('/');
                }

                public function dashboard(Request $request): Response
                {
                    return View::render('dashboard', ['user' => Auth::user()]);
                }
            }
            PHP);

        $this->writeFixture('routes/web.php', <<<'PHP'
            <?php

            use App\Http\Controllers\AuthController;
            use Vigen\View\View;

            $router->get('/', fn () => View::render('welcome', ['title' => 'Test App']));

            $router->get('/login', [AuthController::class, 'showLogin']);
            $router->post('/login', [AuthController::class, 'login']);
            $router->get('/register', [AuthController::class, 'showRegister']);
            $router->post('/register', [AuthController::class, 'register']);
            $router->post('/logout', [AuthController::class, 'logout']);

            $router->get('/dashboard', [AuthController::class, 'dashboard'], ['auth']);
            PHP);

        $this->writeFixture('resources/views/welcome.php', '<h1><?= e($title) ?></h1><p>Vigen is running.</p>');

        $this->writeFixture('resources/views/auth/login.php', <<<'PHP'
            <h1>Log in</h1>
            <?php if (! empty($error)): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
            <?php if (View::error('email')): ?><p class="error"><?= e(View::error('email')) ?></p><?php endif; ?>
            <form method="POST" action="/login">
                <input type="hidden" name="_token" value="<?= e(Vigen\Http\Session::token()) ?>">
                <input name="email" value="<?= e(View::old('email')) ?>">
                <input name="password" type="password">
                <button type="submit">Log in</button>
            </form>
            PHP);

        $this->writeFixture('resources/views/auth/register.php', <<<'PHP'
            <h1>Register</h1>
            <?php foreach (View::errors() as $field => $message): ?>
                <p class="error"><?= e($message) ?></p>
            <?php endforeach; ?>
            <form method="POST" action="/register">
                <input type="hidden" name="_token" value="<?= e(Vigen\Http\Session::token()) ?>">
                <input name="name" value="<?= e(View::old('name')) ?>">
                <input name="email" value="<?= e(View::old('email')) ?>">
                <input name="password" type="password">
                <input name="password_confirmation" type="password">
                <button type="submit">Register</button>
            </form>
            PHP);

        $this->writeFixture('resources/views/dashboard.php', '<h1>Dashboard</h1><p><?= e($user->email) ?></p>');
    }
}
