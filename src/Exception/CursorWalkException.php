<?php

declare(strict_types=1);

namespace CursorWalk\Exception;

/**
 * Base class for every exception this library throws. Catch the subclasses to
 * tell an upstream bug (PaginationLoopException, MalformedPageException) from a
 * policy limit (PageBudgetExceededException).
 */
class CursorWalkException extends \RuntimeException
{
}
