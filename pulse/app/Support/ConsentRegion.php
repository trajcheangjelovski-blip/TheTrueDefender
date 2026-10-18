<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Does this visitor's region need prior consent before optional analytics or advertising cookies
 * or storage? Decided from Cloudflare's CF-IPCountry header. Countries of the European Economic
 * Area, the United Kingdom and Switzerland, and any visitor whose country is unknown (missing
 * header, Tor, local development), are treated as requiring consent. This is a conservative
 * product default, not a legal determination; the owner should confirm the list with counsel.
 */
class ConsentRegion
{
    public const REQUIRED = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT',
        'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',   // EU
        'IS', 'LI', 'NO',                                               // rest of the EEA
        'GB', 'CH',
    ];

    public static function required(?Request $request = null): bool
    {
        $request ??= request();
        $cc = strtoupper(trim((string) $request->header('CF-IPCountry', '')));
        if (strlen($cc) !== 2 || ! ctype_alpha($cc) || in_array($cc, ['XX', 'T1'], true)) {
            return true;   // unknown region: treat as consent-required
        }

        return in_array($cc, self::REQUIRED, true);
    }
}
