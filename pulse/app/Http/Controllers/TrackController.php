<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Visit;
use Illuminate\Http\Request;

class TrackController extends Controller
{
    /**
     * Presence heartbeat — a reader's tab pings this while open so the admin
     * dashboard can show real-time active visitors. Anonymous: keyed only on a
     * first-party id the browser generates and keeps in localStorage.
     */
    public function heartbeat(Request $request)
    {
        $visitorId = (string) $request->input('vid', '');
        if ($visitorId === '' || strlen($visitorId) > 40) {
            return response()->noContent();
        }

        $device = (string) $request->input('device', '');
        $path = (string) $request->input('path', '');

        $visit = Visit::firstOrNew(['visitor_id' => $visitorId]);
        if (! $visit->exists) {
            $visit->created_at = now();
        }
        $visit->path = $path !== '' ? mb_substr($path, 0, 255) : null;
        $visit->device = in_array($device, ['mobile', 'tablet', 'desktop'], true) ? $device : null;
        $visit->last_seen_at = now();
        $visit->save();

        // Keep the table bounded: occasionally drop rows untouched for a week.
        if (random_int(1, 100) === 1) {
            Visit::where('last_seen_at', '<', now()->subDays(7))->delete();
        }

        return response()->noContent();
    }

    /** A headline was clicked from a list — record a click for hook CTR. */
    public function click(Request $request)
    {
        $slug = (string) $request->input('slug', '');
        if ($slug !== '') {
            Post::where('slug', $slug)->where('status', 'published')->increment('clicks');
        }

        return response()->noContent();
    }

    /** Headlines that scrolled into view — record impressions (one per unique slug). */
    public function impressions(Request $request)
    {
        $slugs = collect((array) $request->input('slugs', []))
            ->map(fn ($s) => (string) $s)->filter()->unique()->take(60)->values()->all();

        if (! empty($slugs)) {
            Post::whereIn('slug', $slugs)->where('status', 'published')->increment('impressions');
        }

        return response()->noContent();
    }
}
