<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Security;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

class AccessTokenHandler implements AccessTokenHandlerInterface
{
    private const SCOPE_CLAIM = 'scope';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $factory,
        private readonly LoggerInterface $logger,
        private readonly AccessTokenHandlerConfiguration $configuration,
    )
    {
    }

    public function decode($accessToken, $keySet): object
    {
        try {
            $decoded = JWT::decode($accessToken, $keySet);

            // Get the current time
            $currentTime = time();

            // Check 'iat' claim
            if (isset($decoded->iat) && (int) $decoded->iat > $currentTime) {
                throw new BadCredentialsException('JWT token is not yet valid.');
            }

            // Check 'nbf' claim
            if (isset($decoded->nbf) && (int) $decoded->nbf > $currentTime) {
                throw new BadCredentialsException('JWT token is not yet valid.');
            }

            // Check 'exp' claim
            if (isset($decoded->exp) && (int) $decoded->exp < $currentTime) {
                throw new BadCredentialsException('JWT token has expired.');
            }

            return $decoded;

        } catch (\LogicException $e) {

            $this->logger->critical('JWT Authentication is broken. Malformed keys or environment misconfiguration.', [
                'exception' => $e,
            ]);
            throw new BadCredentialsException('Invalid credentials.');

        } catch (\UnexpectedValueException $e) {

            $this->logger->error('JWT validation failed. Token signature or claims are invalid.', [
                'exception' => $e,
            ]);
            throw new BadCredentialsException('Invalid credentials.');

        } catch (\OutOfBoundsException $e) {

            $this->logger->error('Authentication failed. Token signed with an unknown key ID (kid).', [
                'exception' => $e,
            ]);
            throw new BadCredentialsException('Invalid credentials.');
        }
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $key = $this->configuration->getPublicKey() ?? $this->loadKeyFromJWKS();
        $decoded = $this->decode($accessToken, $key);

        if (!isset($decoded->iss) || $this->configuration->getIssuer() !== $decoded->iss) {
            throw new BadCredentialsException('JWT iss is not valid.');
        }

        $audiences = isset($decoded->aud) && (is_string($decoded->aud) || is_array($decoded->aud))
            ? (array) $decoded->aud
            : [];

        $matchedAudience = null;
        foreach ($audiences as $audience) {
            if (in_array($audience, $this->configuration->getAudiences(), true)) {
                $matchedAudience = $audience;
                break;
            }
        }

        if (null === $matchedAudience) {
            throw new BadCredentialsException('JWT aud is not valid.');
        }

        $scopes = [];
        if (isset($decoded->{self::SCOPE_CLAIM})) {
            $scopes = preg_split('/\s+/', trim($decoded->{self::SCOPE_CLAIM}), -1, PREG_SPLIT_NO_EMPTY);
        }

        if (!empty($decoded->sub)) {
            $identifier = $decoded->sub;
        } else {
            $identifier = $matchedAudience;
        }

        // Create user badge
        return new UserBadge($identifier, function (string $userIdentifier, array $attribs)  use ($decoded): ?UserInterface {
            $user = new User($userIdentifier, array_values(array_unique($attribs['scopes'])));

            $user->setAccessToken($decoded);

            return $user;
        }, ['scopes' => $scopes]);
    }

    private function loadKeyFromJWKS(): CachedKeySet
    {
        $jwksUri = $this->configuration->getJwksUri();
        if (null === $jwksUri) {
            throw new \LogicException('A JWKS URI is required when no public key is configured.');
        }

        $cache = new FilesystemAdapter(
            $namespace = 'JWKeySet',

            // the default lifetime (in seconds) for cache items that do not define their
            // own lifetime, with a value 0 causing items to be stored indefinitely (i.e.
            // until the files are deleted)
            $defaultLifetime = 3600,

            // the main cache directory (the application needs read-write permissions on it)
            // if none is specified, a directory is created inside the system temporary directory
            $directory = null
        );

        return new CachedKeySet(
            $jwksUri,
            $this->client,
            $this->factory,
            $cache,
            null, // $expiresAfter int seconds to set the JWKS to expire
            true  // $rateLimit    true to enable rate limit of 10 RPS on lookup of invalid keys
        );
    }
}
