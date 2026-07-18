<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

use RuntimeException;

/**
 * A runtime condition a correctly-written application can still hit —
 * a closed period, an unbalanced group, a duplicate journal — and may
 * want to catch and handle.
 */
abstract class JournalRuntimeException extends RuntimeException implements JournalException {}
