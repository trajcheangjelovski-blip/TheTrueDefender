<?php

namespace App\Services\Social;

class SocialManager
{
    /** @var array<string,SocialDriver> */
    private array $drivers = [];

    public function __construct()
    {
        foreach ([
            new TelegramDriver(),
            new XDriver(),
            new FacebookDriver(),
            new InstagramDriver(),
            new TruthDriver(),
        ] as $driver) {
            $this->drivers[$driver->key()] = $driver;
        }
    }

    /** @return array<string,SocialDriver> */
    public function all(): array
    {
        return $this->drivers;
    }

    public function resolve(string $key): ?SocialDriver
    {
        return $this->drivers[$key] ?? null;
    }

    /** key => label, for the admin select. */
    public function options(): array
    {
        return array_map(fn (SocialDriver $d) => $d->label(), $this->drivers);
    }
}
