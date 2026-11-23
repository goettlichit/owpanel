{{-- Plain text, sent as text/plain with nosniff, so nothing is HTML-escaped (E-099). --}}
{!! __('settings.backup_keys.heading') !!}
==================================================

{!! __('settings.backup_keys.panel') !!}: https://{!! $domain !!}
{!! __('settings.backup_keys.created') !!}: {!! $now->format('d.m.Y H:i') !!} ({!! $user->first_name !!} {!! $user->last_name !!})

{!! __('settings.backup_keys.warning') !!}

@foreach ($servers as $server)
--------------------------------------------------
{!! __('settings.backup_keys.server') !!}: {!! $server->display_name !!}@if ($panelServer && $panelServer->is($server)) ({!! __('settings.backup_keys.panel_server') !!})@endif

--------------------------------------------------
{!! __('settings.backup_keys.address') !!}: {!! $server->public_ipv4 ?? $server->public_ipv6 ?? $server->host !!}
{!! __('settings.backup_keys.box') !!}: {!! $server->backup_box_name ?? '–' !!}, {!! __('settings.backup_keys.host') !!} {!! $server->backup_host ?? '–' !!}, {!! __('settings.backup_keys.port') !!} 23
{!! __('settings.backup_keys.subaccount') !!}: {!! $server->backup_account ?? '–' !!}
{!! __('settings.backup_keys.subaccount_password') !!}
{!! __('settings.backup_keys.home') !!}: {!! \App\Backups\BackupTarget::homeDirectory($server) !!}
{!! __('settings.backup_keys.repository') !!}: {!! \App\Backups\BackupTarget::REPOSITORY !!}
{!! __('settings.backup_keys.repo_password') !!}: {!! $server->backup_repo_password ?? '–' !!}
@if ($server->backup_account && $server->backup_host)
{!! __('settings.backup_keys.command') !!}:
@php($login = $server->backup_account.'@'.$server->backup_host)
  restic -r sftp:{!! $login !!}:{!! \App\Backups\BackupTarget::REPOSITORY !!} -o sftp.command="ssh -p 23 {!! $login !!} -s sftp" snapshots
@endif

@endforeach
--------------------------------------------------
{!! __('settings.backup_keys.restore_title') !!}
--------------------------------------------------
{!! __('settings.backup_keys.restore_1') !!}
{!! __('settings.backup_keys.restore_2') !!}
{!! __('settings.backup_keys.restore_3') !!}
{!! __('settings.backup_keys.restore_4') !!}
{!! __('settings.backup_keys.restore_more') !!}
