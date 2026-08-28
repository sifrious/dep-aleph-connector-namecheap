<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector\Contracts;

interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
