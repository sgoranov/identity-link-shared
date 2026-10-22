<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sgoranov\IdentityLinkShared\Validator\PasswordStrength;
use sgoranov\IdentityLinkShared\Validator\PasswordStrengthValidator;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PasswordStrengthTest extends TestCase
{
    public function testItUsesDefaultOptions(): void
    {
        $constraint = new PasswordStrength();

        self::assertSame('The password is too weak. {{ feedback }}', $constraint->message);
        self::assertSame([Constraint::DEFAULT_GROUP], $constraint->groups);
        self::assertSame(PasswordStrengthValidator::class, $constraint->validatedBy());
    }

    #[DataProvider('weakPasswordProvider')]
    public function testItRejectsWeakPasswords(string $password): void
    {
        $violations = $this->createValidator()->validate($password, new PasswordStrength());

        self::assertCount(1, $violations);
        self::assertStringStartsWith('The password is too weak. ', $violations[0]->getMessage());
    }

    public static function weakPasswordProvider(): iterable
    {
        yield 'common password (score 0)' => ['password'];
        yield 'predictable substitutions (score 2)' => ['Tr0ub4dour&3'];
    }

    public function testItIncludesSuggestionsInTheMessage(): void
    {
        $violations = $this->createValidator()->validate('password', new PasswordStrength());

        self::assertCount(1, $violations);
        self::assertSame(
            'The password is too weak. Add another word or two. Uncommon words are better.',
            $violations[0]->getMessage(),
        );
    }

    #[DataProvider('strongPasswordProvider')]
    public function testItAcceptsStrongPasswords(string $password): void
    {
        $violations = $this->createValidator()->validate($password, new PasswordStrength());

        self::assertCount(0, $violations);
    }

    public static function strongPasswordProvider(): iterable
    {
        yield 'passphrase' => ['correct horse battery staple'];
        yield 'random characters' => ['vR7#qLm2!xPz9@wK'];
    }

    #[DataProvider('emptyValueProvider')]
    public function testItIgnoresEmptyValues(?string $value): void
    {
        $violations = $this->createValidator()->validate($value, new PasswordStrength());

        self::assertCount(0, $violations);
    }

    public static function emptyValueProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    public function testItSkipsValidationWhenDisabled(): void
    {
        $violations = $this->createValidator(['password_validation_enabled' => false])
            ->validate('password', new PasswordStrength());

        self::assertCount(0, $violations);
    }

    public function testItRequiresTheConfigurationParameter(): void
    {
        $this->expectException(ParameterNotFoundException::class);

        $this->createValidator([])->validate('password', new PasswordStrength());
    }

    public function testItRejectsOtherConstraints(): void
    {
        $validator = new PasswordStrengthValidator(new ParameterBag(['password_validation_enabled' => true]));

        $this->expectException(UnexpectedTypeException::class);

        $validator->validate('password', new NotBlank());
    }

    private function createValidator(array $parameters = ['password_validation_enabled' => true]): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                PasswordStrengthValidator::class => new PasswordStrengthValidator(new ParameterBag($parameters)),
            ]))
            ->getValidator();
    }
}
