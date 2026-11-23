<?php

namespace Tests\Feature;

use App\Mail\TestMail;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\MailSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Outgoing mail and password reset by mail (E-094).
 */
class MailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function configure(): void
    {
        MailSettings::save([
            'host' => 'smtp.example.de', 'port' => 587, 'encryption' => 'starttls', 'username' => 'panel',
            'from_address' => 'panel@example.de', 'from_name' => 'Panel',
        ], 'smtp-secret');
        MailSettings::apply(fresh: true);
    }

    public function test_the_settings_page_opens_without_a_password_prompt(): void
    {
        $this->actingAs($this->admin())->get('/settings')->assertOk();
    }

    public function test_mail_settings_are_saved_with_an_encrypted_password_and_applied(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/mail', [
            'mail_host' => 'smtp.example.de', 'mail_port' => 465, 'mail_encryption' => 'tls',
            'mail_username' => 'panel@example.de', 'mail_password' => 'smtp-secret',
            'mail_from_address' => 'panel@example.de', 'mail_from_name' => 'goettlichIT',
        ])->assertRedirect('/settings/mail')->assertSessionHas('status');

        $this->assertTrue(MailSettings::configured());
        $stored = Setting::get(Setting::MAIL_PASSWORD);
        $this->assertNotSame('smtp-secret', $stored);
        $this->assertSame('smtp-secret', Crypt::decryptString((string) $stored));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('goettlichIT', config('mail.from.name'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.mail_settings_changed']);

        // An empty password keeps the stored one; the page never shows it.
        $this->actingAs($admin)->put('/settings/mail', [
            'mail_host' => 'smtp.example.de', 'mail_port' => 587, 'mail_encryption' => 'starttls',
            'mail_from_address' => 'panel@example.de',
        ]);
        $this->assertSame('smtp-secret', Crypt::decryptString((string) Setting::get(Setting::MAIL_PASSWORD)));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/mail')
            ->assertOk()->assertDontSee('smtp-secret')->assertSee('gespeichert, leer lassen zum Behalten');

        // An empty server turns mail off and forgets everything.
        $this->actingAs($admin)->put('/settings/mail', ['mail_host' => '', 'mail_encryption' => 'starttls'])->assertSessionHas('status');
        $this->assertFalse(MailSettings::configured());
        $this->assertNull(Setting::get(Setting::MAIL_PASSWORD));
        $this->assertSame('log', config('mail.default'));
    }

    public function test_invalid_mail_settings_are_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings/mail', ['mail_host' => 'smtp example', 'mail_port' => 587, 'mail_encryption' => 'starttls', 'mail_from_address' => 'a@b.de'])
            ->assertSessionHasErrors('mail_host');
        $this->actingAs($admin)->put('/settings/mail', ['mail_host' => 'smtp.example.de', 'mail_port' => 70000, 'mail_encryption' => 'starttls', 'mail_from_address' => 'a@b.de'])
            ->assertSessionHasErrors('mail_port');
        $this->actingAs($admin)->put('/settings/mail', ['mail_host' => 'smtp.example.de', 'mail_port' => 587, 'mail_encryption' => 'ssl3', 'mail_from_address' => 'a@b.de'])
            ->assertSessionHasErrors('mail_encryption');
        $this->actingAs($admin)->put('/settings/mail', ['mail_host' => 'smtp.example.de', 'mail_port' => 587, 'mail_encryption' => 'starttls'])
            ->assertSessionHasErrors('mail_from_address');
        $this->assertFalse(MailSettings::configured());
    }

    public function test_a_test_mail_goes_to_the_own_address(): void
    {
        Mail::fake();
        $this->configure();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/settings/mail/test')->assertRedirect('/settings/mail')->assertSessionHas('status');

        Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo($admin->email));
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.test_mail_sent']);

        // More than five a minute: a message instead of an error page.
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($admin)->post('/settings/mail/test');
        }
        $this->actingAs($admin)->post('/settings/mail/test')->assertRedirect('/settings/mail')->assertSessionHas('error');
        Mail::assertSentCount(5);
    }

    public function test_customers_cannot_change_mail_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/mail', ['mail_host' => 'evil.example', 'mail_port' => 25, 'mail_encryption' => 'none', 'mail_from_address' => 'a@evil.example'])->assertForbidden();
        $this->actingAs($user)->post('/settings/mail/test')->assertForbidden();
        $this->assertFalse(MailSettings::configured());
    }

    public function test_without_mail_there_is_no_password_reset(): void
    {
        $this->get('/login')->assertDontSee('Passwort vergessen?');
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'a@example.de'])->assertNotFound();
        $this->get('/reset-password/token')->assertNotFound();
    }

    public function test_a_reset_link_is_sent_in_the_language_of_the_account(): void
    {
        Notification::fake();
        $this->configure();
        $user = User::factory()->create(['email' => 'anna@example.de', 'locale' => 'en']);

        $this->get('/login')->assertSee('Passwort vergessen?');
        $this->get('/forgot-password')->assertOk();
        $this->post('/forgot-password', ['email' => 'anna@example.de'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', __('auth.forgot.sent'));

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
            $this->assertSame('en', $notification->locale);
            $mail = $notification->toMail($user);
            $this->assertStringContainsString('/reset-password/', $mail->actionUrl);

            return true;
        });
    }

    public function test_the_answer_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $this->configure();

        $this->post('/forgot-password', ['email' => 'nobody@example.de'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', __('auth.forgot.sent'))
            ->assertSessionHasNoErrors();
        Notification::assertNothingSent();
    }

    public function test_requests_for_links_are_limited(): void
    {
        Notification::fake();
        $this->configure();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => "user{$i}@example.de"])->assertSessionHasNoErrors();
        }
        $this->post('/forgot-password', ['email' => 'user9@example.de'])->assertSessionHasErrors('email');
    }

    public function test_the_password_can_be_reset_and_two_factor_stays(): void
    {
        $this->configure();
        $admin = $this->admin();
        $admin->forceFill(['password_change_required' => true])->save();
        $token = Password::broker()->createToken($admin);

        $this->get("/reset-password/{$token}?email=".urlencode($admin->email))->assertOk()->assertSee('Neues Passwort');
        $this->post('/reset-password', [
            'token' => $token, 'email' => $admin->email,
            'password' => 'ein-neues-passwort-123', 'password_confirmation' => 'ein-neues-passwort-123',
        ])->assertRedirect('/login');

        $admin->refresh();
        $this->assertTrue(Hash::check('ein-neues-passwort-123', $admin->password));
        $this->assertFalse($admin->password_change_required);
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset')->count());

        // The link works only once, and sign-in still asks for the code.
        $this->post('/reset-password', [
            'token' => $token, 'email' => $admin->email,
            'password' => 'noch-ein-passwort-123', 'password_confirmation' => 'noch-ein-passwort-123',
        ])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $admin->email, 'password' => 'ein-neues-passwort-123'])
            ->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
    }

    public function test_the_reset_notification_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new ResetPasswordNotification('x'));
        $this->assertInstanceOf(ResetPassword::class, new ResetPasswordNotification('x'));
    }
}
