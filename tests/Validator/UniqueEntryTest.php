<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\Validator;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use sgoranov\IdentityLinkShared\Tests\Validator\Fixtures\Account;
use sgoranov\IdentityLinkShared\Validator\UniqueEntry;
use sgoranov\IdentityLinkShared\Validator\UniqueEntryValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UniqueEntryTest extends TestCase
{
    private EntityRepository&MockObject $repository;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Account::class)->willReturn($this->repository);

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                UniqueEntryValidator::class => new UniqueEntryValidator($entityManager),
            ]))
            ->getValidator();
    }

    public function testItUsesDefaultOptions(): void
    {
        $constraint = new UniqueEntry();

        self::assertSame('The value "{{ value }}" already exists.', $constraint->message);
        self::assertSame([Constraint::DEFAULT_GROUP], $constraint->groups);
        self::assertSame(UniqueEntryValidator::class, $constraint->validatedBy());
    }

    public function testItAcceptsValueThatDoesNotExist(): void
    {
        $this->repository->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'new@example.com'])
            ->willReturn(null);

        $violations = $this->validator->validate(new Account('account-1', 'new@example.com'));

        self::assertCount(0, $violations);
    }

    public function testItAllowsEntityToKeepItsOwnValue(): void
    {
        $this->repository->method('findOneBy')->willReturn(new Account('account-1', 'taken@example.com'));

        $violations = $this->validator->validate(new Account('account-1', 'taken@example.com'));

        self::assertCount(0, $violations);
    }

    #[DataProvider('otherEntityProvider')]
    public function testItRejectsValueOwnedByAnotherEntity(?string $id): void
    {
        $this->repository->method('findOneBy')->willReturn(new Account('account-2', 'taken@example.com'));

        $violations = $this->validator->validate(new Account($id, 'taken@example.com'));

        self::assertCount(1, $violations);
        self::assertSame('email', $violations[0]->getPropertyPath());
        self::assertSame('The value "taken@example.com" already exists.', $violations[0]->getMessage());
    }

    public static function otherEntityProvider(): iterable
    {
        yield 'existing entity' => ['account-1'];
        yield 'new entity without an ID' => [null];
    }
}
