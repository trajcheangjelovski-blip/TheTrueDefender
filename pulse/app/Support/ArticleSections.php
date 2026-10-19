<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The one switchboard for the three optional article sections, and the code that
 * enforces it. Every writer, the Post model, PublishGate, the article page and the
 * RSS feed read these switches, so a prompt, a saved preference, a queue retry or
 * an older template cannot bring a disabled section back.
 *
 *   show_key_takeaways   (default OFF) — the posts.takeaways summary box
 *   show_our_take        (default OFF) — an "Our Take" opinion section in news
 *   show_sources_section (default OFF) — a standalone Sources/References list
 *
 * Research, fact-checking, internal source records (posts.sources) and natural
 * in-text attribution are NOT sections and are never affected.
 *
 * Removal is deliberately narrow so reporting is never lost: only an "Our Take"
 * heading with the opinion under it, or a Takeaways/Sources label with the list
 * directly under it, is removed. Anything else that looks like one of these
 * sections is reported by found() and blocks publication instead of being cut.
 */
class ArticleSections
{
    public const TAKEAWAYS = 'key-takeaways';

    public const OUR_TAKE = 'our-take';

    public const SOURCES = 'sources';

    /** Section labels, matched against a whole heading/label line (never prose). */
    private const LABELS = [
        self::TAKEAWAYS => '(?:(?:key|main|quick|top)\h+)?take-?aways?|key\h+points|at\h+a\h+glance|tl;?\h*dr',
        self::OUR_TAKE => "our\h+(?:take|view|perspective|opinion)|(?:the\h+)?editor(?:'|’)?s\h+(?:take|note\h+on\h+this)|editorial\h+take|the\h+defender(?:'|’)?s\h+take",
        self::SOURCES => '(?:(?:supporting|research|reporting)\h+)?(?:sources?|references?|bibliography|source\h+list|research\h+notes|further\h+reading)'
            . '(?:\h*(?:&|and)\h*(?:sources?|references?))?',
    ];

    public static function takeawaysEnabled(): bool
    {
        return self::on('show_key_takeaways');
    }

    public static function ourTakeEnabled(): bool
    {
        return self::on('show_our_take');
    }

    public static function sourcesEnabled(): bool
    {
        return self::on('show_sources_section');
    }

    /** Takeaways to store/render: always [] while the section is switched off. */
    public static function takeaways(mixed $takeaways): array
    {
        return self::takeawaysEnabled() && is_array($takeaways) ? array_values(array_filter($takeaways)) : [];
    }

    /**
     * The disabled sections still present in a body (after strip(), only forms
     * that could not be removed safely). Our Take is allowed in Opinion columns.
     *
     * @return array<int,string> section keys
     */
    public static function found(?string $body, bool $isOpinion = false): array
    {
        $body = (string) $body;
        $lines = self::lines($body);
        // Inline labels: "<p><strong>Our Take:</strong> We think…</p>" / "**Sources:** AP".
        preg_match_all('~<(?:p|div|li)\b[^>]*>\s*<(strong|b)\b[^>]*>(.*?)</\1>~is', $body, $m);
        $inline = $m[2] ?? [];
        foreach ($lines as $line) {
            if (preg_match('/^\h*(?:\*\*|__)(.+?)(?:\*\*|__)/u', $line, $mm)) {
                $inline[] = $mm[1];
            }
        }

        $found = [];
        foreach (self::disabled($isOpinion) as $key) {
            foreach (array_merge($lines, $inline) as $line) {
                if (self::isLabel(strip_tags($line), $key)) {
                    $found[] = $key;
                    break;
                }
            }
        }

        return $found;
    }

    /** Human message for PublishGate / validation. */
    public static function message(array $found): string
    {
        $names = [self::TAKEAWAYS => 'Key Takeaways', self::OUR_TAKE => 'Our Take', self::SOURCES => 'Sources/References'];

        return 'Article contains a disabled section (' . implode(', ', array_map(fn ($k) => $names[$k] ?? $k, $found))
            . '). These sections are switched off site-wide; keep the reporting and in-text attribution only.';
    }

    /**
     * Remove the safely-removable disabled sections. Returns the body unchanged
     * (byte for byte) when nothing is removed.
     */
    public static function strip(?string $body, bool $isOpinion = false): string
    {
        $body = (string) $body;
        $disabled = self::disabled($isOpinion);
        if ($disabled === [] || trim($body) === '' || ! self::found($body, $isOpinion)) {
            return $body;
        }

        return str_contains($body, '<')
            ? self::stripHtml($body, $disabled)
            : self::stripText($body, $disabled);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function on(string $key): bool
    {
        return (bool) (int) Setting::get($key, '0');
    }

    /** @return array<int,string> */
    private static function disabled(bool $isOpinion): array
    {
        return array_values(array_filter([
            self::takeawaysEnabled() ? null : self::TAKEAWAYS,
            (self::ourTakeEnabled() || $isOpinion) ? null : self::OUR_TAKE,
            self::sourcesEnabled() ? null : self::SOURCES,
        ]));
    }

    /** Is this single line nothing but a section label (heading / bold / Markdown / "Label:")? */
    private static function isLabel(string $line, string $key): bool
    {
        $line = trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Markdown/bold wrappers and a trailing colon or setext underline.
        $line = preg_replace('/^\h*(?:#{1,6}\h*)?(?:\*\*|__|\*|_)?\h*/u', '', $line);
        $line = preg_replace('/\h*(?:\*\*|__|\*|_)?\h*:?\h*(?:\*\*|__)?\h*#*\h*$/u', '', $line);
        $line = preg_replace('/^[\p{So}\p{Sk}\x{FE0F}\h]+/u', '', $line); // leading emoji (e.g. 📌)

        return $line !== '' && preg_match('/^(?:' . self::LABELS[$key] . ')$/iu', $line) === 1;
    }

    /** Block-level lines of an HTML/Markdown/plain body, wording kept. */
    private static function lines(string $body): array
    {
        $text = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~<(?:/?(?:h[1-6]|p|div|section|aside|ul|ol|li|header|footer)|br|hr)\b[^>]*>~i', "\n", $text);
        $text = strip_tags($text);

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), fn ($l) => $l !== '' && ! preg_match('/^[=\-]{3,}$/', $l)));
    }

    /** Which disabled section (if any) this whole element/line labels. */
    private static function labelKey(string $text, array $disabled): ?string
    {
        foreach ($disabled as $key) {
            if (self::isLabel($text, $key)) {
                return $key;
            }
        }

        return null;
    }

    /** Does this block open with a bold run that is exactly the label (e.g. <strong>Our Take:</strong>)? */
    private static function boldLead(\DOMElement $node, string $key): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText && trim($child->textContent) === '') {
                continue;
            }

            return $child instanceof \DOMElement && in_array(strtolower($child->nodeName), ['strong', 'b'], true)
                && self::isLabel($child->textContent, $key);
        }

        return false;
    }

    private static function stripHtml(string $body, array $disabled): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__as_root">' . $body . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__as_root');
        if (! $root) {
            return $body;
        }

        $nodes = iterator_to_array($root->childNodes);
        $remove = [];
        $isHeading = fn (\DOMNode $n) => $n instanceof \DOMElement && preg_match('/^h[1-6]$/i', $n->nodeName);
        $isBlank = fn (\DOMNode $n) => $n instanceof \DOMText && trim($n->textContent) === '';
        $isList = fn (\DOMNode $n) => $n instanceof \DOMElement && in_array(strtolower($n->nodeName), ['ul', 'ol'], true);

        for ($i = 0; $i < count($nodes); $i++) {
            $node = $nodes[$i];
            if (! $node instanceof \DOMElement || ! in_array(strtolower($node->nodeName), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'aside', 'section'], true)) {
                continue;
            }

            // An <aside>/<section> wrapper that IS the section (label + list inside).
            $key = self::labelKey($node->textContent, $disabled);
            if (! $key && in_array(strtolower($node->nodeName), ['aside', 'section', 'div'], true)) {
                $first = self::lines($doc->saveHTML($node))[0] ?? '';
                $inner = self::labelKey($first, $disabled);
                if ($inner && $inner !== self::OUR_TAKE && $node->getElementsByTagName('li')->length > 0
                    && $node->getElementsByTagName('p')->length === 0) {
                    $remove[] = $node;
                }
                continue;
            }
            // "<p><strong>Our Take:</strong> We think…</p>" opens the opinion section too.
            if (! $key && in_array(self::OUR_TAKE, $disabled, true) && self::boldLead($node, self::OUR_TAKE)) {
                $key = self::OUR_TAKE;
            }
            if (! $key) {
                continue;
            }

            if ($key === self::OUR_TAKE) {
                // Opinion under the label runs to the next heading (or the end).
                $remove[] = $node;
                for ($j = $i + 1; $j < count($nodes) && ! $isHeading($nodes[$j]); $j++) {
                    $remove[] = $nodes[$j];
                }
                $i = $j - 1;
                continue;
            }

            // Takeaways / Sources: the label plus ONLY the list(s) directly under it.
            $lists = [];
            for ($j = $i + 1; $j < count($nodes) && ($isBlank($nodes[$j]) || $isList($nodes[$j])); $j++) {
                $lists[] = $nodes[$j];
            }
            if (array_filter($lists, $isList)) {
                array_push($remove, $node, ...$lists);
                $i = $j - 1;
            }
            // No list → leave it; found() reports it and publication is blocked.
        }

        if (! $remove) {
            return $body;
        }
        foreach ($remove as $n) {
            if ($n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return trim($html);
    }

    /** Markdown / plain-text bodies: same rules, line based. */
    private static function stripText(string $body, array $disabled): string
    {
        $lines = preg_split('/\R/u', $body);
        $out = [];
        $changed = false;
        $isHeading = fn (string $l) => (bool) preg_match('/^\h*#{1,6}\h+\S/u', $l);
        $isItem = fn (string $l) => (bool) preg_match('/^\h*(?:[-*•]|\d+[.)])\h+\S/u', $l);

        for ($i = 0; $i < count($lines); $i++) {
            $key = trim($lines[$i]) !== '' ? self::labelKey($lines[$i], $disabled) : null;
            if (! $key && in_array(self::OUR_TAKE, $disabled, true)
                && preg_match('/^\h*(?:\*\*|__)(.+?)(?:\*\*|__)/u', $lines[$i], $mm) && self::isLabel($mm[1], self::OUR_TAKE)) {
                $key = self::OUR_TAKE; // "**Our Take:** We think…"
            }
            if (! $key) {
                $out[] = $lines[$i];
                continue;
            }
            $j = $i + 1;
            if (isset($lines[$j]) && preg_match('/^\h*[=\-]{3,}\h*$/', $lines[$j])) {
                $j++; // setext underline
            }
            if ($key === self::OUR_TAKE) {
                while ($j < count($lines) && ! $isHeading($lines[$j])) {
                    $j++;
                }
            } else {
                $k = $j;
                while ($k < count($lines) && (trim($lines[$k]) === '' || $isItem($lines[$k]))) {
                    $k++;
                }
                $hasItems = false;
                for ($m = $j; $m < $k; $m++) {
                    $hasItems = $hasItems || $isItem($lines[$m]);
                }
                if (! $hasItems) {
                    $out[] = $lines[$i];
                    continue;
                }
                $j = $k;
            }
            $changed = true;
            $i = $j - 1;
        }

        return $changed ? rtrim(implode("\n", $out)) : $body;
    }
}
