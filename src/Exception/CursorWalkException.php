<?php

declare(strict_types=1);

namespace CursorWalk\Exception;

/**
 * Base class for every exception thrown by this library.
 *
 * Catch this to handle any cursor-walk failure generically; catch the concrete
 * subclasses to distinguish an upstream bug ({@see PaginationLoopException},
 * {@see MalformedPageException}) from a policy limit
 * ({@see PageBudgetExceededException}).
 */
class CursorWalkException extends \RuntimeException
{
}
