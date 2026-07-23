<?php

namespace App\Observers;

use App\Mail\ReplyNotification;
use App\Models\Comment;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CommentObserver
{
    /** Email the parent commenter when their comment gets an approved reply. */
    public function saved(Comment $comment): void
    {
        if ($comment->status !== 'approved' || ! $comment->parent_id || $comment->reply_notified_at !== null) {
            return;
        }

        // saveQuietly() below avoids re-triggering this observer.
        $parent = Comment::find($comment->parent_id);

        // Nothing to do if there's no parent, no email, or the person replied to themselves.
        if (! $parent || blank($parent->email) || strcasecmp($parent->email, (string) $comment->email) === 0) {
            $comment->forceFill(['reply_notified_at' => now()])->saveQuietly();
            return;
        }

        if (blank(Setting::get('mail_host'))) {
            return; // email not configured yet — leave unmarked so it sends once set up
        }

        try {
            $post = $comment->post;
            Mail::to($parent->email)->send(new ReplyNotification(
                $comment, $parent,
                $post?->title ?? 'a story',
                $post ? route('post.show', $post) . '#comments' : url('/'),
            ));
        } catch (\Throwable $e) {
            Log::warning('Reply notification failed: ' . $e->getMessage());
        }

        $comment->forceFill(['reply_notified_at' => now()])->saveQuietly();
    }
}
