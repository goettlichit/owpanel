@php($area = \App\Support\Area::prefix())
<x-layouts.app :title="__('sites.cron.title').' · '.$site->display_domain">
    {{-- Shared by the administration and the customer area (E-144). --}}
    <x-slot:sidebar>
        @include($area === '' ? 'sites._site-menu' : 'my.sites._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('sites.cron.title')" :pretitle="$site->display_domain" />

    {{-- New job and info box on top, info box on the right (docs/DESIGN.md, "Info-Boxen"); the list below. --}}
    <div class="row row-cards align-items-start">
        <div class="col-lg-8">
            <form data-ow-busy="{{ __('tasks.busy.cron') }}" data-ow-task="site-cron" method="POST" action="{{ route($area.'sites.cron.store', $site) }}" novalidate>
                @csrf
                <x-card :title="__('sites.cron.create')">
                    @if ($blocked)
                        {{-- The package is used up: the form stays visible, but disabled (E-146). --}}
                        <div class="alert alert-warning mb-3" role="status">{{ $blocked }}</div>
                    @endif
                    <fieldset @disabled($blocked)>
                    <div class="mb-3">
                        <label class="form-label required" for="schedule">{{ __('sites.cron.fields.schedule') }}</label>
                        <div class="input-group">
                            <select class="form-select flex-grow-0 w-auto" aria-label="{{ __('sites.cron.preset') }}" data-ow-fill="schedule">
                                <option value="">{{ __('sites.cron.preset') }}</option>
                                {{-- Customers only get the presets their package allows; administrators see all, marked (E-139, E-144). --}}
                                @foreach (\App\Models\CronJob::PRESETS as $key => $value)
                                    @php($allowed = \App\Customers\PackageLimits::scheduleAllowed($site->customer?->package, $value))
                                    @if ($allowed || $area === '')
                                        <option value="{{ $value }}">{{ __('sites.cron.presets.'.$key) }}@unless ($allowed) – {{ __('packages.note.over') }}@endunless</option>
                                    @endif
                                @endforeach
                            </select>
                            <input type="text" id="schedule" name="schedule" value="{{ old('schedule', $area === '' ? '*/5 * * * *' : collect(\App\Models\CronJob::PRESETS)->first(fn ($value) => \App\Customers\PackageLimits::scheduleAllowed($site->customer?->package, $value))) }}" required spellcheck="false"
                                @class(['form-control font-monospace', 'is-invalid' => $errors->has('schedule')])>
                            @error('schedule')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-hint">{{ __('sites.cron.schedule_hint') }}</div>
                        @include('sites._package-note', ['kind' => 'cron'])
                    </div>
                    <div class="mb-3">
                        <label class="form-label required" for="command">{{ __('sites.cron.fields.command') }}</label>
                        <input type="text" id="command" name="command" value="{{ old('command') }}" required spellcheck="false" placeholder="php artisan schedule:run"
                            @class(['form-control font-monospace', 'is-invalid' => $errors->has('command')])>
                        @error('command')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-hint">{{ __('sites.cron.command_hint', ['user' => $site->system_user]) }}</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="description">{{ __('sites.cron.fields.description') }}</label>
                        <input type="text" id="description" name="description" value="{{ old('description') }}" maxlength="255"
                            @class(['form-control', 'is-invalid' => $errors->has('description')])>
                    </div>
                    </fieldset>
                    <x-slot:footer>
                        <button type="submit" class="btn btn-primary" @disabled($blocked)><x-icon name="plus" class="me-1" />{{ __('sites.cron.submit') }}</button>
                    </x-slot:footer>
                </x-card>
            </form>
        </div>

        <div class="col-lg-4">
            <x-card :title="__('sites.cron.about_title')" tone="info">
                <ul class="mb-0 ps-3">
                    @foreach (__('sites.cron.about') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>

        <div class="col-12">
            <x-card :title="__('sites.cron.list')" :flush="true">
                {{-- How the last transfer to the server went (E-088). --}}
                @if ($sync?->status === \App\Enums\TaskStatus::Failed)
                    <x-slot:actions>
                        <livewire:task-badge :task="$sync" :label="__('sites.cron.sync')" :key="$sync->id" />
                    </x-slot:actions>
                @endif

                @if ($jobs->isEmpty())
                    <div class="card-body text-secondary">{{ __('sites.cron.empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sites.cron.fields.schedule') }}</th>
                                    <th scope="col">{{ __('sites.cron.fields.command') }}</th>
                                    <th scope="col">{{ __('sites.cron.fields.state') }}</th>
                                    <th scope="col"><span class="visually-hidden">{{ __('sites.cron.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($jobs as $job)
                                    <tr @class(['text-secondary' => ! $job->enabled])>
                                        <td class="text-nowrap">
                                            <code>{{ $job->schedule }}</code>
                                            @if ($job->presetName())
                                                <div class="small text-secondary">{{ __('sites.cron.presets.'.$job->presetName()) }}</div>
                                            @endif
                                            @unless (\App\Customers\PackageLimits::scheduleAllowed($site->customer?->package, $job->schedule))
                                                <div class="small text-warning">{{ __('packages.note.over') }}</div>
                                            @endunless
                                        </td>
                                        <td>
                                            @if ($job->description)
                                                <div class="fw-medium">{{ $job->description }}</div>
                                            @endif
                                            <code class="text-break">{{ $job->command }}</code>
                                        </td>
                                        <td>
                                            @if ($job->enabled)
                                                <span class="badge bg-green-lt"><x-icon name="player-play" class="me-1" />{{ __('sites.cron.enabled') }}</span>
                                            @else
                                                <span class="badge bg-secondary-lt"><x-icon name="player-pause" class="me-1" />{{ __('sites.cron.disabled') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            {{-- A job beyond the package can only be deleted by the customer (E-139). --}}
                                            @php($locked = $area !== '' && ! \App\Customers\PackageLimits::scheduleAllowed($site->customer?->package, $job->schedule))
                                            @unless ($locked)
                                            <form data-ow-busy="{{ __('tasks.busy.cron') }}" data-ow-task="site-cron" method="POST" action="{{ route($area.'sites.cron.update', [$site, $job]) }}" class="d-inline">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="toggle" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    {{ $job->enabled ? __('sites.cron.disable') : __('sites.cron.enable') }}
                                                </button>
                                            </form>
                                            <details class="ow-inline-confirm d-inline-block">
                                                <summary class="btn btn-sm btn-outline-primary">{{ __('sites.cron.edit') }}</summary>
                                                <form data-ow-busy="{{ __('tasks.busy.cron') }}" data-ow-task="site-cron" method="POST" action="{{ route($area.'sites.cron.update', [$site, $job]) }}" class="mt-2 text-start" novalidate>
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="text" name="edit_schedule" value="{{ $job->schedule }}" class="form-control form-control-sm font-monospace mb-1" aria-label="{{ __('sites.cron.fields.schedule') }}" required>
                                                    <input type="text" name="edit_command" value="{{ $job->command }}" class="form-control form-control-sm font-monospace mb-1" aria-label="{{ __('sites.cron.fields.command') }}" required>
                                                    <input type="text" name="edit_description" value="{{ $job->description }}" class="form-control form-control-sm mb-1" aria-label="{{ __('sites.cron.fields.description') }}">
                                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('sites.cron.save') }}</button>
                                                </form>
                                            </details>
                                            @endunless
                                            <form data-ow-busy="{{ __('tasks.busy.cron') }}" data-ow-task="site-cron" method="POST" action="{{ route($area.'sites.cron.destroy', [$site, $job]) }}" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="{{ __('sites.cron.delete') }}" title="{{ __('sites.cron.delete') }}">
                                                    <x-icon name="trash" />
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <x-slot:footer>
                    <span class="text-secondary small">{{ __('sites.cron.footer', ['user' => $site->system_user, 'timezone' => $timezone]) }}
                        <a href="{{ route($area.'sites.logs', [$site, 'log' => 'cron']) }}">{{ __('sites.cron.output') }}</a></span>
                </x-slot:footer>
            </x-card>
        </div>
    </div>
</x-layouts.app>
