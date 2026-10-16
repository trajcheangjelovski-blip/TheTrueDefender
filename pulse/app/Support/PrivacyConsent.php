<?php

namespace App\Support;

use Illuminate\Http\Request;

class PrivacyConsent
{
    public static function allows(Request $request, string $purpose): bool
    {
        $value = (string) $request->cookie('ttd_privacy', '');
        if (! preg_match('/^v2\.a([01])\.m([01])$/', $value, $matches)) {
            return false;
        }
        return match ($purpose) {
            'analytics' => $matches[1] === '1',
            'marketing' => $matches[2] === '1',
            default => false,
        };
    }
}
