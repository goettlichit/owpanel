<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerJoinToken;
use App\Models\ServerTask;
use App\Servers\HostKey;
use App\Servers\ScriptCall;
use App\Servers\ScriptsArchive;
use App\Support\Audit;
use App\Support\PanelRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * What a new server asks for while it joins (E-166, E-196): only with an
 * open one-time code in the Authorization header. A wrong, used or expired
 * code gets the same 404 as an unknown page. Answers are plain key=value
 * lines, so the installer reads them without extra tools.
 */
class JoinController extends Controller
{
    public function info(Request $request): Response
    {
        $token = $this->token($request);
        $key = $this->panelKey();
        abort_if($key === null || ! ScriptsArchive::available(), 503);

        return $this->lines([
            'version' => PanelRelease::installed(),
            'name' => $token->name,
            'timezone' => (string) config('owpanel.timezone'),
            'ssh_key' => $key,
            'sha256' => hash_file('sha256', ScriptsArchive::path()),
            'signed' => ScriptsArchive::signature() !== null ? '1' : '0',
        ]);
    }

    public function archive(Request $request): BinaryFileResponse
    {
        $this->token($request);
        abort_unless(ScriptsArchive::available(), 503);

        return response()->download(ScriptsArchive::path(), basename(ScriptsArchive::path()), ['Content-Type' => 'application/gzip']);
    }

    public function signature(Request $request): Response
    {
        $this->token($request);
        $signature = ScriptsArchive::signature();
        abort_if($signature === null, 404);

        return response($signature, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * The server says who it is. The panel pins its host key, uses up the
     * code and confirms the firewall over the private address at once; a
     * server the panel cannot reach rolls its firewall back by itself.
     */
    public function register(Request $request): Response
    {
        $token = $this->token($request);
        $validator = Validator::make($request->only(['hostname', 'private_ipv4', 'public_ipv4', 'public_ipv6', 'ssh_port', 'host_key', 'backend_cert']), [
            'hostname' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'private_ipv4' => ['required', 'ipv4', function (string $attribute, mixed $value, \Closure $fail) {
                if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) !== false) {
                    $fail('not private');
                }
            }],
            'public_ipv4' => ['nullable', 'ipv4'],
            'public_ipv6' => ['nullable', 'ipv6'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'host_key' => ['required', 'string', 'max:200', fn (string $attribute, mixed $value, \Closure $fail) => HostKey::isValid((string) $value) ? null : $fail('invalid')],
            'backend_cert' => ['required', 'string', 'max:8192', 'regex:/\A-----BEGIN CERTIFICATE-----\n([A-Za-z0-9+\/=]{1,76}\n){1,60}-----END CERTIFICATE-----\n?\z/'],
        ]);
        abort_if($validator->fails(), 422, 'invalid');
        $data = $validator->validated();
        // A code for the name of an existing server joins it again, e.g.
        // after an aborted run, but only the same machine: its pinned host
        // key must match. Otherwise a known address, the panel's included,
        // is taken.
        $hostKey = HostKey::normalize($data['host_key']);
        $again = Server::where('name', $token->name)->first();
        abort_if($again !== null && ($again->hostsPanel() || ! hash_equals((string) $again->host_key, $hostKey)), 409, 'other');
        $taken = Server::where(fn ($query) => $query->where('host', $data['private_ipv4'])->orWhere('private_ipv4', $data['private_ipv4']))
            ->when($again !== null, fn ($query) => $query->whereKeyNot($again->getKey()))->exists();
        abort_if($taken, 409, 'known');

        $server = DB::transaction(function () use ($token, $data, $hostKey, $again) {
            // Used up first, so a second request with the same code fails.
            $used = ServerJoinToken::whereKey($token->getKey())->whereNull('used_at')->update(['used_at' => now()]);
            abort_if($used !== 1, 404);

            $server = $again ?? new Server;
            $server->forceFill([
                'name' => $token->name,
                'host' => $data['private_ipv4'],
                'port' => (int) $data['ssh_port'],
                'host_key' => $hostKey,
                'hostname' => $data['hostname'],
                'public_ipv4' => $data['public_ipv4'] ?? null,
                'public_ipv6' => $data['public_ipv6'] ?? null,
                'private_ipv4' => $data['private_ipv4'],
                'backend_cert' => rtrim($data['backend_cert'], "\n")."\n",
            ])->save();
            $token->forceFill(['server_id' => $server->getKey()])->save();
            Audit::record('server.joined', $server, ['name' => $server->name, 'private_ipv4' => $server->private_ipv4, 'hostname' => $server->hostname, 'again' => $again !== null], $token->user);
            // Within the 60 seconds of the rollback (E-044).
            ServerTask::start($server, ScriptCall::firewallConfirm(), null);

            return $server;
        });

        return $this->lines(['ok' => '1', 'server' => $server->name]);
    }

    private function token(Request $request): ServerJoinToken
    {
        $token = ServerJoinToken::findOpen($request->bearerToken());
        abort_if($token === null, 404);

        return $token;
    }

    /**
     * The panel's public SSH key, as the installer leaves it for the panel.
     */
    private function panelKey(): ?string
    {
        $file = (string) config('owpanel.ssh.public_key');
        $key = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match('#^ssh-ed25519 [A-Za-z0-9+/=]{60,100}( [A-Za-z0-9@._-]{1,64})?$#', $key) === 1 ? $key : null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function lines(array $values): Response
    {
        $body = '';
        foreach ($values as $key => $value) {
            $body .= $key.'='.str_replace(["\r", "\n"], '', $value)."\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
