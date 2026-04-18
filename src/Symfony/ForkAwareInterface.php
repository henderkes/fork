<?php

declare(strict_types=1);

namespace Henderkes\Fork\Symfony;

/**
 * Marker for services that hold resources that must be re-initialized after forking.
 *
 * Services implementing this interface are auto-tagged by
 * {@see ForkBundle} and their {@see atFork()} method is wired
 * as a `before(child:)` hook on the auto-wired {@see \Henderkes\Fork\Runtime}.
 */
interface ForkAwareInterface
{
    /**
     * Invoked inside each forked child before the task runs. Reset,
     * reconnect, and abandon any resource that cannot be safely shared
     * across processes.
     */
    public function atFork(): void;
}
