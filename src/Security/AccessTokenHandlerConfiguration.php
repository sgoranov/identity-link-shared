<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Security;

use Firebase\JWT\Key;

final class AccessTokenHandlerConfiguration
{
    private readonly ?Key $publicKey;
    private readonly array $audiences;

    public function __construct(
        private readonly string $issuer,
        array $audiences,
        ?string $publicKeyPath = null,
        private readonly ?string $jwksUri = null,
    )
    {
        if ('' === $this->issuer) {
            throw new \InvalidArgumentException('The token issuer must not be empty.');
        }

        $audiences = array_values($audiences);
        if ([] === $audiences) {
            throw new \InvalidArgumentException('The token audience must not be empty.');
        }

        foreach ($audiences as $audience) {
            if (!is_string($audience) || '' === $audience) {
                throw new \InvalidArgumentException('The token audience must not be empty.');
            }
        }

        $this->audiences = $audiences;

        if (($publicKeyPath === null) === ($this->jwksUri === null)) {
            throw new \InvalidArgumentException('Configure exactly one of a public key path or a JWKS URI.');
        }

        if ('' === $this->jwksUri) {
            throw new \InvalidArgumentException('The JWKS URI must not be empty.');
        }

        if (null === $publicKeyPath) {
            $this->publicKey = null;
        } else {
            if (!is_file($publicKeyPath) || !is_readable($publicKeyPath)) {
                throw new \InvalidArgumentException(sprintf('The public key file "%s" is not readable.', $publicKeyPath));
            }

            $publicKey = file_get_contents($publicKeyPath);
            if (false === $publicKey || '' === $publicKey) {
                throw new \InvalidArgumentException(sprintf('The public key file "%s" is empty or could not be read.', $publicKeyPath));
            }

            $this->publicKey = new Key($publicKey, 'RS256');
        }
    }

    public function getPublicKey(): ?Key
    {
        return $this->publicKey;
    }

    public function getJwksUri(): ?string
    {
        return $this->jwksUri;
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    public function getAudiences(): array
    {
        return $this->audiences;
    }
}
