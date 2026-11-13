<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The approval chain for a request total cannot be built (no rule, or a level has no active
 * approver). The message is user-facing Indonesian text and is shown as-is.
 */
class ApprovalChainException extends RuntimeException
{
}
