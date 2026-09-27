<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

/**
 * Which terminal signal a fake positional upstream exposes. One case per branch of
 * {@see \CursorWalk\Offset\OffsetPage::hasMoreAfter()}, so a test names the branch it exercises.
 */
enum TotalsReporting
{
    case TotalPages;
    case TotalItems;
    case Neither;
}
