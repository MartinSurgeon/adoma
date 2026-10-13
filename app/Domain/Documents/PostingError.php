<?php

namespace App\Domain\Documents;

use DomainException;

/**
 * A posting was refused because it breaks an accounting rule: an unbalanced entry, a ledger
 * that cannot be used, a document already reversed. The message is written for the person
 * who pressed Save, so screens can show it as it is.
 */
final class PostingError extends DomainException
{
}
