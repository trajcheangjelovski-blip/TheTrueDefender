<?php

namespace App\Http\Controllers;

use App\Models\User;

class AuthorController extends Controller
{
    /**
     * Public author page: the person's bio/credentials plus every article they
     * have published. Only users explicitly flagged as public authors are
     * reachable here; admin-only accounts 404.
     */
    public function show(User $user)
    {
        abort_unless($user->isPublicAuthor(), 404);

        $posts = $user->posts()->published()
            ->with('category', 'author')
            ->latest('published_at')
            ->paginate(12);

        return view('authors.show', [
            'author' => $user,
            'posts' => $posts,
        ]);
    }
}
