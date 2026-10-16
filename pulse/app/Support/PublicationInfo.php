<?php

namespace App\Support;

use App\Models\Setting;

class PublicationInfo
{
    public static function newsletterAvailable(): bool
    {
        return filter_var(Setting::get('digest_enabled', false), FILTER_VALIDATE_BOOL)
            && in_array(config('mail.default'), ['smtp', 'resend'], true)
            && filled(config('mail.from.address'));
    }

    public static function publisher(): array
    {
        return [
            'name' => trim((string) Setting::get('publisher_name', '')),
            'editor' => trim((string) Setting::get('responsible_editor', '')),
            'contact' => trim((string) Setting::get('publisher_contact', '')),
        ];
    }
}
