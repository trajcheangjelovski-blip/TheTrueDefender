<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\Tag;
use Illuminate\Http\Request;

class PushController extends Controller
{
    /** Expose the VAPID public key to the browser. */
    public function key()
    {
        return response()->json(['key' => config('webpush.vapid.public_key')]);
    }

    /** Store (or refresh) a browser push subscription. */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aesgcm',
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        PushSubscription::where('endpoint', $request->input('endpoint'))->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Sync the topics a push endpoint follows (account-free, keyed by endpoint).
     * The browser sends its full followed-topic list whenever it changes. Unknown
     * slugs are ignored. topics_only lets a reader narrow alerts to just these.
     */
    public function topics(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'topics' => ['array'],
            'topics.*' => ['string', 'max:80'],
            'topics_only' => ['nullable', 'boolean'],
        ]);

        $sub = PushSubscription::where('endpoint', $data['endpoint'])->first();
        if (! $sub) {
            return response()->json(['ok' => false, 'reason' => 'unknown_endpoint'], 404);
        }

        $tagIds = Tag::whereIn('slug', $data['topics'] ?? [])->pluck('id');
        $sub->tags()->sync($tagIds);

        if ($request->has('topics_only')) {
            $sub->update(['topics_only' => (bool) $data['topics_only']]);
        }

        return response()->json(['ok' => true, 'followed' => $tagIds->count()]);
    }
}
