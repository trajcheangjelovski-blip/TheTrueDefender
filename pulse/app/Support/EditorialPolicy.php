<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The newsroom's permanent, version-controlled editorial policy.
 *
 * The rules live in resources/editorial/policy.md (in the repository, so they
 * survive restarts, deploys and model changes). Every AI path that writes,
 * rewrites, updates or summarizes an article loads them through forPrompt(), and
 * the version is recorded on each post and revision (posts.policy_version).
 * Critical rules are ALSO enforced in code by PublishGate and FactChecker, so
 * nothing depends on the model obeying the prompt alone.
 *
 * Changing the policy: edit policy.md AND bump the `version:` line at its top.
 */
class EditorialPolicy
{
    private static ?array $parsed = null;

    /** The policy version, read from the policy file's front matter. */
    public static function version(): string
    {
        return self::parsed()['version'];
    }

    /** The policy rules (without front matter). */
    public static function text(): string
    {
        return self::parsed()['text'];
    }

    /**
     * The policy as a prompt block. The admin "house style" setting is appended
     * as subordinate voice notes — it can shape tone, but never overrides the policy.
     */
    public static function forPrompt(bool $withHouseVoice = true): string
    {
        $block = "=== EDITORIAL POLICY v" . self::version() . " (mandatory; overrides everything below) ===\n" . self::text();

        if ($withHouseVoice && filled($house = Setting::get('ai_instructions'))) {
            $block .= "\n\n=== HOUSE VOICE NOTES (apply only where they do not conflict with the policy above) ===\n" . $house;
        }

        return $block;
    }

    /** @return array{version:string,text:string} */
    private static function parsed(): array
    {
        if (self::$parsed !== null) {
            return self::$parsed;
        }
        $raw = (string) @file_get_contents(resource_path('editorial/policy.md'));
        if ($raw === '') {
            // Fail loudly: writing without the policy is exactly what this class prevents.
            throw new \RuntimeException('Editorial policy file resources/editorial/policy.md is missing or empty.');
        }

        $version = 'unversioned';
        if (preg_match('/\A---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
            if (preg_match('/^version:\s*(\S+)/m', $m[1], $v)) {
                $version = $v[1];
            }
            $raw = substr($raw, strlen($m[0]));
        }

        return self::$parsed = ['version' => $version, 'text' => trim($raw)];
    }
}
