<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What happens when an action breaks a package limit (E-125, E-139):
 *
 * - Customers are refused. Without a package they cannot create anything.
 * - Administrators see the broken limits and may go on after confirming
 *   (field "exceed_limits"). The page layout shows the confirmation
 *   (<x-limit-confirm>) and sends the same form again with the field set.
 *   Going beyond a limit is recorded in the audit log.
 */
trait ChecksPackageLimits
{
    /**
     * @param  Customer|null  $customer  Owner of the website; null for websites of the administration and unassigned ones, which have no limits.
     * @param  array<string, string>  $broken  From App\Customers\PackageLimits.
     * @param  array<string, mixed>  $context  For the audit log, e.g. the domain.
     * @return RedirectResponse|null Null when the action may go on.
     */
    protected function checkPackageLimits(Request $request, ?Customer $customer, array $broken, string $action, array $context = []): ?RedirectResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            if ($customer?->package === null) {
                $broken = ['package' => __('packages.limit_broken.no_package')];
            }

            return $broken === [] ? null : back()->withInput()->with('error', implode(' ', $broken));
        }

        if ($broken === []) {
            return null;
        }

        if (! $request->boolean('exceed_limits')) {
            return back()->withInput()->with('limit_confirm', [
                'url' => $request->url(),
                'method' => $request->method(),
                // Only plain form fields; nothing secret is sent by these forms.
                'input' => array_filter(
                    $request->except(['_token', '_method', 'exceed_limits']),
                    fn (mixed $value) => is_scalar($value) || $value === null,
                ),
                'broken' => array_values($broken),
            ]);
        }

        // Only IDs and limit names: no customer names in the audit log.
        Audit::record('package.limit_exceeded', $customer, $context + [
            'action' => $action,
            'limits' => array_keys($broken),
        ], $user);

        return null;
    }
}
