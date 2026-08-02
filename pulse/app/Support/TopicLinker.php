<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class TopicLinker
{
    /**
     * Wrap the first mention of each of the post's topics in a link to that
     * topic hub, so summary/FAQ text routes readers deeper instead of dead-ending.
     *
     * Safe by construction: input is HTML-escaped first, only whole-word matches
     * of REAL tag names are linked (max once each), and anchors are spliced in
     * last via placeholders so nothing can nest or be double-matched.
     *
     * @param  \Illuminate\Support\Collection|iterable  $tags  the post's Tag models
     */
    public static function link(?string $text, $tags): HtmlString
    {
        $safe = e((string) $text);

        $tags = collect($tags);
        if ($safe === '' || $tags->isEmpty()) {
            return new HtmlString($safe);
        }

        // Longest names first so "Supreme Court" wins over "Court".
        $sorted = $tags->sortByDesc(fn ($t) => mb_strlen((string) $t->name));

        $tokens = [];
        $i = 0;
        foreach ($sorted as $tag) {
            $name = trim((string) $tag->name);
            if ($name === '') {
                continue;
            }

            $escaped = e($name);
            $pattern = '/\b(' . preg_quote($escaped, '/') . ')\b/iu';
            $token = "\x00TL{$i}\x00";

            $safe = preg_replace($pattern, $token, $safe, 1, $count);
            if ($count > 0) {
                $tokens[$token] = '<a href="' . e(route('topic.show', $tag)) . '" class="content-link">' . $escaped . '</a>';
                $i++;
            }
        }

        return new HtmlString($tokens ? strtr($safe, $tokens) : $safe);
    }
}
