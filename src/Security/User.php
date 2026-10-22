<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final class User implements UserInterface
{
    private object $accessToken;

    public function __construct(
        private readonly string $username,
        private readonly array $roles,
    )
    {
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // there is no sensitive data to remove
        // from the User object
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getAccessToken(): object
    {
        return $this->accessToken;
    }

    public function setAccessToken(object $accessToken): void
    {
        $this->accessToken = $accessToken;
    }
}