<?php

namespace App\Support;

class PublicDns
{
    public function addresses(string $host): array
    {
        return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    }
}
