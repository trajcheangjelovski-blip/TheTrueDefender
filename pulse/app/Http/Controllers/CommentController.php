<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request, Post $post)
    {
        abort_unless($post->status === 'published' && $post->allow_comments, 404);

        // Honeypot: bots fill hidden fields humans never see.
        if (filled($request->input('website'))) {
            return back()->with('comment_status', 'Thanks — your comment has been submitted for review.')
                ->withFragment('comments');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'surname' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['required', 'string', 'max:40'],
            'body' => ['required', 'string', 'min:2', 'max:3000'],
            'parent_id' => ['nullable', 'integer'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Please confirm you agree to the comment terms.',
        ]);

        // Resolve the reply target — must be a comment on THIS post. Flatten
        // replies-to-replies onto the top-level parent (one visual level).
        $parentId = null;
        if (! empty($data['parent_id'])) {
            $parent = Comment::where('post_id', $post->id)->find($data['parent_id']);
            if ($parent) {
                $parentId = $parent->parent_id ?? $parent->id;
            }
        }

        $comment = Comment::create([
            'post_id' => $post->id,
            'parent_id' => $parentId,
            'name' => $data['name'],
            'surname' => $data['surname'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'body' => $data['body'],
            'status' => 'pending',
            'ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        // AI reads it against the platform rules: clean → live now, rule-breaking
        // → hidden, borderline → held for manual review.
        $status = app(\App\Services\CommentModerator::class)->moderate($comment);

        $message = match ($status) {
            'approved' => 'Thanks — your comment is now live!',
            'rejected' => 'Your comment could not be posted because it appears to break our comment rules.',
            default => 'Thanks — your comment has been submitted and will appear once approved.',
        };

        return back()->with('comment_status', $message)->withFragment('comments');
    }
}
