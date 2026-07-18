<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Illuminate\Http\Request;

class SubscriberController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'name' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:40'],
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

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'You are subscribed!']);
        }

        return back()->with('status', 'Thanks for subscribing!');
    }
}
