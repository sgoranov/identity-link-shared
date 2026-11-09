<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\Controller;

use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sgoranov\IdentityLinkShared\Api\DTO\AbstractQueryRequest;
use sgoranov\IdentityLinkShared\Controller\QueryController;
use sgoranov\IdentityLinkShared\Serializer\Deserializer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

final class QueryControllerTest extends TestCase
{
    private const SORT_DIRECTION_DEPRECATION = 'https://github.com/doctrine/orm/issues/11313';

    private ?string $dql = null;

    public function testItOrdersBySortDirectionInAnyCase(): void
    {
        Deprecation::enableTrackingDeprecations();
        $deprecations = Deprecation::getTriggeredDeprecations()[self::SORT_DIRECTION_DEPRECATION] ?? 0;

        $this->query(['t.name' => 'DESC', 't.email' => 'asc', 't.id' => null]);

        self::assertSame('SELECT t FROM Account t ORDER BY t.name DESC, t.email ASC, t.id ASC', $this->dql);
        self::assertSame($deprecations, Deprecation::getTriggeredDeprecations()[self::SORT_DIRECTION_DEPRECATION] ?? 0);
    }

    #[DataProvider('invalidDirectionProvider')]
    public function testItRejectsInvalidSortDirection(mixed $order): void
    {
        $response = $this->query(['t.name' => $order]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('{"error":"Invalid sort direction."}', $response->getContent());
        self::assertNull($this->dql);
    }

    public static function invalidDirectionProvider(): iterable
    {
        yield 'unknown word' => ['up'];
        yield 'number' => [1];
        yield 'list' => [['desc']];
    }

    private function query(array $orderBy): Response
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn(new QueryBuilder($entityManager));

        // Query is final and cannot be mocked, so the test records the DQL and stops there
        $entityManager->method('createQuery')->willReturnCallback(function (string $dql) {
            $this->dql = $dql;

            throw new \RuntimeException('The query is not run in tests.');
        });

        $deserializer = $this->createMock(Deserializer::class);
        $deserializer->method('deserialize')->willReturn(true);

        $request = new class extends AbstractQueryRequest
        {
            public function getType(): string
            {
                return 'Account';
            }

            public function setType(string $type): void
            {
            }
        };
        $request->setOrderBy($orderBy);

        $controller = new QueryController($this->createMock(SerializerInterface::class), $deserializer, $entityManager);

        return $controller->query($request);
    }
}
