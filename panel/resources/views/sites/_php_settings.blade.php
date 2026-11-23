{{--
    PHP settings of a website (E-173), next to the PHP version. Needs $site,
    $busy and $action (the route of the form). Bounds come from the package
    of the customer; a customer without a package sees the values only.
--}}
@php
    $bounds = \App\Sites\PhpSettings::bounds($site);
    $effective = \App\Sites\PhpSettings::effective($site);
    $locked = ! auth()->user()->isAdmin() && ! \App\Sites\PhpSettings::customerMayChange($site);
    $fields = [
        'memory' => ['php_memory_mb', 'MB'],
        'upload' => ['php_upload_mb', 'MB'],
        'time' => ['php_time_s', 's'],
    ];
@endphp
<div class="col-12">
    <form data-ow-busy="{{ __('tasks.busy.php') }}" data-ow-task="site-create" method="POST" action="{{ $action }}" class="h-100" novalidate>
        @csrf
        <x-card :title="__('sites.php_settings.title')" class="h-100">
            <p class="text-secondary">{{ __('sites.php_settings.text') }}</p>
            <div class="row">
                @foreach ($fields as $key => [$column, $unit])
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="php-{{ $key }}">{{ __('sites.php_settings.'.$key) }}</label>
                        <div class="input-group">
                            <input type="number" id="php-{{ $key }}" name="{{ $key }}" value="{{ old($key, $effective[$key]) }}"
                                min="{{ $bounds[$key]['min'] }}" max="{{ $bounds[$key]['max'] }}" step="1" inputmode="numeric" required
                                aria-describedby="php-{{ $key }}-hint" @disabled($locked)
                                @class(['form-control', 'is-invalid' => $errors->has($key)])>
                            <span class="input-group-text">{{ $unit }}</span>
                            @error($key)
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-hint" id="php-{{ $key }}-hint">{{ __('sites.php_settings.range', ['min' => $bounds[$key]['min'], 'max' => $bounds[$key]['max'], 'unit' => $unit]) }}</div>
                    </div>
                @endforeach
            </div>
            <label class="form-check mb-1">
                <input type="hidden" name="errors" value="0">
                <input type="checkbox" class="form-check-input" name="errors" value="1" @checked(old('errors', $effective['errors'] ? '1' : '0') === '1') @disabled($locked) aria-describedby="php-errors-hint">
                <span class="form-check-label">{{ __('sites.php_settings.errors') }}</span>
            </label>
            <div class="form-hint mb-3" id="php-errors-hint">{{ __('sites.php_settings.errors_hint') }}</div>
            <p class="text-secondary small mb-0">
                {{ __('sites.php_settings.processes', ['count' => $effective['processes'], 'total' => \App\Support\Units::bytes($effective['memory'] * $effective['processes'] * 1024 * 1024)]) }}
                @if ($locked)
                    <br><span class="text-warning">{{ __('sites.php_settings.no_package') }}</span>
                @endif
            </p>
            <x-slot:footer>
                <button type="submit" class="btn btn-primary" @disabled($busy || $locked)>
                    <x-icon name="device-floppy" class="me-1" />{{ __('sites.php_settings.submit') }}
                </button>
            </x-slot:footer>
        </x-card>
    </form>
</div>
