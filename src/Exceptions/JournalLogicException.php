<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

use LogicException;

/**
 * A developer error: wrong code or configuration. These indicate a bug
 * to fix rather than a condition to catch and handle.
 */
abstract class JournalLogicException extends LogicException implements JournalException {}
