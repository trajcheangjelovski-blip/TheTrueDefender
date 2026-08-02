<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class TrackController extends Controller
{
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
