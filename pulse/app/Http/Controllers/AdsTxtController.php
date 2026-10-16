<?php

namespace App\Http\Controllers;

use App\Models\Setting;

class AdsTxtController extends Controller
{
    /** Google's certification authority ID for AdSense (same for every publisher). */
    private const GOOGLE_CERT_ID = 'f08c47fec0942fa0';

    /**
     * /ads.txt, generated from the AdSense publisher ID in Settings (stored as
     * "ca-pub-…"), so it is correct as soon as the ID is saved in the admin — no
     * file to hand-edit. Without an ID it serves a comment-only placeholder.
     */
    public function __invoke()
    {
        $client = (string) Setting::get('adsense_client', config('services.adsense.client'));
        $pub = preg_match('/pub-\d{10,}/', $client, $m) ? $m[0] : null;

        $body = $pub
            ? 'google.com, ' . $pub . ', DIRECT, ' . self::GOOGLE_CERT_ID . "\n"
            : "# ads.txt — TheTrueDefender\n# Add your AdSense publisher ID in Admin → AI Settings → Google AdSense to publish it here.\n";

        return response($body, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
