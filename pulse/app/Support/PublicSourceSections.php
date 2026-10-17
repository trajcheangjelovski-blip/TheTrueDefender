<?php

namespace App\Support;

/**
 * Detect standalone research-list labels without deleting any reporting.
 * Used by the writer's fix loop, publication guard and read-only content audit.
 * Inline attribution (including the ordinary word "sources") is not a section.
 */
class PublicSourceSections
{
    public const MESSAGE = 'Public articles must not contain a standalone Sources, References or Bibliography section. Keep research records internally and preserve necessary in-text attribution.';

    public static function hasSection(?string $body): bool
    {
        $text = html_entity_decode((string) $body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Convert block boundaries and emphasis to lines, retaining all wording.
        // This covers HTML headings, bold labels, Markdown, plain text and the
        // legacy rich editor's <div>/<p> blocks without matching prose mentions.
        $text = preg_replace('~<(?:/?(?:h[1-6]|p|div|section|ul|ol|li)|br|hr)\b[^>]*>~i', "\n", $text);
        $text = strip_tags($text);

        return (bool) preg_match(
            '/^\h*(?:\#{1,6}\h*)?(?:\*\*|__)?\h*(?:(?:supporting|research|reporting)\h+)?'
            . '(?:sources?|references?|bibliography|source\h+list|research\h+notes)'
            . '(?:\h*(?:&|and)\h*(?:sources?|references?))?\h*:?\h*(?:\*\*|__)?\h*:?\h*(?:\#{1,6})?\h*$/imu',
            $text,
        );
    }
}
