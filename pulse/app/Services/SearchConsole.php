<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Google Search Console client.
 *
 * Authenticates with a Google service account (JWT → access token, RS256 signed
 * via openssl — no google/apiclient dependency) and reads Search Analytics so we
 * can show each page's real Google ranking (average position, clicks, impressions).
 *
 * Setup (see DEPLOY.md): create a service account, download its JSON key, paste it
 * in Admin → AI & Ads Settings, and add the service account's email as a user of the
 * Search Console property.
 */
class SearchConsole
{
    /** True when a service account + property are configured. */
    public function isConfigured(): bool
    {
        return filled($this->credentials()) && filled($this->property());
    }

    public function property(): ?string
    {
        // e.g. "sc-domain:example.com" or "https://example.com/"
        return Setting::get('gsc_property', config('services.gsc.property'));
    }

    private function credentials(): ?array
    {
        $json = Setting::get('gsc_service_account', config('services.gsc.service_account'));
        if (blank($json)) {
            return null;
        }
        $data = is_array($json) ? $json : json_decode($json, true);

        return (is_array($data) && isset($data['client_email'], $data['private_key'])) ? $data : null;
    }

    /**
     * Query Search Analytics grouped by page for the given window.
     *
     * @return array<int,array{page:string,clicks:int,impressions:int,ctr:float,position:float}>
     */
    public function pageMetrics(string $startDate, string $endDate, int $rowLimit = 1000): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $token = $this->accessToken();
        $site = rawurlencode($this->property());

        $response = Http::withToken($token)
            ->timeout(60)
            ->acceptJson()
            ->post("https://www.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query", [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => ['page'],
                'rowLimit' => $rowLimit,
            ])
            ->throw();

        return collect($response->json('rows', []))
            ->map(fn ($row) => [
                'page' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 2),
            ])
            ->all();
    }

    /** Exchange the service-account JWT for a short-lived OAuth access token. */
    private function accessToken(): string
    {
        $creds = $this->credentials();
        $now = time();

        $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->b64(json_encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = "{$header}.{$claims}";
        $signature = '';
        if (! openssl_sign($signingInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign Search Console JWT (check service-account private_key / OpenSSL).');
        }
        $jwt = "{$signingInput}." . $this->b64($signature);

        $token = Http::asForm()->timeout(30)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])
            ->throw()
            ->json('access_token');

        if (blank($token)) {
            throw new \RuntimeException('Google did not return an access token.');
        }

        return $token;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
