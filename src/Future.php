<?php

declare(strict_types=1);

namespace Henderkes\Fork;

use Henderkes\Fork\Future\State;

/**
 * Handle to the result of a forked task.
 */
final class Future
{
    private State $state = State::Pending;

    private mixed $cached = null;

    private ?\Throwable $cachedError = null;

    /** @var resource|null */
    private mixed $stream;

    private ?int $waitStatus = null;

    private bool $reaped = false;

    private ?int $drainedAtNs = null;

    /**
     * @internal
     *
     * @param resource $stream
     */
    public function __construct(
        private int $pid,
        mixed $stream,
        private Runtime $runtime,
    ) {
        if ($pid <= 0) {
            throw new \InvalidArgumentException("Expected a positive pid, got $pid");
        }
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Expected a valid resource for stream');
        }
        $this->stream = $stream;
    }

    /**
     * Reap every future in arrival order via stream_select, returning the
     * results in input order. Faster than a serial {@see value()} loop
     * when sibling workers finish out of order — slow children don't
     * block collection of fast ones.
     *
     * @return list<mixed>
     * @noinspection PhpUnused
     */
    public static function await(self ...$futures): array
    {
        $results = \array_fill(0, \count($futures), null);
        /** @var array<int, array{0: int, 1: self}> $pending indexed by (int)$stream */
        $pending = [];

        foreach ($futures as $i => $f) {
            if ($f->state !== State::Pending || !\is_resource($f->stream)) {
                $results[$i] = $f->value();
                continue;
            }
            $pending[(int) $f->stream] = [$i, $f];
        }

        while ($pending !== []) {
            $read = [];
            foreach ($pending as $key => [$_, $f]) {
                $read[$key] = $f->stream;
            }
            $w = null;
            $e = null;
            $n = @\stream_select($read, $w, $e, null);
            if ($n === false || $n === 0) {
                break;
            }
            foreach ($read as $key => $_) {
                [$i, $f] = $pending[$key];
                unset($pending[$key]);
                $results[$i] = $f->value();
            }
        }

        // If select errored, drain whatever's left serially.
        foreach ($pending as [$i, $f]) {
            $results[$i] = $f->value();
        }

        return $results;
    }

    /**
     * Block until the task finishes. Returns the task's return value or
     * re-throws whatever the task threw (wrapped in {@see Future\Exception\Remote}).
     *
     * @throws Future\Exception\Cancelled if {@see cancel()} won the race
     * @throws Future\Exception\Killed    if the child was killed via
     *                                    {@see kill()} or by an external signal
     * @throws Future\Exception\Remote    if the task itself threw
     * @throws Future\Exception\Foreign   if the child crashed or sent a malformed payload
     */
    public function value(): mixed
    {
        if ($this->state !== State::Pending) {
            return $this->replayTerminalState();
        }

        $stream = $this->stream;
        if (!\is_resource($stream)) {
            $this->reapIfNeeded();
            $this->fail(new Future\Exception\Foreign('Stream is not a valid resource'));
        }

        $payload = $this->readFramedPayload($stream);
        $this->drainedAtNs = \hrtime(true);
        \fclose($stream);
        $this->stream = null;

        $status = $this->reap();

        if ($payload === null) {
            $this->fail($this->interpretEmptyPayload($status));
        }

        $decoded = $this->decodePayload($payload);
        if ($decoded === null) {
            $this->fail(new Future\Exception\Foreign('Invalid or truncated data from child process'));
        }

        if ($decoded['ok'] === false) {
            $this->fail(new Future\Exception\Remote(
                $decoded['class'],
                $decoded['message'],
                $decoded['trace'],
                $decoded['code'] ?? 0,
            ));
        }

        $this->state = State::Succeeded;
        $this->cached = $decoded['value'];
        $this->runtime->childCompleted($this->pid, $this->cached, $status);

        return $this->cached;
    }

    /**
     * hrtime(true) at the moment this future's payload was drained from
     * the socket, or null if the future hasn't been read yet. Useful for
     * per-worker timing in dashboards; ordering matches completion order.
     */
    public function drainedAt(): ?int
    {
        return $this->drainedAtNs;
    }

    /**
     * Poll whether the child has exited. True also means the
     * child has been reaped — it will not show in `waitpid()` again.
     */
    public function done(): bool
    {
        if ($this->state !== State::Pending) {
            return true;
        }

        return $this->reapNonBlocking();
    }

    /**
     * Request cooperative cancellation of the task. Sends SIGTERM to the
     * child and closes the parent's socket end; {@see value()} will then
     * throw {@see Future\Exception\Cancelled}.
     *
     * Returns `true` if the call actually moved the future into the
     * Cancelled state, `false` if the task had already finished.
     *
     * @throws Future\Exception\Cancelled if the future was already cancelled
     */
    public function cancel(): bool
    {
        if ($this->state === State::Cancelled) {
            throw new Future\Exception\Cancelled('task was already cancelled');
        }
        if ($this->state !== State::Pending) {
            return false;
        }

        $this->state = State::Cancelled;
        $this->cachedError = new Future\Exception\Cancelled('cannot retrieve value');
        \posix_kill($this->pid, \SIGTERM);
        $this->closeStream();

        return true;
    }

    public function cancelled(): bool
    {
        return $this->state === State::Cancelled;
    }

    /**
     * @internal called by {@see Runtime::kill()}
     */
    public function kill(): void
    {
        if ($this->state === State::Killed
            || $this->state === State::Succeeded
            || $this->state === State::Failed
        ) {
            return;
        }

        $this->state = State::Killed;
        $this->cachedError = Future\Exception\Killed::explicit();
        $this->closeStream();

        \posix_kill($this->pid, \SIGKILL);
        $this->reap();
    }

    /** @internal Called by {@see Runtime} to drain children. */
    public function reap(): int
    {
        if ($this->reaped) {
            return $this->waitStatus ?? 0;
        }

        $r = $this->waitpid(0);
        $this->reaped = true;
        if ($r['status'] !== null) {
            $this->waitStatus = $r['status'];

            return $r['status'];
        }

        // ECHILD or similar — we never got a real status.
        return 0;
    }

    /**
     * Wrapper around pcntl_waitpid that retries on EINTR
     * (signal delivery interrupting the syscall) so a busy parent
     * doesn't leak zombies when, e.g., SIGCHLD from another child
     * wakes our blocking wait.
     *
     * @return array{'result': int, 'status': int|null} result: >0 reaped
     *                                                  with captured status; 0 still running (WNOHANG); -1 ECHILD
     */
    private function waitpid(int $flags): array
    {
        $status = 0;
        while (true) {
            $res = \pcntl_waitpid($this->pid, $status, $flags);
            if ($res > 0) {
                return ['result' => $res, 'status' => \is_int($status) ? $status : 0];
            }
            if ($res === 0) {
                return ['result' => 0, 'status' => null];
            }
            // $res === -1: either EINTR (retry) or ECHILD (give up).
            if (\pcntl_get_last_error() !== \PCNTL_EINTR) {
                return ['result' => -1, 'status' => null];
            }
        }
    }

    public function __destruct()
    {
        if (Runtime::inChild()) {
            return;
        }
        $this->closeStream();
        if (!$this->reaped && ($this->state === State::Pending || $this->state === State::Cancelled)) {
            $this->reap();
        }
    }

    /**
     * @param resource $stream
     */
    private function readFramedPayload(mixed $stream): ?string
    {
        $header = $this->readExactly($stream, 4);
        if ($header === null) {
            return null;
        }
        /** @var array{1: int} $unpacked */
        $unpacked = \unpack('N', $header);
        $expected = $unpacked[1];
        if ($expected <= 0) {
            return '';
        }

        return $this->readExactly($stream, $expected);
    }

    /**
     * @param resource     $stream
     * @param positive-int $bytes
     */
    private function readExactly(mixed $stream, int $bytes): ?string
    {
        $buf = '';
        while (($remaining = $bytes - \strlen($buf)) > 0) {
            if (\feof($stream)) {
                return null;
            }
            $chunk = @\fread($stream, $remaining);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    /**
     * @return array{ok: true, value: mixed}|array{ok: false, class: string, message: string, trace: string, code?: int}|null
     */
    private function decodePayload(string $data): ?array
    {
        $result = @\unserialize($data, ['allowed_classes' => true]);
        if (!\is_array($result) || !isset($result['ok']) || !\is_bool($result['ok'])) {
            return null;
        }
        if ($result['ok'] === true) {
            if (!\array_key_exists('value', $result)) {
                return null;
            }

            return ['ok' => true, 'value' => $result['value']];
        }
        $class = \is_string($result['class'] ?? null) ? $result['class'] : \RuntimeException::class;
        $message = \is_string($result['message'] ?? null) ? $result['message'] : 'Unknown error';
        $trace = \is_string($result['trace'] ?? null) ? $result['trace'] : '';
        $code = \is_int($result['code'] ?? null) ? $result['code'] : 0;

        return ['ok' => false, 'class' => $class, 'message' => $message, 'trace' => $trace, 'code' => $code];
    }

    private function interpretEmptyPayload(int $status): \Throwable
    {
        $pid = $this->pid;

        if ($this->waitStatus === null) {
            return new Future\Exception\Foreign(
                "child (pid=$pid) was reaped with no recorded status; no result was written"
            );
        }
        if (\pcntl_wifsignaled($status)) {
            $sig = \pcntl_wtermsig($status);

            return Future\Exception\Killed::bySignal($sig === false ? 0 : $sig);
        }
        if (\pcntl_wifexited($status)) {
            $code = \pcntl_wexitstatus($status);
            if ($code !== 0) {
                return new Future\Exception\Foreign("child (pid=$pid) exited with status $code without writing a result");
            }
        }

        return new Future\Exception\Foreign("child (pid=$pid) exited without writing a result");
    }

    private function fail(\Throwable $e): never
    {
        $status = $this->waitStatus ?? 0;
        $this->state = $e instanceof Future\Exception\Killed ? State::Killed : State::Failed;
        $this->cachedError = $e;
        $this->runtime->childCompleted($this->pid, $e, $status);
        throw $e;
    }

    private function replayTerminalState(): mixed
    {
        if ($this->state === State::Cancelled || $this->state === State::Killed) {
            $this->reapIfNeeded();
        }

        return match ($this->state) {
            State::Pending => null, // unreachable — guarded by caller
            State::Succeeded => $this->cached,
            State::Failed, State::Killed, State::Cancelled => throw $this->cachedError ?? new Future\Exception\Foreign('terminal state without cached error'),
        };
    }

    private function reapIfNeeded(): void
    {
        if (!$this->reaped) {
            $this->reap();
        }
    }

    private function reapNonBlocking(): bool
    {
        if ($this->reaped) {
            return true;
        }

        $r = $this->waitpid(\WNOHANG);
        if ($r['status'] !== null) {
            $this->waitStatus = $r['status'];
            $this->reaped = true;

            return true;
        }
        if ($r['result'] === -1) {
            // ECHILD: someone else reaped. Mark reaped so we don't loop
            $this->reaped = true;

            return true;
        }

        return false;
    }

    private function closeStream(): void
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
        $this->stream = null;
    }
}
