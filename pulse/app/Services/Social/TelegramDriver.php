<?php

namespace App\Services\Social;

use App\Models\Post;
use Illuminate\Support\Facades\Http;

class TelegramDriver extends AbstractSocialDriver
{
    public function key(): string { return 'telegram'; }

    public function label(): string { return 'Telegram'; }

    public function configFields(): array
    {
        return [
            'bot_token' => 'Bot token (from @BotFather)',
            'chat_id' => 'Channel/chat ID (e.g. @yourchannel or -100…)',
        ];
    }

    public function send(Post $post, array $config): array
    {
        if ($m = $this->missing($config, ['bot_token', 'chat_id'])) {
            return $this->fail($m);
        }

        try {
            $res = Http::timeout(30)->asJson()
                ->post("https://api.telegram.org/bot{$config['bot_token']}/sendMessage", [
                    'chat_id' => $config['chat_id'],
                    'text' => $this->text($post, 4000),
                    'disable_web_page_preview' => false,
                ])
                ->throw()
                ->json();

            $id = (string) data_get($res, 'result.message_id');
            return $this->ok($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
