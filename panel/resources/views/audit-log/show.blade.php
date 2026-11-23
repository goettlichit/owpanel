<x-layouts.app :title="__('audit.show.title')">
    <x-slot:sidebar>
        @include('system._menu')
    </x-slot:sidebar>

    @php($label = \Illuminate\Support\Facades\Lang::has('audit.actions.'.$entry->action) ? __('audit.actions.'.$entry->action) : $entry->action)
    <x-page-header :title="$label" :pretitle="__('audit.title')">
        <x-slot:actions>
            <a href="{{ route('audit-log.index') }}" class="btn btn-outline-primary"><x-icon name="arrow-left" class="me-1" />{{ __('audit.show.back') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row row-cards align-items-start">
        <div class="col-lg-6">
            <x-card :title="__('audit.show.entry')">
                <x-slot:actions>
                    <x-badge :tone="$entry->tone()">{{ $label }}</x-badge>
                </x-slot:actions>
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('audit.columns.time') }}</dt>
                    <dd class="col-sm-8"><x-time :value="$entry->created_at" /></dd>
                    <dt class="col-sm-4">{{ __('audit.columns.action') }}</dt>
                    <dd class="col-sm-8"><code>{{ $entry->action }}</code></dd>
                    <dt class="col-sm-4">{{ __('audit.columns.user') }}</dt>
                    <dd class="col-sm-8">
                        @if ($entry->user)
                            <div>{{ $entry->user->name }}</div>
                            <div class="small text-secondary">{{ $entry->user->email }}</div>
                        @elseif ($entry->user_id)
                            <span class="text-secondary">{{ __('audit.deleted_user') }}</span>
                        @else
                            <span class="text-secondary">–</span>
                        @endif
                    </dd>
                    <dt class="col-sm-4">{{ __('audit.show.subject') }}</dt>
                    <dd class="col-sm-8">
                        @if ($entry->subject_type)
                            {{ $entry->subject_type }}
                            @if ($subjectUrl)
                                <a href="{{ $subjectUrl }}" class="ms-1">{{ __('audit.show.open_subject') }}</a>
                            @endif
                            <div class="small text-secondary"><code>{{ $entry->subject_id }}</code></div>
                        @else
                            <span class="text-secondary">–</span>
                        @endif
                    </dd>
                    <dt class="col-sm-4">{{ __('audit.columns.ip') }}</dt>
                    <dd class="col-sm-8">{{ $entry->ip_address ?? '–' }}</dd>
                    <dt class="col-sm-4">{{ __('audit.show.user_agent') }}</dt>
                    <dd class="col-sm-8 mb-0 small text-break">{{ $entry->user_agent ?? '–' }}</dd>
                </dl>
            </x-card>
        </div>

        <div class="col-lg-6">
            <x-card :title="__('audit.show.details')">
                @if (empty($entry->context))
                    <p class="text-secondary mb-0">{{ __('audit.show.no_details') }}</p>
                @else
                    <dl class="row mb-0">
                        @foreach ($entry->context as $key => $value)
                            <dt class="col-sm-4"><code>{{ $key }}</code></dt>
                            <dd @class(['col-sm-8 text-break', 'mb-0' => $loop->last])>
                                @if (is_array($value))
                                    <code>{{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>
                                @elseif (is_bool($value))
                                    {{ $value ? 'true' : 'false' }}
                                @elseif ($value === null || $value === '')
                                    <span class="text-secondary">–</span>
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
