<?php

declare(strict_types=1);

namespace Henderkes\Fork\Future\Exception;

final class Killed extends \Henderkes\Fork\Future\Exception
{
    public static function explicit(): self
    {
        return new self('task was killed');
    }

    public static function bySignal(int $signal): self
    {
        return new self("child was killed by signal $signal");
    }
}
