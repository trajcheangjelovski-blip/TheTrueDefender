<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\Setting;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PageController extends Controller
{
    public function unsubscribe(Subscriber $subscriber)
    {
        $subscriber->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);

        return view('pages.unsubscribed', ['email' => $subscriber->email]);
    }

    private const PAGES = ['about', 'contact', 'privacy', 'terms', 'editorial-standards', 'corrections', 'newsroom'];

    public function show(string $slug)
    {
        abort_unless(in_array($slug, self::PAGES, true), 404);

        return view("pages.$slug");
    }

    public function submitContact(Request $request)
    {
        $data = $request->validate([
            'topic' => ['required', 'in:' . implode(',', array_keys(ContactMessage::TOPICS))],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // Bots fill the hidden field; pretend success and keep nothing.
        if (filled($request->input('website'))) {
            return back()->with('status', 'Thanks — your message has been received.');
        }

        $msg = ContactMessage::create($data + ['ip_hash' => hash('sha256', (string) $request->ip())]);

        // Forward to the newsroom inbox (an internal address, never shown on the site).
        // The message is already saved above, so a mail failure loses nothing.
        try {
            $to = Setting::get('contact_recipient', config('mail.from.address'));
            if (filled($to)) {
                $label = ContactMessage::TOPICS[$msg->topic];
                Mail::raw(
                    "Topic: {$label}\nFrom: {$msg->name} <{$msg->email}>\nSubject: {$msg->subject}\n\n{$msg->message}",
                    fn ($m) => $m->to($to)->replyTo($msg->email, $msg->name)->subject("[Contact: {$label}] {$msg->subject}"),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Contact message ' . $msg->id . ' saved but not forwarded: ' . $e->getMessage());
        }

        return back()->with('status', 'Thanks — your message has been received. We read every message.');
    }
}
