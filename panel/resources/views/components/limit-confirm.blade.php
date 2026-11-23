{{--
    Confirmation when an administrator's action breaks a limit of the
    customer's package (E-139). Lists the broken limits and sends the same
    form again with "exceed_limits"; "Cancel" just closes the banner. Set by
    App\Http\Controllers\Concerns\ChecksPackageLimits.
--}}
@php($confirm = session('limit_confirm'))
@if (is_array($confirm) && auth()->user()?->isAdmin())
    <div class="alert alert-warning d-print-none" role="alert">
        <div class="d-flex gap-2">
            <x-icon name="alert-triangle" class="alert-icon" />
            <div class="flex-fill">
                <h4 class="alert-title">{{ __('packages.confirm.title') }}</h4>
                <ul class="mb-2 ps-3">
                    @foreach ($confirm['broken'] as $sentence)
                        <li>{{ $sentence }}</li>
                    @endforeach
                </ul>
                <p class="mb-2">{{ __('packages.confirm.text') }}</p>
                <form method="POST" action="{{ $confirm['url'] }}" class="d-flex gap-2 flex-wrap">
                    @csrf
                    @if (strtoupper($confirm['method']) !== 'POST')
                        @method($confirm['method'])
                    @endif
                    @foreach ($confirm['input'] as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="hidden" name="exceed_limits" value="1">
                    <button type="submit" class="btn btn-warning">{{ __('packages.confirm.submit') }}</button>
                    <a href="{{ url()->current() }}" class="btn btn-outline-secondary">{{ __('packages.confirm.cancel') }}</a>
                </form>
            </div>
        </div>
    </div>
@endif
