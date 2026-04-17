<?php
declare(strict_types=1);

namespace Henderkes\Fork\Future\Error;

use Henderkes\Fork\Error;

class Remote extends Error
{
    public function __construct(
        public readonly string $remoteClass,
        string $remoteMessage,
        public readonly string $remoteTrace,
    ) {
        $message = $remoteClass.': '.$remoteMessage;
        if ($remoteTrace !== '') {
            $message .= "\n\nRemote stack trace:\n".$remoteTrace;
        }
        parent::__construct($message);
    }
}
