<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\AI\ApiReference;
use Vigen\Project\Conventions;

/**
 * The prompt is the fix. These tests exist because the runtime alone did not
 * solve the user's problem: the planner was told Laravel's directory layout
 * and nothing about Vigen's API, so it wrote Laravel - and no router could
 * have run that code.
 */
final class ApiReferenceTest extends TestCase
{
    public function testItStatesThatVigenIsNotLaravel(): void
    {
        self::assertStringContainsString('Vigen is NOT Laravel', ApiReference::NOT_LARAVEL);
    }

    public function testItNamesEveryForeignNamespaceThatMustNotAppear(): void
    {
        $reference = ApiReference::forPrompt();

        foreach (['Illuminate\\', 'Laravel\\', 'Symfony\\'] as $namespace) {
            self::assertStringContainsString($namespace, $reference);
        }
    }

    public function testItNamesTheForbiddenFacades(): void
    {
        $reference = ApiReference::forPrompt();

        foreach (['Route', 'Hash', 'Auth', 'DB', 'Schema', 'Blade'] as $facade) {
            self::assertStringContainsString($facade, $reference);
        }
    }

    public function testItForbidsTheLaravelGlobalHelpers(): void
    {
        $reference = ApiReference::forPrompt();

        foreach (['response()', 'redirect()', 'view()', 'abort()', 'csrf_field()'] as $helper) {
            self::assertStringContainsString($helper, $reference);
        }
    }

    /**
     * The rule that turns an API into a browsable site. Without it the model
     * generates POST-only JSON routes and GET /login has nothing to match -
     * which is exactly the 404 the user reported.
     */
    public function testItRequiresGetRoutesAndViewsForBrowserFacingFeatures(): void
    {
        $reference = ApiReference::forPrompt();

        self::assertStringContainsString('GET route per page', $reference);
        self::assertStringContainsString('rendered view', $reference);
        self::assertStringContainsString('Do not generate a JSON-only API', $reference);
    }

    public function testItRequiresEveryReferencedViewToBeGenerated(): void
    {
        self::assertStringContainsString(
            'a matching resources/views/x/y.php file',
            ApiReference::forPrompt()
        );
    }

    public function testItCarriesTheRouterSurface(): void
    {
        $reference = ApiReference::forPrompt();

        self::assertStringContainsString('$router->get(', $reference);
        self::assertStringContainsString('$router->post(', $reference);
        self::assertStringContainsString("['auth']", $reference);
    }

    public function testItCarriesTheRequestResponseAndSessionSurface(): void
    {
        $reference = ApiReference::forPrompt();

        self::assertStringContainsString('$request->validate(', $reference);
        self::assertStringContainsString('Response::redirect(', $reference);
        self::assertStringContainsString('Session::token()', $reference);
    }

    public function testItCarriesTheModelAndQueryBuilderSurface(): void
    {
        $reference = ApiReference::forPrompt();

        self::assertStringContainsString('Vigen\Database\Model', $reference);
        self::assertStringContainsString('$fillable', $reference);
        self::assertStringContainsString('->orderBy(', $reference);
    }

    public function testItCarriesTheAuthSurface(): void
    {
        $reference = ApiReference::forPrompt();

        self::assertStringContainsString('Auth::attempt(', $reference);
        self::assertStringContainsString('Hash::make(', $reference);
        self::assertStringContainsString('Auth::login(', $reference);
    }

    /**
     * A generated migration has to be runnable, so the reference must show
     * the real file shape rather than describing it.
     */
    public function testItShowsTheMigrationFileShape(): void
    {
        $reference = ApiReference::forPrompt();

        self::assertStringContainsString("'up' => function", $reference);
        self::assertStringContainsString("'down' => function", $reference);
        self::assertStringContainsString('SQLite', $reference);
    }

    /**
     * A model without its migration is an app with no tables: `vigen migrate`
     * has nothing to run, and the first query the user makes dies with "no
     * such table". Unlike a controller's view reference, the model does not
     * mention the migration anywhere, so no "every referenced file must be
     * included" rule can catch it - the pairing has to be stated outright.
     */
    public function testItRequiresAMigrationForEveryModel(): void
    {
        $reference = ApiReference::forPrompt();

        self::assertStringContainsString('EVERY MODEL NEEDS A TABLE', $reference);
        self::assertStringContainsString('create_<table>_table.php', $reference);
        self::assertStringContainsString('ALTER TABLE', $reference);
    }

    /**
     * A POST that omits the CSRF field is rejected with a 419, so a generated
     * form without it is broken on first submit.
     */
    public function testItShowsTheCsrfFieldFormsMustCarry(): void
    {
        self::assertStringContainsString('name="_token"', ApiReference::forPrompt());
    }

    public function testTheConventionsAreIncludedWhenSupplied(): void
    {
        $reference = ApiReference::forPrompt(['models' => 'src/Domain/Models']);

        self::assertStringContainsString('WHERE FILES GO', $reference);
        self::assertStringContainsString('src/Domain/Models', $reference);
    }

    public function testTheConventionsSectionIsOmittedWhenThereAreNone(): void
    {
        self::assertStringNotContainsString('WHERE FILES GO', ApiReference::forPrompt());
    }

    public function testTheDefaultsDescribeWhereGeneratedFilesGo(): void
    {
        $reference = ApiReference::forPrompt(Conventions::DEFAULTS);

        self::assertStringContainsString('app/Http/Controllers', $reference);
        self::assertStringContainsString('resources/views', $reference);
        self::assertStringContainsString('database/migrations', $reference);
    }

    /**
     * The reference is prepended to every planner call, so it is re-sent on
     * every prompt. Anything that bloats it costs tokens on each request.
     */
    public function testItStaysWithinAReasonableSizeForALocalModel(): void
    {
        $length = strlen(ApiReference::forPrompt(Conventions::DEFAULTS));

        self::assertGreaterThan(3000, $length, 'The reference is too thin to pin down the API.');
        self::assertLessThan(12000, $length, 'The reference is too large to send with every prompt.');
    }
}
