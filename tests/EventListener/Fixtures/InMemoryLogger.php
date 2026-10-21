<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Tests\EventListener\Fixtures;

use Psr\Log\AbstractLogger;

final class InMemoryLogger extends AbstractLogger
{
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
