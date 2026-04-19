<?php

declare(strict_types=1);

namespace Henderkes\Fork\Symfony;

use Henderkes\Fork\Runtime;

/**
 * Marker for services that need to register fork hooks on an autowired {@see Runtime}.
 *
 * Services implementing this interface are auto-tagged by {@see ForkBundle}
 * and their {@see configure()} method is called..
 */
interface ForkAwareInterface
{
    /**
     * Register `before`/`after` hooks on the given {@see Runtime} and
     * return it. {@see Runtime::before()} and {@see Runtime::after()}.
     */
    public function configure(Runtime $runtime): Runtime;
}
