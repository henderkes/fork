<?php

declare(strict_types=1);

namespace Henderkes\Fork\Future\Exception;

/**
 * Wraps an exception thrown inside a child process.
 *
 * The original exception cannot be reconstructed losslessly across the
 * process boundary (custom properties, closures in traces, etc. do not
 * survive (de)serialization reliably), so instead we carry the class
 * name, message, code, and rendered trace as plain scalars.
 */
final class Remote extends \Henderkes\Fork\Future\Exception
{
    public function __construct(
        public readonly string $remoteClass,
        string $remoteMessage,
        public readonly string $remoteTrace,
        int $remoteCode = 0,
    ) {
        $message = $remoteClass.': '.$remoteMessage;
        if ($remoteTrace !== '') {
            $message .= "\n\nRemote stack trace:\n".$remoteTrace;
        }
        parent::__construct($message, $remoteCode);
    }
}
