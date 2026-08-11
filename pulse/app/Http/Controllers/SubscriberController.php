<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use App\Models\Tag;
use Illuminate\Http\Request;

class SubscriberController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'name' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:40'],
            'topics' => ['array'],
            'topics.*' => ['string', 'max:80'],
        ]);

        $subscriber = Subscriber::updateOrCreate(
            ['email' => strtolower($data['email'])],
            [
                'name' => $data['name'] ?? null,
                'source' => $data['source'] ?? 'footer',
                'status' => 'subscribed',
                'unsubscribed_at' => null,
            ],
        );

        // Capture any followed topics (for future segmented topic digests).
        if (! empty($data['topics'])) {
            $tagIds = Tag::whereIn('slug', $data['topics'])->pluck('id');
            $subscriber->tags()->syncWithoutDetaching($tagIds);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'You are subscribed!']);
        }

        return back()->with('status', 'Thanks for subscribing!');
    }
}
