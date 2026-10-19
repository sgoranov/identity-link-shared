<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sgoranov\IdentityLinkShared\Security\AccessTokenHandlerConfiguration;

final class AccessTokenHandlerConfigurationTest extends TestCase
{
    public function testItCanUseAPublicKey(): void
    {
        $configuration = new AccessTokenHandlerConfiguration(
            issuer: 'identity-link',
            audiences: ['identity-api'],
            publicKeyPath: __DIR__.'/Fixtures/public-key.pem',
        );

        self::assertSame('RS256', $configuration->getPublicKey()?->getAlgorithm());
        self::assertSame('test-public-key', trim((string) $configuration->getPublicKey()?->getKeyMaterial()));
        self::assertNull($configuration->getJwksUri());
        self::assertSame('identity-link', $configuration->getIssuer());
        self::assertSame(['identity-api'], $configuration->getAudiences());
    }

    public function testItCanUseAJwksUri(): void
    {
        $configuration = new AccessTokenHandlerConfiguration(
            issuer: 'identity-link',
            audiences: ['identity-api'],
            jwksUri: 'https://identity.example/.well-known/jwks.json',
        );

        self::assertNull($configuration->getPublicKey());
        self::assertSame('https://identity.example/.well-known/jwks.json', $configuration->getJwksUri());
    }

    public function testItNormalizesMultipleAudiences(): void
    {
        $configuration = new AccessTokenHandlerConfiguration(
            issuer: 'identity-link',
            audiences: ['identity-api', 'second-api'],
            publicKeyPath: __DIR__.'/Fixtures/public-key.pem',
        );

        self::assertSame(['identity-api', 'second-api'], $configuration->getAudiences());
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testItRejectsInvalidConfiguration(
        string $issuer,
        array $audiences,
        ?string $publicKeyPath,
        ?string $jwksUri,
    ): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AccessTokenHandlerConfiguration($issuer, $audiences, $publicKeyPath, $jwksUri);
    }

    public static function invalidConfigurationProvider(): iterable
    {
        $publicKeyPath = __DIR__.'/Fixtures/public-key.pem';

        yield 'empty issuer' => ['', ['identity-api'], $publicKeyPath, null];
        yield 'empty audience list' => ['identity-link', [], $publicKeyPath, null];
        yield 'empty audience value' => ['identity-link', [''], $publicKeyPath, null];
        yield 'no verification source' => ['identity-link', ['identity-api'], null, null];
        yield 'both verification sources' => ['identity-link', ['identity-api'], $publicKeyPath, 'https://identity.example/jwks.json'];
        yield 'empty JWKS URI' => ['identity-link', ['identity-api'], null, ''];
        yield 'missing public key file' => ['identity-link', ['identity-api'], __DIR__.'/Fixtures/missing.pem', null];
    }
}
