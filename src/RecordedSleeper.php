<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector;

use Sifrious\NamecheapConnector\Contracts\Sleeper;

/**
 * Records the backoff a run would have waited without waiting for it, so a
 * retry test asserts on the schedule rather than on elapsed time.
 */
final class RecordedSleeper implements Sleeper
{
    /** @var list<int> */
    public array $slept = [];

    public function sleep(int $milliseconds): void
    {
        $this->slept[] = $milliseconds;
    }
}
