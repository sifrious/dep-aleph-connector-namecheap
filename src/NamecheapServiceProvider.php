<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector;

use Illuminate\Support\ServiceProvider;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;

final class NamecheapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Normalizer::class);

        $this->app->singleton(NamecheapConnector::class, fn ($app): NamecheapConnector => new NamecheapConnector(
            $app->make(EnvelopeSubmitter::class),
            $app->make(Normalizer::class),
            $app->make(ConnectorInstallations::class),
        ));
    }

    public function boot(): void
    {
        $this->app->make(ConnectorRegistry::class)
            ->register($this->app->make(NamecheapConnector::class));
    }
}
