<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI comment moderation: reads a submitted comment against the platform rules
 * and decides approve / reject / review.
 *
 * Fail-safe: any error, no API key, or moderation disabled → 'review' (held
 * for a human). We never auto-approve without a clean AI verdict.
 */
class CommentModerator
{
    public function enabled(): bool
    {
        return filter_var(Setting::get('ai_moderation_enabled', true), FILTER_VALIDATE_BOOL)
            && filled(Setting::get('openai_key', config('services.openai.key')));
    }

    /**
     * Moderate a comment and apply the verdict (status + reason + timestamp).
     *
     * @return string the resulting status
     */
    public function moderate(Comment $comment): string
    {
        [$decision, $reason] = $this->classify($comment->body);

        $status = match ($decision) {
            'approve' => 'approved',
            'reject' => 'rejected',
            default => 'pending', // review / unknown
        };

        $comment->forceFill([
            'status' => $status,
            'ai_reason' => $reason,
            'moderated_at' => now(),
        ])->save();

        return $status;
    }

    /** @return array{0:string,1:string} [decision, reason] */
    private function classify(string $body): array
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (! $this->enabled() || blank($key)) {
            return ['review', 'AI moderation unavailable — held for manual review.'];
        }

        $site = config('app.name', 'TheTrueDefender');
        $extra = Setting::get('comment_rules');

        $system = <<<SYS
        You moderate reader comments for "{$site}", a US news & opinion site that must
        stay advertiser-safe (Google AdSense). Decide ONE verdict for the comment:

        REJECT if it contains any of: hate speech or slurs targeting a protected group;
        harassment, threats, or incitement to violence; sexual/explicit content; spam,
        scams, or advertising/links to unrelated products; clearly illegal content;
        doxxing or sharing others' private information; relentless profanity/abuse.

        REVIEW if it is borderline, ambiguous, or you are unsure.

        APPROVE if it is a genuine opinion or discussion — EVEN IF it is strongly worded,
        harshly critical, sarcastic, or politically partisan. Robust debate and dissent
        are welcome; only reject actual rule violations, not unpopular opinions.

        Return {decision: approve|reject|review, reason: short explanation}.
        SYS;

        if (filled($extra)) {
            $system .= "\n\nAdditional house rules (follow strictly):\n" . $extra;
        }

        try {
            $response = Http::withToken(trim($key))
                ->timeout(30)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "COMMENT:\n" . $body],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'moderation',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'decision' => ['type' => 'string', 'enum' => ['approve', 'reject', 'review']],
                                    'reason' => ['type' => 'string'],
                                ],
                                'required' => ['decision', 'reason'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])
                ->throw();

            $data = json_decode(data_get($response->json(), 'choices.0.message.content', ''), true);

            if (! is_array($data) || ! in_array($data['decision'] ?? '', ['approve', 'reject', 'review'], true)) {
                return ['review', 'AI returned an unclear verdict — held for review.'];
            }

            return [$data['decision'], 'AI: ' . ($data['reason'] ?? '')];
        } catch (\Throwable $e) {
            Log::warning('Comment moderation failed: ' . $e->getMessage());

            return ['review', 'AI moderation failed — held for manual review.'];
        }
    }
}
