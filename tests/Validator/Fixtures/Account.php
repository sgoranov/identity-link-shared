<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\Validator\Fixtures;

use sgoranov\IdentityLinkShared\Validator\UniqueEntry;

final class Account
{
    public function __construct(
        private readonly ?string $id,
        #[UniqueEntry]
        private readonly string $email,
    )
    {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}
