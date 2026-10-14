<?php

namespace App\Domain\Gold;

use DomainException;

/**
 * The weights and price cannot give a sensible answer. The message is written for the person
 * typing, and the field says which box on the form it belongs next to.
 */
final class CalculationError extends DomainException
{
    public function __construct(string $message, public readonly string $field = 'weight')
    {
        parent::__construct($message);
    }
}
