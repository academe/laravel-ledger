<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Exceptions;

use Throwable;

/**
 * Marker interface for everything the package throws deliberately:
 * catch (JournalException $e) covers the whole package.
 *
 * Concrete exceptions extend one of two bases beneath this interface:
 * JournalLogicException (developer errors — fix the code or config)
 * or JournalRuntimeException (conditions a correct application can
 * still hit, and may want to handle).
 */
interface JournalException extends Throwable {}
