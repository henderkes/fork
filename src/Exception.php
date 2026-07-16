<?php

declare(strict_types=1);

namespace Henderkes\Fork;

/**
 * Base class for every exception thrown by this library.
 *
 * Every Runtime/Future-level exception extends
 * this class so callers can catch everything from
 * this package with a single catch.
 */
abstract class Exception extends \RuntimeException
{
}
