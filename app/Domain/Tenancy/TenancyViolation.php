<?php

namespace App\Domain\Tenancy;

use LogicException;

/**
 * Thrown when code tries to read or write across a business or office boundary.
 * It is a LogicException on purpose: it signals a bug, never a user mistake.
 */
final class TenancyViolation extends LogicException
{
}
