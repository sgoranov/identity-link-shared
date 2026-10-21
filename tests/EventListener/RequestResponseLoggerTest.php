<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\EventListener;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use sgoranov\IdentityLinkShared\EventListener\RequestResponseLogger;
use sgoranov\IdentityLinkShared\Tests\EventListener\Fixtures\InMemoryLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestResponseLoggerTest extends TestCase
{
    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new InMemoryLogger();
    }

    public function testItMasksSensitiveJsonFields(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'alice',
            'password' => 'hunter2',
            'profile' => ['newPassword' => 'hunter3', 'displayName' => 'Alice'],
            'devices' => [['name' => 'phone', 'refresh_token' => 'rt-123']],
        ]);

        $context = $this->handle(new RequestResponseLogger($this->logger), $request, new Response());

        self::assertSame(
            '{"username":"alice","password":"*** MASKED ***","profile":{"newPassword":"*** MASKED ***","displayName":"Alice"},'
            .'"devices":[{"name":"phone","refresh_token":"*** MASKED ***"}]}',
            $context['body'],
        );
        self::assertSame(strlen($request->getContent()), $context['body_length']);

        $stored = json_encode($request->attributes->all());
        self::assertStringNotContainsString('hunter2', $stored);
        self::assertStringNotContainsString('rt-123', $stored);
    }

    public function testItIgnoresTheContentType(): void
    {
        // JSON sent with curl -d, which sets a form Content-Type
        $request = Request::create('/api/users', 'POST', server: [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], content: '{"username":"alice","password":"p@ss=w0rd&x"}');

        $context = $this->handle(new RequestResponseLogger($this->logger), $request, new Response());

        self::assertSame('{"username":"alice","password":"*** MASKED ***"}', $context['body']);
    }

    #[DataProvider('fieldNameProvider')]
    public function testItMatchesSensitiveFieldNames(string $field, bool $masked): void
    {
        $request = $this->createJsonRequest([$field => 'value']);

        $context = $this->handle(new RequestResponseLogger($this->logger), $request, new Response());

        self::assertSame(json_encode([$field => $masked ? '*** MASKED ***' : 'value']), $context['body']);
    }

    public static function fieldNameProvider(): iterable
    {
        foreach (['password', 'plainPassword', 'new_password', 'PASSWD', 'secret', 'client_secret', 'token', '_token',
            'csrf_token', 'access_token', 'refresh_token', 'id_token', 'id_token_hint', 'refreshToken', 'tokens',
            'credentials', 'api_key', 'apiKey', 'code', 'code_verifier', 'otp', 'pin'] as $field) {
            yield $field => [$field, true];
        }

        foreach (['username', 'email', 'countryCode', 'errorCode', 'postcode', 'footprint', 'passport',
            'pinned', 'opinion', 'description', 'token_type', 'token_type_hint', 'token_endpoint',
            'id_token_signing_alg_values_supported'] as $field) {
            yield $field => [$field, false];
        }
    }

    public function testItMasksSensitiveResponseFields(): void
    {
        $response = new JsonResponse([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'eyJhbGciOiJSUzI1NiJ9.payload.signature',
            'refresh_token' => 'rt-123',
            'id_token' => 'eyJhbGciOiJSUzI1NiJ9.claims.signature',
            'scope' => 'openid email',
        ]);

        $context = $this->handle(new RequestResponseLogger($this->logger), Request::create('/oauth/token', 'POST'), $response);

        self::assertSame(
            '{"token_type":"Bearer","expires_in":3600,"access_token":"*** MASKED ***","refresh_token":"*** MASKED ***",'
            .'"id_token":"*** MASKED ***","scope":"openid email"}',
            $context['response_body'],
        );
        self::assertSame(strlen($response->getContent()), $context['response_length']);
    }

    public function testItMasksSensitiveHeaders(): void
    {
        $request = Request::create('/api/users', server: [
            'HTTP_AUTHORIZATION' => 'Bearer token',
            'HTTP_PROXY_AUTHORIZATION' => 'Basic credentials',
            'HTTP_COOKIE' => 'session=abc',
            'HTTP_X_API_KEY' => 'key',
            'HTTP_X_AUTH_TOKEN' => 'token',
            'HTTP_X_CSRF_TOKEN' => 'csrf',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $headers = $this->handle(new RequestResponseLogger($this->logger), $request, new Response())['headers'];

        foreach (['authorization', 'proxy-authorization', 'cookie', 'x-api-key', 'x-auth-token', 'x-csrf-token'] as $name) {
            self::assertSame(['*** MASKED ***'], $headers[$name], $name);
        }
        self::assertSame(['application/json'], $headers['accept']);
    }

    #[DataProvider('nonJsonBodyProvider')]
    public function testItDoesNotLogBodiesThatCannotBeMasked(string $contentType, string $content): void
    {
        $request = Request::create('/api/users', 'POST', server: ['CONTENT_TYPE' => $contentType], content: $content);

        $context = $this->handle(new RequestResponseLogger($this->logger), $request, new Response());

        self::assertSame('*** BODY CANNOT BE MASKED ***', $context['body']);
        self::assertSame(strlen($content), $context['body_length']);
    }

    public static function nonJsonBodyProvider(): iterable
    {
        yield 'form data' => ['application/x-www-form-urlencoded', 'grant_type=password&username=alice&password=hunter2'];
        yield 'multipart' => [
            'multipart/form-data; boundary=x',
            "--x\r\nContent-Disposition: form-data; name=\"password\"\r\n\r\nhunter2\r\n--x--\r\n",
        ];
        yield 'plain text' => ['text/plain', 'my password is hunter2'];
        yield 'binary' => ['application/octet-stream', "\x00\x01password"];
        yield 'invalid JSON' => ['application/json', '{"password": "hunter2"'];
        yield 'JSON string' => ['application/json', '"hunter2"'];
        yield 'JSON number' => ['application/json', '123456'];
        yield 'JSON that cannot be encoded back' => ['application/json', '{"amount":1e999,"password":"hunter2"}'];
    }

    public function testItDoesNotLogBodiesOverOneMegabyte(): void
    {
        $request = $this->createJsonRequest(['password' => 'hunter2', 'data' => str_repeat('a', 1_000_000)]);

        $context = $this->handle(new RequestResponseLogger($this->logger), $request, new Response());

        self::assertSame('*** BODY IS TOO LARGE ***', $context['body']);
        self::assertSame(strlen($request->getContent()), $context['body_length']);
    }

    public function testItTruncatesLongBodiesAfterMasking(): void
    {
        $request = $this->createJsonRequest(['password' => 'hunter2', 'data' => str_repeat('a', 3000)]);

        $context = $this->handle(new RequestResponseLogger($this->logger), $request, new Response());

        $expected = substr('{"password":"*** MASKED ***","data":"'.str_repeat('a', 3000), 0, 2000).'... (TRUNCATED)';
        self::assertSame($expected, $context['body']);
    }

    public function testItDoesNotSplitCharactersWhenTruncating(): void
    {
        $request = $this->createJsonRequest(['password' => 'hunter2', 'data' => str_repeat('я', 2000)]);

        $context = $this->handle(new RequestResponseLogger($this->logger), $request, new Response());

        // The prefix is 37 bytes and "я" is 2, so a cut at exactly 2000 bytes would split the last one
        self::assertSame('{"password":"*** MASKED ***","data":"'.str_repeat('я', 981).'... (TRUNCATED)', $context['body']);
    }

    public function testItLogsEmptyBodiesAsEmpty(): void
    {
        $context = $this->handle(new RequestResponseLogger($this->logger), Request::create('/api/users'), new Response());

        self::assertSame('', $context['body']);
        self::assertSame('', $context['response_body']);
    }

    public function testItCanDisableBodyLogging(): void
    {
        $request = $this->createJsonRequest(['username' => 'alice']);
        $response = new JsonResponse(['id' => 1]);

        $context = $this->handle(new RequestResponseLogger($this->logger, logBodies: false), $request, $response);

        self::assertSame('*** BODY LOGGING IS DISABLED ***', $context['body']);
        self::assertSame('*** BODY LOGGING IS DISABLED ***', $context['response_body']);
        self::assertSame(strlen($request->getContent()), $context['body_length']);
        self::assertSame(strlen($response->getContent()), $context['response_length']);
    }

    public function testItCanDisableHeaderLogging(): void
    {
        $request = Request::create('/api/users', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer token',
        ], content: '{"username":"alice"}');

        $context = $this->handle(new RequestResponseLogger($this->logger, logHeaders: false), $request, new Response());

        self::assertArrayNotHasKey('headers', $context);
        self::assertSame('POST', $context['method']);
        self::assertSame('/api/users', $context['path']);
        self::assertSame('{"username":"alice"}', $context['body']);
    }

    public function testItAcceptsCustomPattern(): void
    {
        $request = $this->createJsonRequest(['pwd' => 'hunter2', 'password' => 'hunter3']);

        $context = $this->handle(new RequestResponseLogger($this->logger, '/^pwd$/i'), $request, new Response());

        self::assertSame('{"pwd":"*** MASKED ***","password":"hunter3"}', $context['body']);
    }

    public function testItMasksFieldsWhenMatchingFails(): void
    {
        $field = str_repeat('a', 30).'b';
        $listener = new RequestResponseLogger($this->logger, '/^(a+)+$/');
        $request = $this->createJsonRequest([$field => 'hunter2']);

        // Force a backtracking error so preg_match() returns false
        $jit = ini_set('pcre.jit', '0');
        $backtrackLimit = ini_set('pcre.backtrack_limit', '100');

        try {
            $context = $this->handle($listener, $request, new Response());
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
        }

        self::assertSame(json_encode([$field => '*** MASKED ***']), $context['body']);
    }

    public function testItRejectsInvalidPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RequestResponseLogger($this->logger, '/unclosed(/');
    }

    public function testItMasksBodiesWhenLoggingExceptions(): void
    {
        $listener = new RequestResponseLogger($this->logger);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = $this->createJsonRequest(['password' => 'hunter2']);

        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new NotFoundHttpException('Not found')));

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);

        $context = $this->logger->records[0]['context'];
        self::assertSame(404, $context['status']);
        self::assertSame('{"password":"*** MASKED ***"}', $context['body']);
    }

    private function createJsonRequest(array $data): Request
    {
        return Request::create('/api/users', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data));
    }

    private function handle(RequestResponseLogger $listener, Request $request, Response $response): array
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::INFO, $this->logger->records[0]['level']);

        return $this->logger->records[0]['context'];
    }
}
