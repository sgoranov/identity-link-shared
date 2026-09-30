<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use sgoranov\IdentityLinkShared\Security\AccessTokenHandler;
use sgoranov\IdentityLinkShared\Security\AccessTokenHandlerConfiguration;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class AccessTokenHandlerTest extends TestCase
{
    #[DataProvider('validAudienceProvider')]
    public function testItAcceptsValidIssuerAndAudience(string|array $audience): void
    {
        $token = (object) [
            'iss' => 'identity-link',
            'aud' => $audience,
            'sub' => 'user-id',
        ];

        $badge = $this->createHandlerReturning($token)->getUserBadgeFrom('token');
        $user = $badge->getUser();

        self::assertSame('user-id', $badge->getUserIdentifier());
        self::assertSame([], $user->getRoles());
        self::assertSame($token, $user->getAccessToken());
    }

    public static function validAudienceProvider(): iterable
    {
        yield 'string audience' => ['identity-api'];
        yield 'audience list' => [['another-api', 'identity-api']];
    }

    public function testItAcceptsTokenWhenAnyAudienceIsConfigured(): void
    {
        $token = (object) ['iss' => 'identity-link', 'aud' => ['identity-api', 'second-api'], 'sub' => 'user-id'];

        $badge = $this->createHandlerReturning($token, ['identity-api', 'second-api'])->getUserBadgeFrom('token');

        self::assertSame('user-id', $badge->getUserIdentifier());
    }

    public function testItRejectsTokenWhenNoAudienceIsConfigured(): void
    {
        $token = (object) ['iss' => 'identity-link', 'aud' => ['identity-api', 'untrusted-api'], 'sub' => 'user-id'];

        $this->expectException(BadCredentialsException::class);

        $this->createHandlerReturning($token, ['another-api', 'second-api'])->getUserBadgeFrom('token');
    }

    #[DataProvider('invalidClaimsProvider')]
    public function testItRejectsInvalidIssuerOrAudience(object $token): void
    {
        $this->expectException(BadCredentialsException::class);

        $this->createHandlerReturning($token)->getUserBadgeFrom('token');
    }

    public static function invalidClaimsProvider(): iterable
    {
        yield 'missing issuer' => [(object) ['aud' => 'identity-api']];
        yield 'incorrect issuer' => [(object) ['iss' => 'another-issuer', 'aud' => 'identity-api']];
        yield 'missing audience' => [(object) ['iss' => 'identity-link']];
        yield 'incorrect audience' => [(object) ['iss' => 'identity-link', 'aud' => 'another-api']];
        yield 'invalid audience type' => [(object) ['iss' => 'identity-link', 'aud' => 123]];
    }

    public function testItUsesConfiguredAudienceWhenSubjectIsMissing(): void
    {
        $token = (object) [
            'iss' => 'identity-link',
            'aud' => ['another-api', 'identity-api'],
        ];

        $badge = $this->createHandlerReturning($token)->getUserBadgeFrom('token');

        self::assertSame('identity-api', $badge->getUserIdentifier());
        self::assertSame([], $badge->getUser()->getRoles());
    }

    #[DataProvider('scopeProvider')]
    public function testItAddsEveryScopeAsARole(string $scope, array $expectedRoles): void
    {
        $token = (object) [
            'iss' => 'identity-link',
            'aud' => 'identity-api',
            'scope' => $scope,
        ];

        $user = $this->createHandlerReturning($token)->getUserBadgeFrom('token')->getUser();

        self::assertSame($expectedRoles, $user->getRoles());
    }

    public static function scopeProvider(): iterable
    {
        yield 'space-delimited scope claim' => ['read write', ['read', 'write']];
        yield 'extra whitespace' => [' read  write ', ['read', 'write']];
        yield 'duplicate scopes' => ['read read', ['read']];
        yield 'empty scope' => ['', []];
        yield 'spaces-only scope' => ['   ', []];
    }

    private function createHandlerReturning(object $decodedToken, array $audiences = ['identity-api']): AccessTokenHandler&MockObject
    {
        $configuration = new AccessTokenHandlerConfiguration(
            issuer: 'identity-link',
            audiences: $audiences,
            publicKeyPath: __DIR__.'/Fixtures/public-key.pem',
        );

        $handler = $this->getMockBuilder(AccessTokenHandler::class)
            ->setConstructorArgs([
                $this->createStub(ClientInterface::class),
                $this->createStub(RequestFactoryInterface::class),
                $this->createStub(LoggerInterface::class),
                $configuration,
            ])
            ->onlyMethods(['decode'])
            ->getMock();

        $handler->method('decode')->willReturn($decodedToken);

        return $handler;
    }
}
