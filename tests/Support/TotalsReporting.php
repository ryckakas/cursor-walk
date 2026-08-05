<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

/**
 * Which terminal-condition signal a fake positional upstream exposes.
 *
 * The three cases correspond one-to-one to the precedence table in
 * {@see \CursorWalk\Offset\OffsetPage::hasMoreAfter()}, so every offset test can
 * name the branch it is exercising instead of juggling nullable ints.
 */
enum TotalsReporting
{
    /** The envelope reports `totalPages` only. */
    case TotalPages;

    /** The envelope reports a total row count only. */
    case TotalItems;

    /** The envelope reports neither; only a short page ends the walk. */
    case Neither;
}
