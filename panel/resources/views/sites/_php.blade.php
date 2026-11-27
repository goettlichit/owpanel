{{--
    PHP version of a website (E-172), on the page "Website and domain" for
    administrators and on the overview for customers. Needs $site, $busy and
    $action (the route of the form).
--}}
@php($phpVersions = $site->server->phpVersions())
<div class="col-12">
    <form data-ow-busy="{{ __('tasks.busy.php') }}" data-ow-task="site-create gateway-site" method="POST" action="{{ $action }}" class="h-100">
        @csrf
        <x-card :title="__('sites.php.title')" class="h-100">
            <p class="text-secondary">{{ __('sites.php.text', ['version' => $site->php_version ?? '–']) }}</p>
            <div class="mb-0">
                <label class="form-label" for="php">{{ __('sites.php.label') }}</label>
                <select id="php" name="php" aria-describedby="php-hint" @class(['form-select', 'is-invalid' => $errors->has('php')]) @disabled($busy)>
                    @foreach ($phpVersions as $version)
                        <option value="{{ $version }}" @selected($version === $site->php_version)>
                            PHP {{ $version }}@if ($version === config('owpanel.php_default')) ({{ __('sites.php.default') }})@endif
                        </option>
                    @endforeach
                </select>
                @error('php')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-hint" id="php-hint">{{ __('sites.php.hint') }}</div>
            </div>
            <x-slot:footer>
                <button type="submit" class="btn btn-primary" @disabled($busy || count($phpVersions) < 2)>
                    <x-icon name="brand-php" class="me-1" />{{ __('sites.php.submit') }}
                </button>
            </x-slot:footer>
        </x-card>
    </form>
</div>
