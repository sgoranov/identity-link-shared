<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class JsonChoice extends Constraint
{
    public string $message = 'The value "{{ invalidChoices }}" is not a valid choice.';
    public array $choices;

    #[HasNamedArguments]
    public function __construct(array $choices, ?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
        $this->choices = $choices;
        $this->message = $message ?? $this->message;
    }
}