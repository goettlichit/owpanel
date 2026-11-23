<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationEvents;
use App\Servers\ScriptRunner;
use App\Servers\SshScriptRunner;
use App\Support\MailSettings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Server scripts run over SSH (E-051). Tests swap in a fake.
        $this->app->bind(ScriptRunner::class, SshScriptRunner::class);
    }

    public function boot(): void
    {
        // At least 12 characters. No check against online leak databases:
        // the panel must not send password hashes to third parties.
        Password::defaults(fn () => Password::min(12)->max(255));

        Event::subscribe(RecordAuthenticationEvents::class);

        // SMTP server from System -> Settings (E-094); the workers pick up a
        // change before each job.
        MailSettings::apply();
        Queue::before(fn () => MailSettings::apply(fresh: true));

        // Tabler is based on Bootstrap 5.
        Paginator::useBootstrapFive();
    }
}
