<x-layouts.app :title="__('settings.mail.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    <x-page-header :title="__('settings.mail.title')" :pretitle="__('ui.nav.system')" />

    <div class="row row-cards">
        <div class="col-12">
            <form method="POST" action="{{ route('settings.mail.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-card :title="__('settings.mail.title')" :accent="$mailConfigured ? 'green' : 'blue'">
                    <x-slot:actions>
                        @if ($mailConfigured)
                            <span class="badge bg-green-lt"><x-icon name="mail-check" class="me-1" />{{ __('settings.mail.on') }}</span>
                        @else
                            <span class="badge bg-secondary-lt"><x-icon name="mail-off" class="me-1" />{{ __('settings.mail.off') }}</span>
                        @endif
                    </x-slot:actions>
                    <p class="text-secondary">{{ __('settings.mail.text') }}</p>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="mail_host">{{ __('settings.mail.host') }}</label>
                            <input type="text" id="mail_host" name="mail_host" value="{{ old('mail_host', $mail['host']) }}" placeholder="smtp.example.de" autocomplete="off"
                                @class(['form-control', 'is-invalid' => $errors->has('mail_host')])>
                            @error('mail_host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="mail_port">{{ __('settings.mail.port') }}</label>
                            <input type="number" id="mail_port" name="mail_port" value="{{ old('mail_port', $mail['port']) }}" min="1" max="65535"
                                @class(['form-control', 'is-invalid' => $errors->has('mail_port')])>
                            @error('mail_port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="mail_encryption">{{ __('settings.mail.encryption') }}</label>
                            <select id="mail_encryption" name="mail_encryption" class="form-select">
                                @foreach (\App\Support\MailSettings::ENCRYPTIONS as $encryption)
                                    <option value="{{ $encryption }}" @selected(old('mail_encryption', $mail['encryption']) === $encryption)>{{ __('settings.mail.encryptions.'.$encryption) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label" for="mail_username">{{ __('settings.mail.username') }}</label>
                            <input type="text" id="mail_username" name="mail_username" value="{{ old('mail_username', $mail['username']) }}" autocomplete="off"
                                @class(['form-control', 'is-invalid' => $errors->has('mail_username')])>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label" for="mail_password">{{ __('settings.mail.password') }}</label>
                            <input type="password" id="mail_password" name="mail_password" autocomplete="new-password"
                                placeholder="{{ $mail['password_set'] ? __('settings.mail.password_keep') : '' }}"
                                @class(['form-control', 'is-invalid' => $errors->has('mail_password')])>
                            @if ($mail['password_set'])
                                <label class="form-check mt-2 mb-0">
                                    <input type="checkbox" class="form-check-input" name="mail_password_remove" value="1">
                                    <span class="form-check-label">{{ __('settings.mail.password_remove') }}</span>
                                </label>
                            @endif
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label" for="mail_from_address">{{ __('settings.mail.from_address') }}</label>
                            <input type="email" id="mail_from_address" name="mail_from_address" value="{{ old('mail_from_address', $mail['from_address']) }}" placeholder="panel@example.de"
                                @class(['form-control', 'is-invalid' => $errors->has('mail_from_address')])>
                            @error('mail_from_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label" for="mail_from_name">{{ __('settings.mail.from_name') }}</label>
                            <input type="text" id="mail_from_name" name="mail_from_name" value="{{ old('mail_from_name', $mail['from_name']) }}" maxlength="100" placeholder="OWPanel"
                                class="form-control">
                        </div>
                    </div>
                    <div class="form-hint mt-2">{{ __('settings.mail.hint') }}</div>
                    <x-slot:footer>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary"><x-icon name="device-floppy" class="me-1" />{{ __('settings.submit') }}</button>
                            @if ($mailConfigured)
                                <button type="submit" form="mail-test" class="btn btn-outline-primary"><x-icon name="send" class="me-1" />{{ __('settings.mail.test') }}</button>
                            @endif
                        </div>
                    </x-slot:footer>
                </x-card>
            </form>
            <form method="POST" action="{{ route('settings.mail.test') }}" id="mail-test" class="d-none">@csrf</form>
        </div>
    </div>
</x-layouts.app>
