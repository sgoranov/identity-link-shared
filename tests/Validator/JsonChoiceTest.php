<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sgoranov\IdentityLinkShared\Validator\JsonChoice;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\Loader\AbstractLoader;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class JsonChoiceTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testItUsesDefaultOptions(): void
    {
        $constraint = new JsonChoice(choices: ['read', 'write']);

        self::assertSame(['read', 'write'], $constraint->choices);
        self::assertSame('The value "{{ invalidChoices }}" is not a valid choice.', $constraint->message);
        self::assertSame([Constraint::DEFAULT_GROUP], $constraint->groups);
        self::assertNull($constraint->payload);
    }

    public function testItAcceptsNamedArguments(): void
    {
        $constraint = new JsonChoice(
            choices: ['read'],
            message: 'Invalid scopes: {{ invalidChoices }}',
            groups: ['strict'],
            payload: 'severity',
        );

        self::assertSame(['read'], $constraint->choices);
        self::assertSame('Invalid scopes: {{ invalidChoices }}', $constraint->message);
        self::assertSame(['strict'], $constraint->groups);
        self::assertSame('severity', $constraint->payload);
    }

    public function testItCanBeConfiguredFromMappingFiles(): void
    {
        // YAML and XML mapping build constraints through AbstractLoader::newConstraint()
        $loader = new class extends AbstractLoader {
            public function loadClassMetadata(ClassMetadata $metadata): bool
            {
                return false;
            }

            public function create(array $options): Constraint
            {
                return $this->newConstraint(JsonChoice::class, $options);
            }
        };

        $constraint = $loader->create([
            'choices' => ['read', 'write'],
            'message' => 'Invalid scopes: {{ invalidChoices }}',
        ]);

        self::assertInstanceOf(JsonChoice::class, $constraint);
        self::assertSame(['read', 'write'], $constraint->choices);
        self::assertSame('Invalid scopes: {{ invalidChoices }}', $constraint->message);
    }

    #[DataProvider('validValueProvider')]
    public function testItAcceptsValidValues(mixed $value): void
    {
        $violations = $this->validator->validate($value, new JsonChoice(choices: ['read', 'write']));

        self::assertCount(0, $violations);
    }

    public static function validValueProvider(): iterable
    {
        yield 'all choices' => [['read', 'write']];
        yield 'subset of choices' => [['write']];
        yield 'empty array' => [[]];
        yield 'null is ignored' => [null];
        yield 'string is ignored' => ['delete'];
    }

    public function testItReportsInvalidChoices(): void
    {
        $violations = $this->validator->validate(
            ['read', 'delete', 'admin'],
            new JsonChoice(choices: ['read', 'write']),
        );

        self::assertCount(1, $violations);
        self::assertSame('The value "delete, admin" is not a valid choice.', $violations[0]->getMessage());
    }

    public function testItUsesCustomMessage(): void
    {
        $violations = $this->validator->validate(
            ['delete'],
            new JsonChoice(choices: ['read'], message: 'Invalid scopes: {{ invalidChoices }}'),
        );

        self::assertCount(1, $violations);
        self::assertSame('Invalid scopes: delete', $violations[0]->getMessage());
    }

    public function testItValidatesAttributeMappedProperties(): void
    {
        $subject = new class {
            #[JsonChoice(choices: ['read', 'write'])]
            public array $scopes = ['read', 'delete'];

            #[JsonChoice(choices: ['admin'], groups: ['strict'])]
            public array $roles = ['guest'];
        };

        $violations = $this->validator->validate($subject);

        self::assertCount(1, $violations);
        self::assertSame('scopes', $violations[0]->getPropertyPath());

        $violations = $this->validator->validate($subject, null, ['strict']);

        self::assertCount(1, $violations);
        self::assertSame('roles', $violations[0]->getPropertyPath());
    }
}
