{{--
    Package of a customer with what he has against its limits (E-125).
    Expects $customer (with package), $packages and $usage.
--}}
@php($exceeded = $customer->exceededLimits($usage))
<form method="POST" action="{{ route('customers.package', $customer) }}" novalidate>
    @csrf
    @method('PATCH')
    <x-card :title="__('customers.package.title')" :accent="$customer->package === null || $exceeded !== [] ? 'orange' : 'blue'">
        @if ($customer->package === null)
            <p class="text-secondary">
                <x-badge tone="warning">{{ __('customers.package.no_package') }}</x-badge>
                {{ __('customers.package.no_package_text') }}
            </p>
        @elseif ($exceeded !== [])
            <p class="text-secondary"><x-icon name="alert-triangle" class="me-1 text-orange" />{{ __('customers.package.over_text') }}</p>
        @endif

        @if ($packages->isEmpty())
            <p class="text-secondary mb-0">{{ __('customers.package.no_packages') }}
                @can('create', App\Models\Package::class)
                    <a href="{{ route('packages.create') }}">{{ __('customers.package.create_first') }}</a>
                @endcan
            </p>
        @else
            <label class="form-label" for="package_id">{{ __('customers.fields.package') }}</label>
            <select id="package_id" name="package_id" @class(['form-select', 'is-invalid' => $errors->has('package_id')])>
                <option value="">{{ __('customers.package.none') }}</option>
                @foreach ($packages as $package)
                    <option value="{{ $package->id }}" @selected(old('package_id', $customer->package_id) === $package->id)>{{ $package->name }}</option>
                @endforeach
            </select>
            @error('package_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        @endif

        @if ($customer->package !== null)
            <div class="table-responsive mt-3">
                <table class="table table-sm table-vcenter mb-0">
                    <thead>
                        <tr>
                            <th scope="col"><span class="visually-hidden">{{ __('customers.package.title') }}</span></th>
                            <th scope="col">{{ __('customers.package.used') }}</th>
                            <th scope="col">{{ __('customers.package.limit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (App\Models\Package::LIMITS as $limit)
                            <tr>
                                <th scope="row" class="fw-normal">{{ __('packages.fields.'.($limit === 'min_cron_interval' ? 'min_cron_interval_short' : ($limit === 'daily_self_service' ? 'daily_self_service_short' : $limit))) }}</th>
                                <td class="text-nowrap">
                                    @if (isset(App\Models\Package::COUNTED[$limit]))
                                        {{ $usage[App\Models\Package::COUNTED[$limit]] }}
                                        @if (in_array($limit, $exceeded, true))
                                            <x-badge tone="warning" class="ms-1">{{ __('customers.package.over') }}</x-badge>
                                        @endif
                                    @elseif ($limit === 'storage_mb')
                                        {{ $storage['used'] === null ? '–' : App\Support\Units::bytes($storage['used']) }}
                                        @if ($storage['state'] === 'full')
                                            <x-badge tone="danger" class="ms-1">{{ __('customers.package.full') }}</x-badge>
                                        @endif
                                    @else
                                        <span class="text-secondary">–</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">@include('packages._limit', ['package' => $customer->package, 'limit' => $limit])</td>
                            </tr>
                        @endforeach
                        {{-- PHP limits per website (E-173); nothing to count. --}}
                        @foreach (App\Models\Package::PHP_LIMITS as $limit)
                            <tr>
                                <th scope="row" class="fw-normal">{{ __('packages.fields.'.$limit) }}</th>
                                <td><span class="text-secondary">–</span></td>
                                <td class="text-nowrap">@include('packages._limit', ['package' => $customer->package, 'limit' => $limit])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($packages->isNotEmpty())
            <x-slot:footer>
                <button type="submit" class="btn btn-primary"><x-icon name="package" class="me-1" />{{ __('customers.package.submit') }}</button>
            </x-slot:footer>
        @endif
    </x-card>
</form>
