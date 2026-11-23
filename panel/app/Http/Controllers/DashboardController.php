<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        // Customers start in their own area (E-124).
        if ($request->user()->isCustomer()) {
            return redirect()->route('my.sites');
        }
        $admin = $request->user()->isAdmin();

        return view('dashboard.index', [
            'stats' => $admin ? $this->adminStats() : null,
            'servers' => $admin ? Server::orderBy('name')->get() : null,
            'expiring' => $admin ? $this->expiringCertificates() : collect(),
        ]);
    }

    /**
     * Certificates of websites and of the panel that expire within 20 days
     * or already have; normally certbot renews 30 days ahead (E-076).
     *
     * @return Collection<int, array{name: string, expires: Carbon, href: string}>
     */
    private function expiringCertificates(): Collection
    {
        $limit = now()->addDays(Site::CERTIFICATE_WARNING_DAYS);

        $sites = Site::where('tls', true)
            ->whereNotNull('certificate_expires_at')
            ->where('certificate_expires_at', '<', $limit)
            ->orderBy('certificate_expires_at')
            ->get()
            ->map(fn (Site $site) => [
                'name' => $site->display_domain,
                'expires' => $site->certificate_expires_at,
                'href' => route('sites.show', $site),
            ]);

        $panel = Server::whereNotNull('panel_certificate_expires_at')
            ->where('panel_certificate_expires_at', '<', $limit)
            ->get()
            ->map(fn (Server $server) => [
                'name' => __('dashboard.servers.panel_certificate'),
                'expires' => $server->panel_certificate_expires_at,
                'href' => route('servers.show', $server),
            ]);

        return $panel->concat($sites)->values();
    }

    /**
     * Key figures for administrators.
     *
     * @return array<string, int>
     */
    private function adminStats(): array
    {
        $since = now()->subDay();

        return [
            'users' => User::count(),
            'admins' => User::where('role', Role::Admin)->count(),
            'sites' => Site::count(),
            'sites_tls' => Site::where('tls', true)->count(),
            'databases' => Database::count(),
            'failed_logins' => AuditLog::where('action', 'auth.failed')->where('created_at', '>=', $since)->count(),
            'lockouts' => AuditLog::where('action', 'auth.lockout')->where('created_at', '>=', $since)->count(),
            'admins_without_2fa' => User::where('role', Role::Admin)->whereNull('two_factor_confirmed_at')->count(),
        ];
    }
}
