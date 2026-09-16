<?php

declare(strict_types=1);

namespace Vigen\Project;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Throwable;
use Vigen\AI\ValidationResult;

/**
 * Checks that generated PHP targets Vigen's API rather than another
 * framework's.
 *
 * SyntaxValidator cannot catch this: Laravel-flavoured code is perfectly valid
 * PHP, so `php -l` passes and the engine reports success on a controller that
 * would fatal the moment a request reached it. This class is what makes the
 * self-correction loop fire on that output.
 *
 * It reads the file's *references* - use statements, instantiations, static
 * calls, type hints, parent classes - and reports any that name something
 * Vigen does not provide.
 */
final class CodeValidator
{
    /**
     * Namespaces that belong to another framework.
     */
    private const FOREIGN_NAMESPACES = [
        'Illuminate\\',
        'Laravel\\',
        'Symfony\\',
        'Doctrine\\',
        'Carbon\\',
    ];

    /**
     * What to say about each foreign namespace. The specific noun matters: a
     * model told "Eloquent does not exist" fixes it, where "remove this
     * import" leaves it guessing what to use instead.
     */
    private const NAMESPACE_HINTS = [
        'Illuminate\\' => "Illuminate is Laravel's namespace. Its facades, Eloquent and Blade do not exist in Vigen.",
        'Laravel\\' => "Laravel's own classes do not exist in Vigen. Use the App\\ and Vigen\\ classes instead.",
        'Symfony\\' => 'Symfony components are not part of the API Vigen generates against.',
        'Doctrine\\' => "Vigen's database layer is Vigen\\Database\\Model and Vigen\\Database\\Connection, not Doctrine.",
        'Carbon\\' => 'Use PHP\'s own date() / DateTimeImmutable instead of Carbon.',
    ];

    /**
     * Bare class names a model reaches for out of Laravel habit, which resolve
     * to nothing here. Checked only against the file's own namespace and
     * against imports, so a project's own `App\Support\Route` is not flagged.
     */
    private const FOREIGN_CLASSES = [
        'Route' => 'Vigen has no Route facade. Routes are registered on the '
            . '$router variable in routes/web.php.',
        'Hash' => 'Vigen has no Hash facade. Use Vigen\\Auth\\Hash::make() / '
            . 'Hash::check().',
        'Auth' => 'Vigen has no Auth facade. Use Vigen\\Auth\\Auth::attempt() / '
            . 'user() / check().',
        'DB' => 'Vigen has no DB facade. Use Vigen\\Database\\Connection or a '
            . 'model extending Vigen\\Database\\Model.',
        'Schema' => 'Vigen has no schema builder. Write SQL in a migration file '
            . 'and run `vigen migrate`.',
        'Blade' => 'Vigen has no Blade. Views are plain PHP files in '
            . 'resources/views.',
        'Validator' => 'Vigen has no Validator facade. Use '
            . '$request->validate([...]).',
        'Controller' => 'Vigen controllers extend nothing. Remove the base class.',
        'Model' => 'Eloquent does not exist here. Extend '
            . 'Vigen\\Database\\Model.',
        'Migration' => 'Vigen migrations are plain PHP files returning an array '
            . 'of closures - there is no Migration base class.',
    ];

    /**
     * Global functions Laravel defines that Vigen does not.
     */
    private const FOREIGN_FUNCTIONS = [
        'response' => 'Return Vigen\\Http\\Response::json() / html() / redirect() instead.',
        'redirect' => 'Return Vigen\\Http\\Response::redirect($path) instead.',
        'view' => 'Return Vigen\\View\\View::render($name, $data) instead.',
        'abort' => 'Throw an exception, or return a Response with the status you want.',
        'route' => 'Vigen has no named routes. Build the path yourself.',
        'asset' => 'Vigen has no asset() helper. Use a path relative to public/.',
        'csrf_field' => 'Write the hidden field by hand: '
            . '<input type="hidden" name="_token" value="<?= e(Vigen\\Http\\Session::token()) ?>">',
        'old' => 'Use View::old($field, $default) in a template.',
        'back' => 'Return Vigen\\Http\\Response::redirect($path) with an explicit path.',
        'bcrypt' => 'Use Vigen\\Auth\\Hash::make($password).',
        'dd' => 'Use var_dump() or write to storage/logs/vigen.log.',
    ];

    private const VIGEN_FUNCTION_ALLOWLIST = ['config', 'env'];

    /**
     * @param array<string, string> $contentsByPath project-relative path => body
     * @return list<string> error messages, empty when everything is on-API
     */
    public function validateContents(array $contentsByPath): array
    {
        $errors = [];

        foreach ($contentsByPath as $path => $contents) {
            if (! str_ends_with(strtolower($path), '.php')) {
                continue;
            }

            foreach ($this->inspect($contents, $path) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $contentsByPath project-relative path => body
     */
    public function validate(array $contentsByPath): ValidationResult
    {
        $errors = $this->validateContents($contentsByPath);

        return $errors === [] ? ValidationResult::ok() : ValidationResult::failed($errors);
    }

    /**
     * @return list<string>
     */
    private function inspect(string $code, string $label): array
    {
        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        } catch (Throwable) {
            // A parse failure is SyntaxValidator's department - reporting it
            // here too would double up the fix prompt.
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, string> import alias => fully qualified name */
            public array $imports = [];

            /** @var array<string, int> import alias => line */
            public array $importLines = [];

            /** @var list<array{name: string, line: int}> */
            public array $references = [];

            /** @var list<array{name: string, line: int}> */
            public array $calls = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $name = $use->name->toString();
                        $alias = $use->alias?->toString() ?? substr($name, (int) strrpos($name, '\\') + 1);
                        $this->imports[$alias] = $name;
                        $this->importLines[$alias] = $use->getStartLine();
                    }

                    return null;
                }

                if ($node instanceof Node\Stmt\GroupUse) {
                    foreach ($node->uses as $use) {
                        $name = $node->prefix->toString() . '\\' . $use->name->toString();
                        $alias = $use->alias?->toString() ?? $use->name->toString();
                        $this->imports[$alias] = $name;
                        $this->importLines[$alias] = $use->getStartLine();
                    }

                    return null;
                }

                // new Foo(), Foo::bar(), Foo $param, extends Foo
                if ($node instanceof Node\Name) {
                    $this->references[] = ['name' => $node->toString(), 'line' => $node->getStartLine()];
                }

                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                    $this->calls[] = ['name' => $node->name->toString(), 'line' => $node->getStartLine()];
                }

                if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
                    $this->references[] = ['name' => $node->class->toString(), 'line' => $node->getStartLine()];
                }

                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->class instanceof Node\Name
                    && $node->name instanceof Node\Identifier
                    && strtolower($node->name->toString()) === 'class'
                ) {
                    $this->references[] = ['name' => $node->class->toString(), 'line' => $node->getStartLine()];
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse($ast);

        $errors = [];

        // Foreign imports, which are unambiguous - the full namespace is right
        // there in the use statement.
        foreach ($visitor->imports as $alias => $name) {
            $namespace = $this->foreignNamespaceOf($name);

            if ($namespace === null) {
                continue;
            }

            $errors[] = sprintf(
                '%s:%d imports [%s], which is not part of Vigen. %s',
                $label,
                $visitor->importLines[$alias] ?? 0,
                $name,
                self::NAMESPACE_HINTS[$namespace]
            );
        }

        // An import of a foreign name makes every use of it foreign too, and it
        // is already reported above.
        foreach ($visitor->references as $reference) {
            $resolved = $this->resolve($reference['name'], $visitor->imports);

            if ($this->isForeignNamespace($resolved)) {
                continue;
            }

            $bare = $this->bareName($reference['name']);

            if (isset($visitor->imports[$bare])) {
                continue;
            }

            if (isset(self::FOREIGN_CLASSES[$bare])) {
                $errors[] = sprintf(
                    '%s:%d uses [%s]. %s',
                    $label,
                    $reference['line'],
                    $reference['name'],
                    self::FOREIGN_CLASSES[$bare]
                );
            }
        }

        foreach ($visitor->calls as $call) {
            $name = $call['name'];

            if (in_array($name, self::VIGEN_FUNCTION_ALLOWLIST, true)) {
                continue;
            }

            $bare = $this->bareName($name);

            if (! array_key_exists($bare, self::FOREIGN_FUNCTIONS)) {
                continue;
            }

            $hint = self::FOREIGN_FUNCTIONS[$bare];

            if ($hint === null) {
                continue;
            }

            // Only flag a call that is not namespaced and not imported - a
            // project's own helper() or a defined function is legitimate.
            if (str_contains($name, '\\') || isset($visitor->imports[$bare]) || function_exists($bare)) {
                continue;
            }

            $errors[] = sprintf(
                '%s:%d calls %s(), which does not exist in Vigen. %s',
                $label,
                $call['line'],
                $bare,
                $hint
            );
        }

        return array_values(array_unique($errors));
    }

    private function isForeignNamespace(string $name): bool
    {
        return $this->foreignNamespaceOf($name) !== null;
    }

    /**
     * The matching foreign namespace prefix, or null when the name is local.
     */
    private function foreignNamespaceOf(string $name): ?string
    {
        foreach (self::FOREIGN_NAMESPACES as $namespace) {
            if (str_starts_with($name, $namespace)) {
                return $namespace;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $imports
     */
    private function resolve(string $name, array $imports): string
    {
        $bare = $this->bareName($name);

        return $imports[$bare] ?? $name;
    }

    /**
     * The first segment of a name: "Illuminate\Support\Route" -> "Illuminate",
     * "Route::get" -> "Route".
     */
    private function bareName(string $name): string
    {
        $name = ltrim($name, '\\');

        return str_contains($name, '\\') ? substr($name, 0, (int) strpos($name, '\\')) : $name;
    }
}
