<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PageController extends Controller
{
    private const PAGES = ['about', 'contact', 'privacy', 'terms'];

    public function show(string $slug)
    {
        abort_unless(in_array($slug, self::PAGES, true), 404);

        return view("pages.$slug");
    }

    public function submitContact(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // Phase 3: persist to DB and/or email the newsroom.
        // For now we acknowledge receipt.

        return back()->with('status', 'Thanks — your message has been received. We read every message.');
    }
}
