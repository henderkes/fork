<?php

declare(strict_types=1);

namespace Henderkes\Fork\Future;

/**
 * @internal internal state of a {@see \Henderkes\Fork\Future}
 */
enum State
{
    case Pending;
    case Succeeded;
    case Failed;
    case Cancelled;
    case Killed;
}
