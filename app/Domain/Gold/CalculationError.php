<?php

namespace App\Domain\Gold;

use DomainException;

/**
 * The weights and price cannot give a sensible answer. The message is written for the person
 * typing, and the field says which box on the form it belongs next to. A karat a little over 24 is
 * "overridable": an admin may allow it, anyone else is told to ask one.
 */
final class CalculationError extends DomainException
{
    public function __construct(string $message, public readonly string $field = 'weight', public readonly bool $overridable = false)
    {
        parent::__construct($message);
    }
}
