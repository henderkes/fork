<?php

declare(strict_types=1);

namespace Henderkes\Fork;

/**
 * Runs closures in forked child processes.
 *
 * Each {@see run()} call:
 *  1. allocates a Unix socket pair,
 *  2. forks with {@see pcntl_fork()},
 *  3. in the child: waits for the parent's "ready" handshake, runs any
 *     {@see before()} `child:` hooks, runs the task, writes a framed
 *     serialized result onto the socket and exits,
 *  4. in the parent: runs any {@see before()} `parent:` hooks, then
 *     signals the child to proceed and returns a {@see Future}.
 *
 * Child processes must exit after writing their result — the destructors
 * of inherited resources (DB connections, file handles, etc.) must not
 * run in a forked child or they will close fds the parent still uses.
 * Register a `before(child:)` hook that resets those resources before
 * the task runs, but keep a reference to them so they don't get destructed.
 */
final class Runtime
{
    private static bool $inChild = false;

    private bool $closed = false;

    /** @var array<int, Future> */
    private array $children = [];

    /** @var list<callable> */
    private array $beforeChild = [];

    /** @var list<callable> */
    private array $beforeParent = [];

    /** @var list<callable> */
    private array $afterChild = [];

    /** @var list<callable> */
    private array $afterParent = [];

    public function before(?callable $child = null, ?callable $parent = null): self
    {
        if ($child !== null) {
            $this->beforeChild[] = $child;
        }
        if ($parent !== null) {
            $this->beforeParent[] = $parent;
        }

        return $this;
    }

    public function after(?callable $child = null, ?callable $parent = null): self
    {
        if ($child !== null) {
            $this->afterChild[] = $child;
        }
        if ($parent !== null) {
            $this->afterParent[] = $parent;
        }

        return $this;
    }

    /**
     * True inside a forked child, false in the parent.
     */
    public static function inChild(): bool
    {
        return self::$inChild;
    }

    /**
     * @var list<mixed> values pinned for the lifetime of a forked child
     *
     * @noinspection PhpPropertyOnlyWrittenInspection
     *
     * @phpstan-ignore property.onlyWritten
     */
    private static array $stashToAbandon = [];

    /**
     * Pin values for the forked child's lifetime so PHP's GC won't close
     * fds the parent still owns. {@see before()} child hooks.
     *
     * @throws \LogicException if called in the parent
     */
    public static function abandon(mixed ...$refs): void
    {
        if (!self::$inChild) {
            throw new \LogicException('Runtime::abandon() is only valid in a forked child');
        }
        foreach ($refs as $ref) {
            self::$stashToAbandon[] = $ref;
        }
    }

    /**
     * Fork and run $task in a child process.
     *
     * @param callable     $task a closure (or other callable) to execute in the child
     * @param array<mixed> $argv positional arguments unpacked into the task
     *
     * @throws Runtime\Exception\Closed           if this runtime has already been closed
     * @throws Runtime\Exception\SocketPairFailed if the kernel refused to allocate the socket pair
     * @throws Runtime\Exception\ForkFailed       if pcntl_fork() failed
     */
    public function run(callable $task, array $argv = []): Future
    {
        if ($this->closed) {
            throw new Runtime\Exception\Closed('Runtime has been closed');
        }

        [$parentEnd, $childEnd] = $this->createSocketPair();

        $pid = \pcntl_fork();
        if ($pid < 0) {
            \fclose($parentEnd);
            \fclose($childEnd);
            throw new Runtime\Exception\ForkFailed($this->posixError('pcntl_fork() failed'));
        }

        if ($pid === 0) {
            \fclose($parentEnd);
            $this->runChild($task, $argv, $childEnd);
        }

        \fclose($childEnd);

        return $this->runParent($pid, $parentEnd);
    }

    /**
     * Gracefully drain every outstanding child. Failures are swallowed —
     * close() only cares that children are dead. Retrieve task results
     * or failures via {@see Future::value()} before closing.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        foreach ($this->children as $future) {
            try {
                $future->value();
            } catch (\Throwable) {
            }
        }
        $this->children = [];
    }

    public function kill(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        foreach ($this->children as $future) {
            $future->kill();
        }

        $this->children = [];
    }

    /** @internal Called by {@see Future::value()} / kill(); do not invoke directly. */
    public function childCompleted(int $pid, mixed $result, int $status): void
    {
        unset($this->children[$pid]);

        foreach ($this->afterParent as $cb) {
            $cb($result, $status);
        }
    }

    public function __destruct()
    {
        if ($this->closed || self::$inChild) {
            return;
        }
        $this->close();
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function createSocketPair(): array
    {
        $pair = @\stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($pair === false || \count($pair) !== 2) {
            throw new Runtime\Exception\SocketPairFailed($this->lastError('stream_socket_pair failed'));
        }

        return [$pair[0], $pair[1]];
    }

    /**
     * @param array<mixed> $argv
     * @param resource     $childEnd
     */
    private function runChild(callable $task, array $argv, mixed $childEnd): never
    {
        self::$inChild = true;
        $this->children = [];
        $this->closed = true;

        $this->waitForParentReady($childEnd);

        [$exitCode, $payload] = $this->executeTaskInChild($task, $argv);

        $this->writeFramed($childEnd, $payload);
        \fclose($childEnd);

        \posix_kill(\posix_getpid(), \SIGKILL);
        exit($exitCode);
    }

    /**
     * Run the task and its hook pairs in the child.
     *
     * @param array<mixed> $argv
     *
     * @return array{0: int, 1: string}
     */
    private function executeTaskInChild(callable $task, array $argv): array
    {
        try {
            foreach ($this->beforeChild as $cb) {
                $cb();
            }
        } catch (\Throwable $e) {
            return [1, \serialize($this->encodeException($e))];
        }

        try {
            $result = $task(...$argv);
            $payload = \serialize(['ok' => true, 'value' => $result]);
            $exitCode = 0;
        } catch (\Throwable $e) {
            $payload = \serialize($this->encodeException($e));
            $exitCode = 1;
        }

        foreach ($this->afterChild as $cb) {
            try {
                $cb();
            } catch (\Throwable $e) {
                if ($exitCode === 0) {
                    $exitCode = 1;
                    $payload = \serialize($this->encodeException($e));
                }
            }
        }

        return [$exitCode, $payload];
    }

    /**
     * @param resource $parentEnd
     */
    private function runParent(int $pid, mixed $parentEnd): Future
    {
        try {
            foreach ($this->beforeParent as $cb) {
                $cb();
            }
        } catch (\Throwable $e) {
            // Child is blocked in waitForParentReady; kill it before
            // surfacing the parent-side error so it doesn't linger.
            \posix_kill($pid, \SIGTERM);
            \pcntl_waitpid($pid, $status);
            if (\is_resource($parentEnd)) {
                \fclose($parentEnd);
            }
            throw $e;
        }

        $this->releaseChild($parentEnd);

        $future = new Future($pid, $parentEnd, $this);
        $this->children[$pid] = $future;

        return $future;
    }

    /**
     * @return array{ok: false, message: string, class: class-string, trace: string, code: int}
     */
    private function encodeException(\Throwable $e): array
    {
        return [
            'ok' => false,
            'message' => $e->getMessage(),
            'class' => \get_class($e),
            'trace' => $e->getTraceAsString(),
            'code' => $e->getCode(),
        ];
    }

    /**
     * @param resource $stream
     */
    private function writeFramed(mixed $stream, string $payload): void
    {
        $this->writeAll($stream, \pack('N', \strlen($payload)));
        $this->writeAll($stream, $payload);
    }

    /**
     * @param resource $stream
     */
    private function writeAll(mixed $stream, string $data): void
    {
        $len = \strlen($data);
        $offset = 0;
        while ($offset < $len) {
            $written = @\fwrite($stream, $offset === 0 ? $data : \substr($data, $offset));
            if ($written === false || $written === 0) {
                return;
            }
            $offset += $written;
        }
    }

    /**
     * @param resource $childEnd
     */
    private function waitForParentReady(mixed $childEnd): void
    {
        $byte = @\fread($childEnd, 1);
        if ($byte === false || $byte === '') {
            // Parent died before the handshake and no one will collect
            // our result. SIGKILL self instead of exit() so we skip
            // framework shutdown handlers — they'd flush caches, close
            // DB sockets, etc., on fds the parent still holds. SIGKILL
            // is synchronous and not deliverable to handlers, so the
            // subsequent exit() is unreachable but keeps PHPStan happy.
            \posix_kill(\posix_getpid(), \SIGKILL);
            exit(1);
        }
    }

    /**
     * @param resource $parentEnd
     */
    private function releaseChild(mixed $parentEnd): void
    {
        @\fwrite($parentEnd, "\x01");
    }

    private function lastError(string $prefix): string
    {
        $err = \error_get_last();
        if ($err === null || $err['message'] === '') {
            return $prefix;
        }

        return $prefix.': '.$err['message'];
    }

    private function posixError(string $prefix): string
    {
        $errno = \posix_get_last_error();
        if ($errno === 0) {
            return $this->lastError($prefix);
        }

        return $prefix.': '.\posix_strerror($errno).' (errno '.$errno.')';
    }
}
