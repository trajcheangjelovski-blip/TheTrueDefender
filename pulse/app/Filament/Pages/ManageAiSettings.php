<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Section;
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
        'ai_moderation_enabled' => true,
        'comment_rules' => null,
        'adsense_client' => null,
        'gsc_property' => null,
        'gsc_service_account' => null,
        'dedup_threshold' => '0.85',
        'affiliate_rate_per_1000' => '6',
        'affiliate_share_pct' => '70',
        'affiliate_sale_commission_pct' => '10',
        'affiliate_cookie_days' => '30',
        'stripe_key' => null,
        'stripe_secret' => null,
        'stripe_webhook_secret' => null,
    ];

    public function mount(): void
    {
        $state = [];
        foreach (self::KEYS as $key => $default) {
            $state[$key] = Setting::get($key, $default);
        }
        $state['ai_moderation_enabled'] = filter_var($state['ai_moderation_enabled'], FILTER_VALIDATE_BOOL);
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
                    TextInput::make('dedup_threshold')
                        ->label('Duplicate detection sensitivity')
                        ->numeric()->minValue(0.5)->maxValue(0.99)->step(0.01)
                        ->placeholder('0.85')
                        ->helperText('0.50–0.99. Stories from different feeds more similar than this are skipped as duplicates. Higher = stricter (fewer skips). Uses AI embeddings; falls back to title matching without an API key.'),
                ])->columns(2),

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

            Section::make('Payments (Stripe)')
                ->description('Card payments with an on-page card form (Stripe Elements — card data goes straight to Stripe, '
                    . 'never your server). Add the publishable + secret keys to enable; leave blank for cash-on-delivery. '
                    . 'Optionally add a webhook to /stripe/webhook ("payment_intent.succeeded") for the most reliable confirmation.')
                ->schema([
                    TextInput::make('stripe_key')
                        ->label('Publishable key')->autocomplete(false)
                        ->placeholder('pk_live_… or pk_test_…'),
                    TextInput::make('stripe_secret')
                        ->label('Secret key')->password()->revealable()->autocomplete(false)
                        ->placeholder('sk_live_… or sk_test_…'),
                    TextInput::make('stripe_webhook_secret')
                        ->label('Webhook signing secret (optional)')->password()->revealable()->autocomplete(false)
                        ->placeholder('whsec_…'),
                ])->columns(2),

            Section::make('Affiliate Program')
                ->description('Global rates for affiliates. You can override each rate per affiliate on their record. '
                    . 'Affiliates are paid for referred VISITS (never ad clicks) plus a commission on referred shop orders.')
                ->schema([
                    TextInput::make('affiliate_rate_per_1000')
                        ->label('Your RPM ($ you earn per 1000 visits)')
                        ->numeric()->step('0.01')->placeholder('6')
                        ->helperText('Fully custom — set any value, any time. Tip: match your real AdSense RPM (AdSense → Reports). Changes apply to NEW visits only; already-earned clicks keep the rate they were earned at.'),
                    TextInput::make('affiliate_share_pct')
                        ->label('Affiliate share %')
                        ->numeric()->minValue(0)->maxValue(100)->placeholder('70')
                        ->helperText('e.g. 70 → affiliates get $4.20 per 1000 valid visits at $6 RPM. Changes apply to new visits only.'),
                    TextInput::make('affiliate_sale_commission_pct')
                        ->label('Shop sale commission %')
                        ->numeric()->minValue(0)->maxValue(100)->placeholder('10')
                        ->helperText('% of the order total for referred sales.'),
                    TextInput::make('affiliate_cookie_days')
                        ->label('Attribution window (days)')
                        ->numeric()->minValue(1)->maxValue(365)->placeholder('30')
                        ->helperText('How long after a referred visit a shop order still credits the affiliate.'),
                ])->columns(2),
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
