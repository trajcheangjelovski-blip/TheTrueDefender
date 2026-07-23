<?php

namespace App\Http\Controllers;

use App\Models\PollOption;
use Illuminate\Http\Request;

class PollController extends Controller
{
    public function vote(Request $request)
    {
        $data = $request->validate([
            'poll_id' => ['required', 'integer'],
            'option_id' => ['required', 'integer'],
        ]);

        $cookie = 'poll_voted_' . $data['poll_id'];
        $option = PollOption::where('poll_id', $data['poll_id'])->find($data['option_id']);

        // One vote per browser (cookie-guarded).
        if ($option && ! $request->cookie($cookie)) {
            $option->increment('votes');
        }

        return back()
            ->withCookie(cookie($cookie, (string) ($option?->id ?? 1), 60 * 24 * 30))
            ->withFragment('poll');
    }
}
