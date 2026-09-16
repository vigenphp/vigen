<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vigen\Http\ValidationException;
use Vigen\Http\Validator;

final class ValidatorTest extends TestCase
{
    public function testItReturnsOnlyTheValidatedFields(): void
    {
        $validated = Validator::validate(
            ['email' => 'a@b.com', 'name' => 'Ada', 'admin' => '1'],
            ['email' => 'required|email']
        );

        self::assertSame(['email' => 'a@b.com'], $validated);
    }

    public function testMissingRequiredFieldFails(): void
    {
        try {
            Validator::validate([], ['email' => 'required|email']);
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
            self::assertSame('The email field is required.', $e->firstErrors()['email']);

            return;
        }

        self::fail('Expected a ValidationException.');
    }

    public function testBlankStringCountsAsMissingForARequiredField(): void
    {
        $this->expectException(ValidationException::class);

        Validator::validate(['name' => '   '], ['name' => 'required|string']);
    }

    /**
     * An optional field that was submitted blank is skipped rather than
     * failing every remaining rule.
     */
    public function testBlankOptionalFieldIsSkippedNotFailed(): void
    {
        $validated = Validator::validate(['nickname' => ''], ['nickname' => 'string|min:3']);

        self::assertSame([], $validated);
    }

    public function testNullableAcceptsNull(): void
    {
        $validated = Validator::validate(['bio' => null], ['bio' => 'nullable|string|max:10']);

        self::assertSame([], $validated);
    }

    public function testSometimesSkipsAnAbsentFieldEntirely(): void
    {
        $validated = Validator::validate([], ['password' => 'sometimes|required|min:8']);

        self::assertSame([], $validated);
    }

    public function testSometimesStillValidatesAPresentField(): void
    {
        $this->expectException(ValidationException::class);

        Validator::validate(['password' => 'short'], ['password' => 'sometimes|min:8']);
    }

    /**
     * Every failing rule on a field is reported, not just the first, so a form
     * can show the user everything that is wrong in one pass.
     */
    public function testAllFailuresOnAFieldAreCollected(): void
    {
        try {
            Validator::validate(['email' => 'not-an-email'], ['email' => 'email|min:50']);
        } catch (ValidationException $e) {
            self::assertCount(2, $e->errors()['email']);

            return;
        }

        self::fail('Expected a ValidationException.');
    }

    public function testSeveralFieldsCanFailTogether(): void
    {
        try {
            Validator::validate(['email' => 'x', 'age' => 'abc'], [
                'email' => 'required|email',
                'age' => 'required|integer',
            ]);
        } catch (ValidationException $e) {
            self::assertSame(['email', 'age'], array_keys($e->errors()));

            return;
        }

        self::fail('Expected a ValidationException.');
    }

    #[DataProvider('ruleProvider')]
    public function testRulePasses(string $rule, mixed $value, bool $valid): void
    {
        $data = ['field' => $value];

        if ($rule === 'confirmed') {
            $data['field_confirmation'] = $valid ? $value : 'different';
        }

        try {
            Validator::validate($data, ['field' => 'required|' . $rule]);
            $passed = true;
        } catch (ValidationException) {
            $passed = false;
        }

        self::assertSame($valid, $passed, "Rule [{$rule}] on " . var_export($value, true));
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: bool}>
     */
    public static function ruleProvider(): array
    {
        return [
            'email valid' => ['email', 'a@b.com', true],
            'email invalid' => ['email', 'nope', false],
            'url valid' => ['url', 'https://vigen.test', true],
            'url invalid' => ['url', 'not a url', false],
            'numeric valid' => ['numeric', '12.5', true],
            'numeric invalid' => ['numeric', 'twelve', false],
            'integer valid' => ['integer', '42', true],
            'integer invalid' => ['integer', '1.5', false],
            'boolean valid' => ['boolean', '1', true],
            'boolean invalid' => ['boolean', 'yes', false],
            'date valid' => ['date', '2026-09-16', true],
            'date invalid' => ['date', 'not a date', false],
            'alpha_dash valid' => ['alpha_dash', 'user-name_1', true],
            'alpha_dash invalid' => ['alpha_dash', 'user name', false],
            'min length passes' => ['min:8', 'longenough', true],
            'min length fails' => ['min:8', 'short', false],
            'max length passes' => ['max:5', 'abc', true],
            'max length fails' => ['max:5', 'abcdef', false],
            'size length exact' => ['size:3', 'abc', true],
            'size length wrong' => ['size:3', 'abcd', false],
            'min numeric magnitude' => ['min:18', '21', true],
            'min numeric too small' => ['min:18', '16', false],
            'confirmed matching' => ['confirmed', 'secret', true],
            'confirmed mismatched' => ['confirmed', 'secret', false],
            'in allowed' => ['in:draft,published', 'draft', true],
            'in rejected' => ['in:draft,published', 'deleted', false],
            'not_in allowed' => ['not_in:admin', 'editor', true],
            'not_in rejected' => ['not_in:admin', 'admin', false],
        ];
    }

    public function testUnknownRuleIsReportedWithTheSupportedList(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown validation rule \[emial\]/');

        Validator::validate(['email' => 'a@b.com'], ['email' => 'emial']);
    }

    /**
     * A typo in a rule is a programming error, so it must raise rather than
     * silently pass and let bad data through.
     */
    public function testUnknownRuleIsNotTreatedAsAPassingRule(): void
    {
        $this->expectException(RuntimeException::class);

        Validator::validate(['x' => 'v'], ['x' => 'required|nonsense']);
    }

    public function testRulesMayBeGivenAsAnArray(): void
    {
        $validated = Validator::validate(
            ['email' => 'a@b.com'],
            ['email' => ['required', 'email']]
        );

        self::assertSame(['email' => 'a@b.com'], $validated);
    }

    public function testTheExceptionCarriesADefaultMessage(): void
    {
        try {
            Validator::validate([], ['email' => 'required']);
        } catch (ValidationException $e) {
            self::assertSame('The given data was invalid.', $e->getMessage());

            return;
        }

        self::fail('Expected a ValidationException.');
    }
}
