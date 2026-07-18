<?php

namespace App\Http\Middleware;

use App\Models\Affiliate;
use App\Services\AffiliateProgram;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Detects ?ref=CODE on any public page, records the referred visit, and
 * drops the attribution cookie so a later shop order credits the affiliate.
 */
class TrackAffiliate
{
    public function __construct(private AffiliateProgram $program) {}

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->query('ref');

        if (is_string($code) && $code !== ''
            && $request->isMethod('GET')
            && ! $request->is('admin*')
            && ! $request->is('affiliate*')) {

            $affiliate = Affiliate::where('code', strtoupper(trim($code)))
                ->where('status', 'active')
                ->first();

            if ($affiliate) {
                $this->program->recordClick($request, $affiliate);
                Cookie::queue(AffiliateProgram::COOKIE, $affiliate->code, $this->program->cookieMinutes());
            }
        }

        return $next($request);
    }
}
