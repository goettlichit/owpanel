<?php

namespace App\Http\Controllers;

use App\Customers\StorageQuota;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use App\Oidc\OidcSettings;
use App\Support\Audit;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customers of the hoster (E-084, E-085): name, company, email, language,
 * the panel's own customer number and the number in the external system,
 * nothing more (docs/SICHERHEIT.md, "Kundenbereich",
 * rule 6). Websites are assigned on the website page. Each customer has at
 * most one package with limits (E-125). Logins for customers and the API
 * for the homepage follow in milestone 7.
 *
 * The audit log stores only the customer ID, never name or email, so that
 * deleting a customer removes its personal data (rule 7).
 */
class CustomerController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Customer::class);

        return view('customers.index', [
            'customers' => Customer::with('package')->withCount('sites')->orderBy('number')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Customer::class);

        return view('customers.create', [
            'customer' => new Customer(['locale' => app()->getLocale()]),
            'packages' => Package::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $customer = new Customer($this->validated($request));
        $customer->package_id = $this->validatedPackage($request);
        $customer->save();

        Audit::record('customer.created', $customer, [], $request->user());
        if ($customer->package_id !== null) {
            Audit::record('customer.package_changed', $customer, ['old' => null, 'new' => $customer->package?->name], $request->user());
        }

        return redirect()->route('customers.show', $customer)
            ->with('status', __('customers.toast.created', ['name' => $customer->display_name, 'number' => $customer->display_number]));
    }

    /**
     * Overview of a customer: key figures and his websites. Master data,
     * package, access and deleting have their own pages in the customer's
     * left menu, like the pages of a website.
     */
    public function show(Customer $customer): View
    {
        Gate::authorize('view', $customer);

        return view('customers.show', [
            'customer' => $customer->load('package'),
            'sites' => $customer->sites()->with('server')->orderBy('domain')->get(),
            'usage' => $customer->usage(),
            'access' => $customer->users()->first(),
        ]);
    }

    public function details(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        return view('customers.details', ['customer' => $customer]);
    }

    public function package(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        $customer->load(['package', 'sites' => fn ($query) => $query->with('server')->orderBy('domain')]);

        return view('customers.package', [
            'customer' => $customer,
            'storage' => StorageQuota::summary(StorageQuota::usedBytes($customer), StorageQuota::limitBytes($customer)),
            'packages' => Package::orderBy('name')->get(),
            'usage' => $customer->usage(),
        ]);
    }

    public function access(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        $access = $customer->users()->first();

        return view('customers.access', [
            'customer' => $customer,
            'access' => $access,
            'mail' => MailSettings::configured(),
            // Pocket ID of the customer (E-138).
            'oidc' => OidcSettings::configured(),
            'pocketApi' => Setting::get(Setting::POCKET_ID_API_KEY) !== null,
            'identity' => $access?->oidcIdentities()->where('issuer', OidcSettings::issuer())->first(),
        ]);
    }

    public function confirmDelete(Customer $customer): View
    {
        Gate::authorize('view', $customer);

        return view('customers.delete', [
            'customer' => $customer,
            'hasSites' => $customer->sites()->exists(),
            'hasAccess' => $customer->users()->exists(),
        ]);
    }

    /**
     * Assign a package or none (E-125). Applies at once; a smaller package
     * deletes nothing, the customer page shows what is over the limit.
     */
    public function assignPackage(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $old = $customer->package?->name;
        $customer->package_id = $this->validatedPackage($request);
        if (! $customer->isDirty('package_id')) {
            return redirect()->route('customers.package.show', $customer);
        }
        $customer->save();
        $customer->load('package');

        Audit::record('customer.package_changed', $customer, ['old' => $old, 'new' => $customer->package?->name], $request->user());

        return redirect()->route('customers.package.show', $customer)->with('status', $customer->package === null
            ? __('customers.package.toast_removed')
            : __('customers.package.toast_assigned', ['name' => $customer->package->name]));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $customer->fill($this->validated($request, $customer))->save();

        Audit::record('customer.updated', $customer, [], $request->user());

        return redirect()->route('customers.details', $customer)->with('status', __('customers.toast.updated'));
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        // Confirmed with the panel's customer number, e.g. K10001.
        if (strtoupper(trim((string) $request->input('confirm_number'))) !== $customer->display_number) {
            return redirect()->route('customers.delete', $customer)->with('error', __('customers.delete.mismatch'));
        }

        Audit::record('customer.deleted', $customer, [], $request->user());
        $customer->delete();

        return redirect()->route('customers.index')->with('status', __('customers.toast.deleted'));
    }

    private function validatedPackage(Request $request): ?string
    {
        $validated = $request->validate(['package_id' => ['nullable', 'string', Rule::exists('packages', 'id')]]);

        return filled($validated['package_id'] ?? null) ? (string) $validated['package_id'] : null;
    }

    /**
     * @return array{first_name: string, last_name: string, company: string|null, email: string, locale: string, external_id: string|null}
     */
    private function validated(Request $request, ?Customer $customer = null): array
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'locale' => ['required', Rule::in(['de', 'en'])],
            'external_id' => ['nullable', 'string', 'max:64', Rule::unique('customers', 'external_id')->ignore($customer)],
        ]);

        return [
            'first_name' => trim($validated['first_name']),
            'last_name' => trim($validated['last_name']),
            'company' => filled($validated['company'] ?? null) ? trim($validated['company']) : null,
            'email' => Str::lower(trim($validated['email'])),
            'locale' => $validated['locale'],
            'external_id' => filled($validated['external_id'] ?? null) ? trim($validated['external_id']) : null,
        ];
    }
}
