<?php

namespace App\Domain\Scanning\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScannerEgress
{
    public function address(): ?string
    {
        $configured = trim((string) config('hackly.egress_ip', ''));

        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_IP) !== false) {
            return $configured;
        }

        if (app()->runningUnitTests()) {
            return null;
        }

        $cached = Cache::get('hackly.egress-ip');

        if (is_string($cached) && filter_var($cached, FILTER_VALIDATE_IP) !== false) {
            return $cached;
        }

        try {
            $ip = trim((string) Http::timeout(4)->get('https://api.ipify.org')->body());
        } catch (Throwable) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        Cache::put('hackly.egress-ip', $ip, now()->addDay());

        return $ip;
    }
}
