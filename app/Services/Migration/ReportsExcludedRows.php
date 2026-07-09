<?php

namespace App\Services\Migration;

/**
 * Optional capability for a mapper whose legacy rows depend on a parent that must
 * also exist in the legacy database (e.g. a document's customer). rows()/count() are
 * expected to already filter out rows with no such parent (for performance — see
 * DocumentMapper), so this reports how many were excluded that way without ever
 * fetching them, purely for visibility into legacy data-quality gaps.
 */
interface ReportsExcludedRows
{
    /** Must be a single cheap COUNT query — never fetch/iterate the excluded rows themselves. */
    public function excludedCount(): int;

    /** Human label for what "excluded" means here, e.g. "no matching customer in legacy data". */
    public function excludedLabel(): string;
}
