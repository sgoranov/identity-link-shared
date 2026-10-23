<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Controller;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ParameterType;
use sgoranov\IdentityLinkShared\Api\DTO\AbstractQueryRequest;
use sgoranov\IdentityLinkShared\Serializer\Deserializer;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

class QueryController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly Deserializer $deserializer,
        private readonly EntityManagerInterface $entityManager,
    )
    {
    }

    public function query(AbstractQueryRequest $request): Response
    {
        if (!$this->deserializer->deserialize($request)) {
            return $this->deserializer->respondWithError();
        }

        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder->select($request->getAlias())
            ->from($request->getType(), $request->getAlias())
            ->setMaxResults($request->getLimit() + 1)
            ->setFirstResult($request->getOffset())
        ;

        if ($request->getQuery() !== null) {
            $queryBuilder->where($request->getQuery());
        }

        $params = $request->getParameters();
        if ($params !== null) {
            foreach ($params as $name => $value) {
                $queryBuilder->setParameter($name, $value, ParameterType::STRING);
            }
        }

        if ($request->getJoins() !== null) {
            foreach ($request->getJoins() as $alias => $join) {
                $queryBuilder->join($join, $alias);
            }
        }

        try {
            foreach ($request->getOrderBy() ?? [] as $sort => $order) {
                $queryBuilder->addOrderBy($sort, $this->getSortDirection($order));
            }

            $result = $queryBuilder->getQuery()->getResult();
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }

        $hasMore = false;
        if (count($result) === $request->getLimit() + 1) {
            $hasMore = true;
        }

        $response = [];
        $result = array_slice($result, 0, $request->getLimit());
        foreach ($result as $item) {
            $response[] = json_decode($this->serializer->serialize($item, 'json'));
        }

        return new JsonResponse([
            'response' => [
                'result' => $response,
                'hasMore' => $hasMore,
            ]
        ]);
    }

    private function getSortDirection(mixed $order): SortDirection
    {
        return match (is_string($order) ? strtolower($order) : $order) {
            'asc', null => SortDirection::Ascending,
            'desc' => SortDirection::Descending,
            default => throw new \InvalidArgumentException('Invalid sort direction.'),
        };
    }
}