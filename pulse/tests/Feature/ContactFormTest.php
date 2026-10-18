<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return $over + ['topic' => 'correction', 'name' => 'Pat Reader', 'email' => 'pat@example.org',
            'subject' => 'Wrong date', 'message' => 'The article gives the wrong date for the vote.'];
    }

    public function test_the_form_saves_the_message_and_forwards_it_to_the_internal_inbox(): void
    {
        Mail::spy();
        Setting::put('contact_recipient', 'inbox@internal.test');

        $this->post('/contact', $this->payload())->assertRedirect()->assertSessionHas('status');

        $row = ContactMessage::firstOrFail();
        $this->assertSame('correction', $row->topic);
        $this->assertSame('pat@example.org', $row->email);
        $this->assertSame(64, strlen($row->ip_hash), 'only a hash of the IP is kept');
        Mail::shouldHaveReceived('raw')->once()->withArgs(fn ($text) => str_contains($text, 'wrong date for the vote') && str_contains($text, 'Correction'));
    }

    public function test_validation_rejects_bad_input_and_bots_leave_no_record(): void
    {
        $this->post('/contact', $this->payload(['email' => 'nope']))->assertSessionHasErrors('email');
        $this->post('/contact', $this->payload(['topic' => 'spam']))->assertSessionHasErrors('topic');
        $this->assertSame(0, ContactMessage::count());

        $this->post('/contact', $this->payload(['website' => 'http://bot.example']))->assertRedirect();
        $this->assertSame(0, ContactMessage::count(), 'honeypot submissions are discarded');
    }

    public function test_a_mail_failure_does_not_lose_the_message(): void
    {
        Setting::put('contact_recipient', 'inbox@internal.test');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $this->post('/contact', $this->payload())->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, ContactMessage::count());
    }

    public function test_the_public_site_shows_no_email_addresses_and_points_to_the_form(): void
    {
        User::factory()->create(['name' => 'Ada Writer', 'slug' => 'ada-writer', 'is_author' => true, 'public_email' => 'ada@thetruedefender.news']);

        foreach (['/', '/about', '/contact', '/corrections', '/editorial-standards', '/newsroom', '/privacy', '/terms', '/author/ada-writer'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('mailto:', $html, $url);
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._-]+@thetruedefender\.news/', $html, $url);
        }
        foreach (['/about', '/corrections', '/editorial-standards', '/newsroom', '/privacy'] as $url) {
            $this->get($url)->assertSee('/contact#contact-form', false);
        }
        $this->get('/contact')->assertSee('id="contact-form"', false)->assertSee('Privacy request');
    }
}
