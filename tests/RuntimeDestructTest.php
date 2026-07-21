<?php

declare(strict_types=1);

namespace Tests;

use Henderkes\Fork\Runtime;
use PHPUnit\Framework\TestCase;

final class RuntimeDestructTest extends TestCase
{
    public function testRuntimeDestructsImmediatelyDespitePendingFutures(): void
    {
        \gc_disable();
        try {
            $completed = 0;
            $runtime = new Runtime()->after(parent: function () use (&$completed) { ++$completed; });
            $weakRuntime = \WeakReference::create($runtime);

            $future = $runtime->run(fn () => 42);
            unset($runtime);

            // No cycle: releasing the last user reference must destruct the
            // runtime (running close(), which drains the child) without the
            // cycle collector's help.
            self::assertNull($weakRuntime->get());
            self::assertSame(1, $completed);
            self::assertSame(42, $future->value());
        } finally {
            \gc_enable();
        }
    }
}
