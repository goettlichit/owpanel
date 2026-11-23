{{--
    Messages after a redirect: "status" (success), "warning" and "error".
    Fortify sends codes such as "password-updated"; they are translated in
    lang/*/account.php under "status".

    In the main layout the messages appear as toasts at the top centre under
    the header (:toast="true", docs/DESIGN.md "Hinweise"). Success messages
    close by themselves after a few seconds, warnings and errors stay until
    closed. On the login pages they stay inline above the form.
--}}
@props(['toast' => false])
@foreach (['status' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $type)
    @if ($message = session($key))
        <div @class(['alert', 'alert-'.$type, 'alert-dismissible ow-toast' => $toast])
            role="{{ $type === 'success' ? 'status' : 'alert' }}"
            @if ($toast && $type === 'success') data-ow-autohide @endif>
            <div class="d-flex">
                <x-icon :name="match ($type) { 'success' => 'check', 'warning' => 'alert-triangle', default => 'alert-circle' }" class="alert-icon" />
                <div>
                    {{ \Illuminate\Support\Facades\Lang::has('account.status.'.$message) ? __('account.status.'.$message) : $message }}
                    @if ($key === 'error' && session('error_link'))
                        {{-- Failed server task: link to its log, administrators only (E-152). --}}
                        <a href="{{ session('error_link') }}" class="alert-link ms-1">{{ __('tasks.feedback.details') }}</a>
                    @endif
                </div>
            </div>
            @if ($toast)
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('ui.close') }}"></button>
            @endif
        </div>
    @endif
@endforeach
