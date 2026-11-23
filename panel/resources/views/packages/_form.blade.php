{{-- Fields of a package (E-125). Expects $package. Empty limits mean unlimited. --}}
<div class="mb-3">
    <label class="form-label required" for="name">{{ __('packages.fields.name') }}</label>
    <input type="text" id="name" name="name" value="{{ old('name', $package->name) }}" maxlength="100" required autocomplete="off"
        @class(['form-control', 'is-invalid' => $errors->has('name')])>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-hint">{{ __('packages.fields.name_hint') }}</div>
</div>
@php
    $storage = old('storage_gb', $package->storage_mb !== null ? rtrim(rtrim(number_format($package->storage_mb / 1024, 1, '.', ''), '0'), '.') : null);
    $fields = [
        'max_sites' => [old('max_sites', $package->max_sites), '1', null],
        'storage_gb' => [$storage, '0.1', 'storage_hint'],
        'max_databases' => [old('max_databases', $package->max_databases), '1', null],
        'max_cron_jobs' => [old('max_cron_jobs', $package->max_cron_jobs), '1', null],
        'min_cron_interval' => [old('min_cron_interval', $package->min_cron_interval), '1', 'min_cron_interval_hint'],
        'daily_self_service' => [old('daily_self_service', $package->daily_self_service), '1', 'daily_self_service_hint'],
    ];
@endphp
<div class="row">
    @foreach ($fields as $field => [$value, $step, $hint])
        <div class="col-sm-6 mb-3">
            <label class="form-label" for="{{ $field }}">{{ __('packages.fields.'.$field) }}</label>
            <input type="number" id="{{ $field }}" name="{{ $field }}" value="{{ $value }}" min="{{ $field === 'min_cron_interval' ? 1 : 0 }}" step="{{ $step }}"
                placeholder="{{ __('packages.unlimited') }}" inputmode="{{ $step === '1' ? 'numeric' : 'decimal' }}"
                @class(['form-control', 'is-invalid' => $errors->has($field)])>
            @error($field)
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-hint">{{ $hint ? __('packages.fields.'.$hint).' ' : '' }}{{ __('packages.fields.empty_is_unlimited') }}</div>
        </div>
    @endforeach
</div>

{{-- PHP of each website (E-173). New packages start with sensible values; empty means up to what the server accepts. --}}
<h4 class="mt-2 mb-1">{{ __('packages.php.title') }}</h4>
<p class="text-secondary">{{ __('packages.php.text') }}</p>
<div class="row">
    @foreach (['php_memory_mb' => ['memory', 'MB'], 'php_upload_mb' => ['upload', 'MB'], 'php_time_s' => ['time', 's'], 'php_processes' => ['processes', null]] as $field => [$key, $unit])
        <div class="col-sm-6 mb-3">
            <label class="form-label" for="{{ $field }}">{{ __('packages.fields.'.$field) }}</label>
            <div class="input-group">
                <input type="number" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $package->{$field}) }}"
                    min="{{ config('owpanel.php_settings.min.'.$key) }}" max="{{ config('owpanel.php_settings.max.'.$key) }}" step="1"
                    placeholder="{{ __('packages.unlimited') }}" inputmode="numeric"
                    @class(['form-control', 'is-invalid' => $errors->has($field)])>
                @if ($unit)
                    <span class="input-group-text">{{ $unit }}</span>
                @endif
                @error($field)
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-hint">{{ __('packages.fields.'.$field.'_hint', ['max' => config('owpanel.php_settings.max.'.$key)]) }}</div>
        </div>
    @endforeach
</div>
