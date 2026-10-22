<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\Monolog;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use sgoranov\IdentityLinkShared\Monolog\RequestIdProcessor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestIdProcessorTest extends TestCase
{
    private RequestStack $requestStack;
    private TestHandler $handler;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
        $this->handler = new TestHandler();
        $this->logger = new Logger('app', [$this->handler], [new RequestIdProcessor($this->requestStack)]);
    }

    public function testItAddsTheRequestIdToEveryRecord(): void
    {
        $this->requestStack->push($this->createRequest('3f2c9a'));

        $this->logger->info('First line');
        $this->logger->error('Second line');

        foreach ($this->handler->getRecords() as $record) {
            self::assertSame('3f2c9a', $record->extra['request_id']);
        }
        self::assertCount(2, $this->handler->getRecords());
    }

    public function testItUsesTheMainRequestDuringSubRequests(): void
    {
        $this->requestStack->push($this->createRequest('main-id'));
        $this->requestStack->push($this->createRequest('sub-id'));

        $this->logger->info('Rendering a fragment');

        self::assertSame('main-id', $this->handler->getRecords()[0]->extra['request_id']);
    }

    public function testItAddsNothingWithoutARequest(): void
    {
        $this->logger->info('Console command');

        self::assertSame([], $this->handler->getRecords()[0]->extra);
    }

    public function testItAddsNothingWithoutTheHeader(): void
    {
        $this->requestStack->push(Request::create('/api/users'));

        $this->logger->info('Request without an ID');

        self::assertSame([], $this->handler->getRecords()[0]->extra);
    }

    public function testItKeepsOtherExtraFields(): void
    {
        $this->requestStack->push($this->createRequest('3f2c9a'));
        $this->logger->pushProcessor(function ($record) {
            $record->extra['hostname'] = 'web-1';

            return $record;
        });

        $this->logger->info('Line');

        self::assertSame(['hostname' => 'web-1', 'request_id' => '3f2c9a'], $this->handler->getRecords()[0]->extra);
    }

    public function testItIsRegisteredAsAMonologProcessor(): void
    {
        self::assertCount(1, (new \ReflectionClass(RequestIdProcessor::class))->getAttributes(AsMonologProcessor::class));
    }

    private function createRequest(string $requestId): Request
    {
        return Request::create('/api/users', server: ['HTTP_X_REQUEST_ID' => $requestId]);
    }
}
