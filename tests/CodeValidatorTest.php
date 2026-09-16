<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\Project\CodeValidator;

/**
 * The two bodies below are real. The first is what Vigen generated for the
 * prompt "create an authentication module" before the planner was told the
 * Vigen API - original Laravel imports and all. `php -l` passes on it, which
 * is exactly why nothing complained and the user got a 404.
 */
final class CodeValidatorTest extends TestCase
{
    private CodeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CodeValidator();
    }

    public function testTheLaravelControllerThatBrokeTheProjectIsFlagged(): void
    {
        $errors = $this->validator->validateContents([
            'app/Http/Controllers/AuthController.php' => self::laravelController(),
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('Illuminate', implode("\n", $errors));
    }

    public function testTheLaravelRouteFileIsFlagged(): void
    {
        $errors = $this->validator->validateContents([
            'routes/web.php' => self::laravelRoutes(),
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('Illuminate', implode("\n", $errors));
    }

    public function testTheLaravelModelIsFlagged(): void
    {
        $errors = $this->validator->validateContents([
            'app/Models/User.php' => "<?php\n\nnamespace App\\Models;\n\n"
                . "use Illuminate\\Database\\Eloquent\\Model;\n\n"
                . "class User extends Model\n{\n}\n",
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('Eloquent', implode("\n", $errors));
    }

    /**
     * The other half of the job: Vigen-native code must pass cleanly, or the
     * fix loop would fight every correct generation.
     */
    public function testTheVigenNativeControllerPasses(): void
    {
        self::assertSame([], $this->validator->validateContents([
            'app/Http/Controllers/AuthController.php' => self::vigenController(),
        ]));
    }

    public function testTheVigenNativeRouteFilePasses(): void
    {
        $routes = <<<'PHP'
            <?php

            use App\Http\Controllers\AuthController;
            use Vigen\View\View;

            $router->get('/', fn () => View::render('welcome'));
            $router->get('/login', [AuthController::class, 'showLogin']);
            $router->post('/login', [AuthController::class, 'login']);
            PHP;

        self::assertSame([], $this->validator->validateContents(['routes/web.php' => $routes]));
    }

    public function testTheVigenNativeModelPasses(): void
    {
        $model = <<<'PHP'
            <?php

            namespace App\Models;

            use Vigen\Database\Model;

            class User extends Model
            {
                protected static string $table = 'users';

                protected array $fillable = ['name', 'email', 'password'];
                protected array $hidden = ['password'];
            }
            PHP;

        self::assertSame([], $this->validator->validateContents(['app/Models/User.php' => $model]));
    }

    public function testAMigrationClosurePasses(): void
    {
        $migration = <<<'PHP'
            <?php

            return [
                'up' => function (\Vigen\Database\Connection $db): void {
                    $db->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
                },
                'down' => function (\Vigen\Database\Connection $db): void {
                    $db->statement('DROP TABLE users');
                },
            ];
            PHP;

        self::assertSame([], $this->validator->validateContents([
            'database/migrations/2026_09_16_000000_create_users_table.php' => $migration,
        ]));
    }

    /**
     * A bare `Route::get()` with no import is the other common shape - the
     * model "knows" the facade is global.
     */
    public function testABareForeignFacadeIsFlagged(): void
    {
        $errors = $this->validator->validateContents([
            'routes/web.php' => "<?php\n\nRoute::get('/login', 'AuthController@showLogin');\n",
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('Route', implode("\n", $errors));
    }

    public function testAForeignHelperFunctionIsFlagged(): void
    {
        $errors = $this->validator->validateContents([
            'app/Http/Controllers/AuthController.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\n"
                . "class AuthController\n{\n"
                . "    public function login()\n    {\n"
                . "        return response()->json(['ok' => true]);\n"
                . "    }\n}\n",
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('response()', implode("\n", $errors));
    }

    public function testAControllerExtendingAMissingBaseClassIsFlagged(): void
    {
        $errors = $this->validator->validateContents([
            'app/Http/Controllers/PostController.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\n"
                . "class PostController extends Controller\n{\n}\n",
        ]);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('extend', implode("\n", $errors));
    }

    /**
     * A project's own classes must never be mistaken for framework ones - the
     * validator would otherwise flag correct code forever.
     */
    public function testAProjectClassNamedLikeAForeignOneIsNotFlaggedWhenImported(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use App\Support\Route;

            class ReportController
            {
                public function index(): Route
                {
                    return new Route('/reports');
                }
            }
            PHP;

        self::assertSame([], $this->validator->validateContents([
            'app/Http/Controllers/ReportController.php' => $code,
        ]));
    }

    public function testVigenHelpersAreNotFlagged(): void
    {
        $code = <<<'PHP'
            <?php

            $name = config('app.name', 'Vigen');
            $debug = env('APP_DEBUG', false);
            $path = base_path('storage');
            PHP;

        self::assertSame([], $this->validator->validateContents(['bootstrap.php' => $code]));
    }

    /**
     * A syntax error is SyntaxValidator's to report; duplicating it here would
     * send the model the same complaint twice.
     */
    public function testUnparseableCodeIsLeftToTheSyntaxValidator(): void
    {
        self::assertSame([], $this->validator->validateContents([
            'broken.php' => "<?php\n\nclass {\n",
        ]));
    }

    public function testNonPhpFilesAreIgnored(): void
    {
        self::assertSame([], $this->validator->validateContents([
            'resources/views/auth/login.php.txt' => 'use Illuminate\\Support\\Facades\\Route;',
            'README.md' => 'Illuminate\\Support\\Facades\\Route',
        ]));
    }

    public function testItReportsAPathAndLineSoTheErrorIsFindable(): void
    {
        $errors = $this->validator->validateContents([
            'routes/web.php' => self::laravelRoutes(),
        ]);

        self::assertMatchesRegularExpression('#routes/web\.php:\d+#', implode("\n", $errors));
    }

    /**
     * The controller Vigen actually generated for the user.
     */
    private static function laravelController(): string
    {
        return <<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use App\Models\User;
            use Illuminate\Http\Request;
            use Illuminate\Support\Facades\Hash;

            class AuthController extends Controller
            {
                public function register(Request $request)
                {
                    $request->validate([
                        'name' => 'required|string|max:255',
                        'email' => 'required|string|email|unique:users',
                        'password' => 'required|string|min:8|confirmed',
                    ]);

                    $user = User::create([
                        'name' => $request->name,
                        'email' => $request->email,
                        'password' => Hash::make($request->password),
                    ]);

                    return response()->json(['user' => $user], 201);
                }

                public function login(Request $request)
                {
                    $request->validate([
                        'email' => 'required|string|email',
                        'password' => 'required|string',
                    ]);

                    $user = User::where('email', $request->email)->first();

                    if (! $user || ! Hash::check($request->password, $user->password)) {
                        return response()->json(['message' => 'Invalid credentials'], 401);
                    }

                    return response()->json(['token' => $user->createToken('auth')->plainTextToken]);
                }
            }
            PHP;
    }

    private static function laravelRoutes(): string
    {
        return <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;
            use App\Http\Controllers\AuthController;

            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);
            Route::post('/logout', [AuthController::class, 'logout']);
            PHP;
    }

    /**
     * The Vigen-native equivalent of the same feature.
     */
    private static function vigenController(): string
    {
        return <<<'PHP'
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

                public function login(Request $request): Response
                {
                    $data = $request->validate([
                        'email' => 'required|email',
                        'password' => 'required|string',
                    ]);

                    if (! Auth::attempt($data['email'], $data['password'])) {
                        return View::render('auth.login', ['error' => 'Invalid credentials']);
                    }

                    return Response::redirect('/dashboard');
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
            }
            PHP;
    }
}
