@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="__('databases.title').' · '.$site->display_domain">
    {{-- Shared by the administration and the customer area (E-144). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('databases.title')" :pretitle="$site->display_domain" />

    {{-- Info box top right (docs/DESIGN.md, "Info-Boxen"), also without the form. --}}
    <div class="row row-cards align-items-start">
        @can('create', [\App\Models\Database::class, $site])
            <div class="col-lg-8">
                <form data-ow-busy="{{ __('tasks.busy.db_create') }}" data-ow-task="db-create" method="POST" action="{{ route($area.'databases.store', $site) }}" novalidate>
                    @csrf
                    <x-card :title="__('databases.form.title')">
                        @if ($blocked)
                            {{-- The package is used up: the form stays visible, but disabled (E-146). --}}
                            <div class="alert alert-warning mb-3" role="status">{{ $blocked }}</div>
                        @endif
                        <fieldset @disabled($blocked)>
                        <label class="form-label required" for="suffix">{{ __('databases.form.name') }}</label>
                        <div @class(['input-group', 'has-validation'])>
                            <span class="input-group-text"><code>{{ $site->system_user }}_</code></span>
                            <input type="text" id="suffix" name="suffix" value="{{ old('suffix') }}" required maxlength="16"
                                autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="shop" aria-describedby="suffix-hint"
                                @class(['form-control', 'is-invalid' => $errors->has('suffix')])>
                            @error('suffix')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-hint" id="suffix-hint">{{ __('databases.form.name_hint') }}</div>
                        @include('sites._package-note', ['kind' => 'databases'])
                        </fieldset>

                        <x-slot:footer>
                            <button type="submit" class="btn btn-primary" @disabled($site->status !== \App\Enums\SiteStatus::Active || $blocked)>
                                <x-icon name="plus" class="me-1" />{{ __('databases.form.submit') }}
                            </button>
                        </x-slot:footer>
                    </x-card>
                </form>
            </div>
        @endcan

        <div class="col-lg-4 ms-auto">
            <x-card :title="__('databases.about.title')" tone="info">
                <p>{{ __('databases.about.same_name') }}</p>
                <p>{{ __('databases.about.host', ['port' => \App\Models\Database::PORT]) }}</p>
                <p>{{ __('databases.about.password') }}</p>
                <p class="mb-0">{{ __('databases.about.site_delete') }}</p>
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('databases.list_title')" :flush="true">
                @if ($databases->isEmpty())
                    <div class="card-body text-secondary">{{ __('databases.empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('databases.columns.name') }}</th>
                                    <th scope="col">{{ __('databases.columns.status') }}</th>
                                    <th scope="col">{{ __('databases.columns.created') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($databases as $database)
                                    <tr>
                                        <td><a href="{{ route($area.'databases.show', $database) }}"><code>{{ $database->name }}</code></a></td>
                                        <td><x-status-badge :status="$database->status->value" /></td>
                                        <td class="text-nowrap"><x-time :value="$database->created_at" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
