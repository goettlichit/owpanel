<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Servers\HostKey;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Adds a server with its fixed host key (E-051). The key is read from a file
 * instead of being taken from the first connection, so a server that is not
 * the expected one cannot slip in. For the panel's own server the file is
 * /etc/ssh/ssh_host_ed25519_key.pub. In milestone 3 the installer adds the
 * own server, later the bootstrap of further servers.
 *
 *   php artisan owpanel:add-server --name=web1 --host=127.0.0.1 --host-key-file=/etc/ssh/ssh_host_ed25519_key.pub
 *
 * The public addresses are only shown (E-072). With --skip-existing they are
 * updated on an existing server, so a second installer run fills them in.
 */
class AddServer extends Command
{
    protected $signature = 'owpanel:add-server
        {--name= : Name shown in the panel}
        {--host= : IP address or host name}
        {--port=22 : SSH port}
        {--host-key-file= : Public Ed25519 host key of the server}
        {--public-ipv4= : Public IPv4 address, shown in the panel}
        {--public-ipv6= : Public IPv6 address, shown in the panel}
        {--skip-existing : Succeed without changes if the server exists with the same host key}';

    protected $description = 'Add a server to the panel';

    public function handle(): int
    {
        $file = (string) $this->option('host-key-file');
        $hostKey = $file !== '' && is_file($file) && is_readable($file) ? (string) file_get_contents($file, length: 4096) : '';

        $validator = Validator::make([
            'name' => $this->option('name'),
            'host' => is_string($this->option('host')) ? strtolower(trim($this->option('host'))) : null,
            'port' => $this->option('port'),
            'host_key' => $hostKey,
            'public_ipv4' => $this->option('public-ipv4') ?: null,
            'public_ipv6' => $this->option('public-ipv6') ?: null,
        ], [
            'public_ipv4' => ['nullable', 'ipv4'],
            'public_ipv6' => ['nullable', 'ipv6'],
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:253', function (string $attribute, string $value, $fail) {
                if (! filter_var($value, FILTER_VALIDATE_IP) && ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $value)) {
                    $fail('The host must be an IP address or a host name.');
                }
            }],
            'port' => ['required', 'integer', 'between:1,65535',
                Rule::unique('servers')->where(fn ($query) => $query->where('host', strtolower(trim((string) $this->option('host'))))),
            ],
            'host_key' => ['required', function (string $attribute, string $value, $fail) {
                if (! HostKey::isValid($value)) {
                    $fail('The host key file must contain one Ed25519 public key.');
                }
            }],
        ], [
            'port.unique' => 'A server with this host and port exists already.',
            'host_key.required' => 'The host key file is missing or not readable.',
        ]);

        if ($this->option('skip-existing') && HostKey::isValid($hostKey)) {
            $existing = Server::where('host', strtolower(trim((string) $this->option('host'))))
                ->where('port', (int) $this->option('port'))
                ->first();
            if ($existing !== null) {
                if (! HostKey::matches($existing->host_key, $hostKey)) {
                    $this->error('The server exists with another host key; nothing changed.');

                    return self::FAILURE;
                }

                $addresses = Validator::make([
                    'public_ipv4' => $this->option('public-ipv4') ?: null,
                    'public_ipv6' => $this->option('public-ipv6') ?: null,
                ], [
                    'public_ipv4' => ['nullable', 'ipv4'],
                    'public_ipv6' => ['nullable', 'ipv6'],
                ]);
                if ($addresses->fails()) {
                    $this->error('Invalid public address.');

                    return self::FAILURE;
                }
                $existing->fill(array_filter($addresses->validated(), fn ($value) => $value !== null))->save();
                $this->info("Server {$existing->name} exists already, public addresses updated.");

                return self::SUCCESS;
            }
        }

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();
        $server = Server::create([...$data, 'port' => (int) $data['port']]);

        Audit::record('server.added', $server, ['host' => $server->host, 'source' => 'console']);

        $this->info("Server {$server->name} added ({$server->address}).");
        $this->line("Host key: {$server->host_key_fingerprint}");

        return self::SUCCESS;
    }
}
