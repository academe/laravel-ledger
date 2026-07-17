<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Contracts;

/**
 * Opt-in display naming for journal owner models.
 *
 * The package resolves a human name for a journal through its owner:
 * implement this on the owner model and the package uses it wherever it
 * names a journal (exception messages; potentially future admin/debug
 * output). It specifies capabilities, not storage — the values can come
 * from a column, an accessor, or anywhere else. Read-only display
 * concerns only.
 */
interface NamesJournal
{
    /**
     * Short human name for this owner's journal,
     * e.g. "Margaret Whitfield", "VAT owed".
     */
    public function journalDisplayName(): string;

    /**
     * Optional longer description; null when there is nothing more
     * to say.
     */
    public function journalDescription(): ?string;
}
