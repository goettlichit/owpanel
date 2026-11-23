@foreach ($messages as $message)
{!! \App\Notify\MessageText::title($message) !!}
{!! \App\Notify\MessageText::body($message) !!}
{{ __('notifications.mail.time') }}: {{ \App\Notify\MessageText::time($message) }}
@if (! empty($message['url']))
{!! $message['url'] !!}
@endif

@endforeach
--
{!! __('notifications.mail.footer', ['url' => route('account.notifications')]) !!}
