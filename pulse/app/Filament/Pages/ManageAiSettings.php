<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ManageAiSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'AI & Ads Settings';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.manage-ai-settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_settings') ?? false;
    }

    public ?array $data = [];

    /** Setting keys this page manages, with sensible defaults. */
    private const KEYS = [
        'openai_key' => null,
        'openai_model' => 'gpt-4o-mini',
        'openai_image_model' => 'dall-e-3',
        'ai_instructions' => null,
        'editorial_stance' => null,
        'ai_moderation_enabled' => true,
        'comment_rules' => null,
        'adsense_client' => null,
        'gsc_property' => null,
        'gsc_service_account' => null,
        'dedup_threshold' => '0.85',
        'ingest_max_age_hours' => '3',
        'min_publish_words' => '400',
        'publish_score_threshold' => '0',
        'daily_publish_cap' => '10',
        'publish_now_score' => '85',
        'promote_score_floor' => '70',
        'min_sources_to_publish' => '3',
        'max_sources_per_post' => '4',
        'draft_expire_hours' => '6',
        'web_research_enabled' => true,
        'web_search_provider' => 'brave',
        'web_search_key' => null,
        'web_search_cx' => null,
        'social_x' => null,
        'social_facebook' => null,
        'social_youtube' => null,
        'social_truth' => null,
        'social_telegram' => null,
        'push_interval_hours' => '2',
        'resend_key' => null,
        'mail_host' => null,
        'mail_port' => '587',
        'mail_username' => null,
        'mail_password' => null,
        'mail_encryption' => 'tls',
        'mail_from_address' => 'news@thetruedefender.news',
        'mail_from_name' => 'TheTrueDefender',
        'digest_enabled' => false,
    ];

    public function mount(): void
    {
        $state = [];
        foreach (self::KEYS as $key => $default) {
            $state[$key] = Setting::get($key, $default);
        }
        $state['ai_moderation_enabled'] = filter_var($state['ai_moderation_enabled'], FILTER_VALIDATE_BOOL);
        $state['digest_enabled'] = filter_var($state['digest_enabled'], FILTER_VALIDATE_BOOL);
        $state['web_research_enabled'] = filter_var($state['web_research_enabled'], FILTER_VALIDATE_BOOL);
        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('OpenAI')
                ->description('Powers the AI news rewriting and image generation.')
                ->schema([
                    TextInput::make('openai_key')
                        ->label('API key')->password()->revealable()->autocomplete(false)
                        ->helperText('Stored securely. Leave blank to keep the pipeline in safe stub mode.'),
                    TextInput::make('openai_model')->label('Text model')->placeholder('gpt-4o-mini'),
                    TextInput::make('openai_image_model')->label('Image model')->placeholder('dall-e-3'),
                    Textarea::make('ai_instructions')
                        ->label('Teach the AI (house style & rules)')
                        ->rows(6)
                        ->helperText('Editorial guidance added to every rewrite — tone, angle, do/don\'t, formatting. e.g. "Write in a confident, patriotic voice. Keep it factual. Never speculate. End with a short takeaway."'),
                    Textarea::make('editorial_stance')
                        ->label('Editorial perspective (for the "Our Take" section)')
                        ->rows(3)
                        ->helperText('Your outlet\'s point of view. The clearly-labeled "Our Take" opinion section at the end of each story is written FROM this viewpoint — while every fact in the reporting stays accurate and grounded. e.g. "Conservative, pro-Trump, pro-Constitution, skeptical of federal overreach and legacy media." Leave blank for a neutral take.'),
                    TextInput::make('dedup_threshold')
                        ->label('Duplicate detection sensitivity')
                        ->numeric()->minValue(0.5)->maxValue(0.99)->step(0.01)
                        ->placeholder('0.85')
                        ->helperText('0.50–0.99. Stories from different feeds more similar than this are skipped as duplicates. Higher = stricter (fewer skips). Uses AI embeddings; falls back to title matching without an API key.'),
                    TextInput::make('ingest_max_age_hours')
                        ->label('Only import news newer than (hours)')
                        ->numeric()->minValue(0.5)->maxValue(72)->step(0.5)
                        ->placeholder('3')
                        ->helperText('Stories the source published longer ago than this are skipped, keeping the site fresh. e.g. 3 = only import articles from the last 3 hours.'),
                    TextInput::make('min_publish_words')
                        ->label('Minimum words to auto-publish')
                        ->numeric()->minValue(0)->maxValue(2000)->step(50)
                        ->placeholder('400')
                        ->helperText('Quality gate: articles shorter than this (or with no full source text) are held as DRAFTS instead of auto-published. Higher = stricter, fewer but deeper stories (better for AdSense). 0 disables the gate.'),
                    TextInput::make('publish_score_threshold')
                        ->label('AI editorial score to publish (0–100)')
                        ->numeric()->minValue(0)->maxValue(100)->step(1)
                        ->placeholder('0')
                        ->helperText('Before the costly rewrite + image, the AI scores each story 0–100 for how strongly it fits your audience. Auto-publish stories BELOW this bar are skipped (logged in the Ingest Log with the reason). Higher = fewer, stronger stories. Roughly: 0 = off, ~60 ≈ moderate, ~70 ≈ strict. Cuts both volume and cost.'),
                ])->columns(2),

            Section::make('Publishing & Daily Volume')
                ->description('Controls how many stories publish per day and which go out immediately vs. wait in the '
                    . 'queue. Fewer, stronger, multi-source posts is the goal — quality over volume.')
                ->schema([
                    TextInput::make('daily_publish_cap')
                        ->label('Max posts per day')
                        ->numeric()->minValue(0)->maxValue(100)->step(1)
                        ->placeholder('10')
                        ->helperText('Hard ceiling on auto-published posts per day (breaking news included). 10 is recommended. 0 = unlimited (not recommended).'),
                    TextInput::make('min_sources_to_publish')
                        ->label('Minimum sources to publish')
                        ->numeric()->minValue(1)->maxValue(10)->step(1)
                        ->placeholder('3')
                        ->helperText('A story may not publish until it has at least this many distinct sources (gathered by open-web research). 3 is recommended. 1 = allow single-source (not recommended for AdSense).'),
                    TextInput::make('max_sources_per_post')
                        ->label('Maximum sources to gather')
                        ->numeric()->minValue(1)->maxValue(12)->step(1)
                        ->placeholder('4')
                        ->helperText('Upper bound on how many outlets the research step pulls per story (cost control).'),
                    TextInput::make('publish_now_score')
                        ->label('Fast-track score (0–100)')
                        ->numeric()->minValue(0)->maxValue(100)->step(1)
                        ->placeholder('85')
                        ->helperText('Stories scoring at least this (or breaking) are researched and published AHEAD of the queue. Higher = only the biggest stories jump the line.'),
                    TextInput::make('promote_score_floor')
                        ->label('Queue quality floor (0–100)')
                        ->numeric()->minValue(0)->maxValue(100)->step(1)
                        ->placeholder('70')
                        ->helperText('A queued story must score at least this to be promoted into a daily slot; below it, it waits (and expires). Higher = fewer, stronger posts.'),
                    TextInput::make('draft_expire_hours')
                        ->label('Delete unpublished drafts after (hours)')
                        ->numeric()->minValue(1)->maxValue(48)->step(1)
                        ->placeholder('6')
                        ->helperText('Auto-ingested drafts that haven\'t been published within this many hours are deleted (news goes stale, so drafts don\'t pile up). Checked every ~30 min. Your own manual drafts are never auto-deleted.'),
                ])->columns(2),

            Section::make('Open-Web Research')
                ->description('Let the AI search the open web for OTHER outlets covering a story and synthesize their '
                    . 'reporting into the article — turning single-feed rewrites into multi-source, less-derivative pieces. '
                    . 'Breaking stories are researched immediately before publishing (so a single-source bombshell is '
                    . 'cross-checked first); other stories are researched shortly after. Needs a search API key. '
                    . 'Brave Search has a free tier that easily covers this (~1 search per published story).')
                ->schema([
                    Toggle::make('web_research_enabled')
                        ->label('Enable open-web research')
                        ->helperText('When on AND a key is set below, new stories are enriched with other outlets\' coverage.'),
                    Select::make('web_search_provider')
                        ->label('Search provider')
                        ->options(['brave' => 'Brave Search', 'google' => 'Google Programmable Search'])
                        ->default('brave')->native(false),
                    TextInput::make('web_search_key')
                        ->label('Search API key')->password()->revealable()->autocomplete(false)
                        ->helperText('Brave: your Subscription Token from api.search.brave.com. Google: your API key.'),
                    TextInput::make('web_search_cx')
                        ->label('Google search-engine ID (cx)')->autocomplete(false)
                        ->placeholder('Only for Google Programmable Search')
                        ->helperText('Leave blank for Brave.'),
                ])->columns(2),

            Section::make('Learned Hook Guide (automatic)')
                ->description('Updated weekly from your best-performing headlines (by click-through rate once '
                    . 'enough click data exists, otherwise by views). The article writer follows this automatically.')
                ->collapsed()
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('learned_style_guide_view')
                        ->hiddenLabel()
                        ->content(function () {
                            $guide = \App\Models\Setting::get('learned_style_guide');
                            if (blank($guide)) {
                                return new \Illuminate\Support\HtmlString('<em style="color:#9ca3af">Not generated yet — it builds automatically each week.</em>');
                            }
                            $signal = \App\Models\Setting::get('learned_style_signal', 'views');
                            $at = \App\Models\Setting::get('learned_style_at', '');
                            return new \Illuminate\Support\HtmlString(
                                '<div style="font-size:.8rem;color:#9ca3af;margin-bottom:8px">Based on <strong>' . e($signal) . '</strong>'
                                . ($at ? ' · updated ' . e($at) : '') . '</div>'
                                . '<div style="white-space:pre-wrap;line-height:1.6">' . e($guide) . '</div>'
                            );
                        }),
                ]),

            Section::make('Comment Moderation (AI)')
                ->description('When on, the AI reads each new comment against the rules: clean comments go live instantly, '
                    . 'clear violations are hidden, and borderline ones are held for your approval. If the AI is unavailable, '
                    . 'comments wait for manual approval.')
                ->schema([
                    Toggle::make('ai_moderation_enabled')->label('Auto-moderate comments with AI'),
                    Textarea::make('comment_rules')
                        ->label('Extra comment rules (optional)')
                        ->rows(4)
                        ->helperText('Added to the built-in rules (no hate/harassment/spam/explicit/illegal). '
                            . 'e.g. "No off-topic election-fraud claims. Keep it about the article. English only."'),
                ]),

            Section::make('Web Push Notifications')
                ->description('To avoid spamming subscribers, the site sends at most ONE push per interval, '
                    . 'choosing the most important recent story (Breaking → Top Story → Trending → newest).')
                ->schema([
                    TextInput::make('push_interval_hours')
                        ->label('Minimum hours between notifications')
                        ->numeric()->minValue(0.25)->maxValue(72)->step('0.25')->placeholder('2')
                        ->helperText('e.g. 2 = at most one push every 2 hours. Lower = more frequent.'),
                ]),

            Section::make('Email (Newsletter & Notifications)')
                ->description('Sends the daily digest and comment-reply notifications. '
                    . 'Easiest: paste a Resend API key below. Or use any SMTP provider '
                    . '(Postmark, Amazon SES, Mailgun, or your host\'s SMTP). '
                    . 'Leave everything blank and no email is sent.')
                ->schema([
                    Toggle::make('digest_enabled')
                        ->label('Send the daily "Top stories" digest to subscribers')
                        ->columnSpanFull(),
                    TextInput::make('resend_key')
                        ->label('Resend API key')
                        ->password()->revealable()->autocomplete(false)
                        ->placeholder('re_xxxxxxxxxxxxxxxxxxxx')
                        ->columnSpanFull()
                        ->helperText(new \Illuminate\Support\HtmlString(
                            'Recommended. Get it at <strong>resend.com → API Keys</strong>, then verify your '
                            . 'sending domain under <strong>Domains</strong> so the From address below is allowed. '
                            . 'When set, this takes priority over the SMTP fields.'
                        )),
                    TextInput::make('mail_host')->label('SMTP host')->placeholder('smtp.resend.com')
                        ->helperText('Only needed if you are NOT using the Resend API key above.'),
                    TextInput::make('mail_port')->label('SMTP port')->numeric()->placeholder('587'),
                    TextInput::make('mail_username')->label('SMTP username')->autocomplete(false),
                    TextInput::make('mail_password')->label('SMTP password / API key')->password()->revealable()->autocomplete(false),
                    TextInput::make('mail_encryption')->label('Encryption')->placeholder('tls'),
                    TextInput::make('mail_from_address')->label('From address')->email()->placeholder('news@thetruedefender.news'),
                    TextInput::make('mail_from_name')->label('From name')->placeholder('TheTrueDefender'),
                ])->columns(2),

            Section::make('Social Media Links (footer)')
                ->description('Your profile URLs. These power the social icons in the site footer. Leave blank to hide an icon.')
                ->schema([
                    TextInput::make('social_x')->label('X (Twitter)')->url()->placeholder('https://x.com/yourhandle'),
                    TextInput::make('social_facebook')->label('Facebook')->url()->placeholder('https://facebook.com/yourpage'),
                    TextInput::make('social_youtube')->label('YouTube')->url()->placeholder('https://youtube.com/@yourchannel'),
                    TextInput::make('social_truth')->label('Truth Social')->url()->placeholder('https://truthsocial.com/@yourhandle'),
                    TextInput::make('social_telegram')->label('Telegram')->url()->placeholder('https://t.me/yourchannel'),
                ])->columns(2),

            Section::make('Google AdSense')
                ->description('Global publisher ID. Manage individual ad positions under Ads → Ad Placements.')
                ->schema([
                    TextInput::make('adsense_client')
                        ->label('Publisher ID')
                        ->placeholder('ca-pub-XXXXXXXXXXXXXXXX'),
                ]),

            Section::make('Google Search Console')
                ->description('Powers real ranking data (avg position, clicks, impressions) shown per post & page. '
                    . 'Create a service account, paste its JSON key here, then add the service account email as a '
                    . 'user of your Search Console property. Pulled daily; run "php artisan seo:pull-rankings" to sync now.')
                ->schema([
                    TextInput::make('gsc_property')
                        ->label('Property')
                        ->placeholder('sc-domain:thetruedefender.com')
                        ->helperText('Domain property: sc-domain:yourdomain.com — or a URL-prefix property: https://yourdomain.com/'),
                    Textarea::make('gsc_service_account')
                        ->label('Service account JSON key')
                        ->rows(4)
                        ->autocomplete(false)
                        ->helperText('Paste the entire downloaded JSON. Stored in settings; leave blank to disable ranking sync.'),
                ]),


        ])->statePath('data');
    }

    public function save(): void
    {
        foreach ($this->form->getState() as $key => $value) {
            // Store booleans as '1'/'0' — an empty string would make Setting::get
            // fall back to the default, so a disabled toggle wouldn't stick.
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            Setting::put($key, $value);
        }

        Notification::make()->title('Settings saved')->success()->send();
    }
}
