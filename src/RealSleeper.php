<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector;

use Sifrious\NamecheapConnector\Contracts\Sleeper;

final class RealSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
