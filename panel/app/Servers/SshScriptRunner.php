<?php

namespace App\Servers;

use App\Models\Server;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Runs approved scripts over SSH with phpseclib (E-051).
 *
 * Order matters: the host key is checked before the panel signs in, so a
 * server that is not the expected one never sees the panel's key or a
 * command.
 */
class SshScriptRunner implements ScriptRunner
{
    /**
     * Only the end of the output is kept here; the full log goes to
     * $onOutput. The result line is short.
     */
    private const TAIL_BYTES = 8192;

    /**
     * Piece size for files sent to a script.
     */
    private const TRANSFER_BYTES = 1048576;

    public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
    {
        $key = $this->privateKey();

        try {
            $ssh = new SSH2($server->host, $server->port, config('owpanel.ssh.connect_timeout'));
            // Ask only for the key type the panel knows, so the server cannot
            // present another one of its keys.
            $ssh->setPreferredAlgorithms(['hostkey' => [HostKey::TYPE]]);
            $presented = $ssh->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new ServerConnectionException('ssh_connection_failed', $e->getMessage());
        }

        if (! is_string($presented) || ! HostKey::matches($server->host_key, $presented)) {
            $ssh->disconnect();
            throw new ServerConnectionException('host_key_mismatch', 'The server presented an unexpected host key.');
        }

        try {
            if (! $ssh->login(config('owpanel.ssh.user'), $key)) {
                throw new ServerConnectionException('ssh_login_failed', 'The server refused the panel key.');
            }

            $tail = '';
            $collect = function (string $chunk) use (&$tail, $onOutput): void {
                $tail = substr($tail.$chunk, -self::TAIL_BYTES);
                $onOutput($chunk);
            };
            $ssh->setTimeout($call->script->timeout());

            if ($call->stdoutFile !== null) {
                $this->execToFile($ssh, $call, $collect);
            } elseif ($call->stdin === null && $call->stdinFile === null) {
                $ssh->exec($call->commandLine(), $collect);
            } else {
                $this->execWithInput($ssh, $call, $collect);
            }

            $timedOut = $ssh->isTimeout();
            $exitCode = $ssh->getExitStatus();
        } catch (ServerConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ServerConnectionException('ssh_connection_lost', $e->getMessage());
        } finally {
            $ssh->disconnect();
        }

        return ScriptResult::fromOutput(
            $call->script->value,
            $tail,
            is_int($exitCode) ? $exitCode : null,
            $timedOut,
        );
    }

    /**
     * Runs the command, sends the secret input on its stdin and closes it,
     * then reads the output as exec() does. The input never appears in the
     * command line, the process list or the task log (E-078).
     *
     * @param  callable(string): void  $collect
     */
    private function execWithInput(SSH2 $ssh, ScriptCall $call, callable $collect): void
    {
        if ($ssh->exec($call->commandLine(), false) === false) {
            throw new ServerConnectionException('ssh_connection_lost', 'The command could not be started.');
        }

        $this->sendInput($ssh, $call);

        while (true) {
            $chunk = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_EXEC);
            if (! is_string($chunk) || $ssh->isTimeout()) {
                break;
            }
            $collect($chunk);
        }
    }

    /**
     * Sends $stdin, then the file in $stdinFile in pieces, and closes stdin.
     */
    private function sendInput(SSH2 $ssh, ScriptCall $call): void
    {
        if ($call->stdin !== null) {
            $ssh->write($call->stdin, SSH2::CHANNEL_EXEC);
        }

        if ($call->stdinFile !== null) {
            $handle = fopen($call->stdinFile, 'rb');
            if ($handle === false) {
                throw new ServerConnectionException('transfer_file_missing', 'The file for the script cannot be read.');
            }
            try {
                while (! feof($handle)) {
                    $piece = fread($handle, self::TRANSFER_BYTES);
                    if ($piece === false) {
                        throw new ServerConnectionException('transfer_file_missing', 'The file for the script cannot be read.');
                    }
                    if ($piece !== '') {
                        $ssh->write($piece, SSH2::CHANNEL_EXEC);
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        $ssh->sendEOF(SSH2::CHANNEL_EXEC);
    }

    /**
     * Writes stdout into $stdoutFile; the log and the result line come from
     * stderr (db-export, E-082). In quiet mode phpseclib keeps stderr apart.
     *
     * @param  callable(string): void  $collect
     */
    private function execToFile(SSH2 $ssh, ScriptCall $call, callable $collect): void
    {
        $handle = fopen((string) $call->stdoutFile, 'xb');
        if ($handle === false) {
            throw new ServerConnectionException('transfer_file_missing', 'The file for the output cannot be created.');
        }

        $ssh->enableQuietMode();
        try {
            if ($ssh->exec($call->commandLine(), false) === false) {
                throw new ServerConnectionException('ssh_connection_lost', 'The command could not be started.');
            }
            $this->sendInput($ssh, $call);

            while (true) {
                $chunk = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_EXEC);
                if (! is_string($chunk) || $ssh->isTimeout()) {
                    break;
                }
                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new ServerConnectionException('transfer_file_missing', 'The output file cannot be written.');
                }
            }
        } finally {
            fclose($handle);
            $collect((string) $ssh->getStdError());
            $ssh->disableQuietMode();
        }
    }

    private function privateKey(): PrivateKey
    {
        $path = (string) config('owpanel.ssh.private_key');
        $content = is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new ServerConnectionException('ssh_key_missing', 'The private key of the panel is missing or not readable.');
        }

        try {
            return PublicKeyLoader::loadPrivateKey($content);
        } catch (Throwable) {
            // The exception message could quote parts of the key.
            throw new ServerConnectionException('ssh_key_missing', 'The private key of the panel cannot be read.');
        }
    }
}
