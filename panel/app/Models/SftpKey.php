<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A public SSH key for the SFTP access of a website (E-089). site-sftp
 * writes all keys of the website to /etc/ssh/owpanel-sftp/<user>.
 */
class SftpKey extends Model
{
    use HasUlids;

    public const MAX_PER_SITE = 20;

    public const MAX_LENGTH = 8192;

    /**
     * The same list as in server/bin/site-sftp. DSA is gone from OpenSSH.
     */
    public const TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    /**
     * Number of fields in the key data, the type included (RFC 4253,
     * RFC 5656, PROTOCOL.u2f). The server checks the key again with
     * ssh-keygen.
     */
    private const FIELDS = [
        'ssh-ed25519' => 2,
        'ssh-rsa' => 3,
        'ecdsa-sha2-nistp256' => 3,
        'ecdsa-sha2-nistp384' => 3,
        'ecdsa-sha2-nistp521' => 3,
        'sk-ssh-ed25519@openssh.com' => 3,
        'sk-ecdsa-sha2-nistp256@openssh.com' => 4,
    ];

    protected $fillable = ['name', 'public_key', 'fingerprint'];

    /**
     * Checks a public key as it appears in id_ed25519.pub and the like:
     * type, Base64 data whose first field repeats the type, an optional
     * comment. Options in front of the key are not allowed. Returns the key
     * as one clean line with its parts, or null.
     *
     * @return array{key: string, type: string, comment: string, fingerprint: string}|null
     */
    public static function parse(string $input): ?array
    {
        $line = trim($input);
        if ($line === '' || strlen($line) > self::MAX_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $line)) {
            return null;
        }

        $parts = preg_split('/ +/', $line, 3) ?: [];
        [$type, $blob] = [$parts[0], $parts[1] ?? ''];
        $comment = trim($parts[2] ?? '');
        if (! in_array($type, self::TYPES, true) || ! preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $blob)) {
            return null;
        }
        if ($comment !== '' && ! preg_match('/^[\x20-\x7e]+$/', $comment)) {
            return null;
        }

        // The data is a row of fields, each with its length in front; the
        // first one repeats the type. Ed25519 keys have exactly 32 bytes.
        $data = base64_decode($blob, true);
        $fields = $data === false ? null : self::fields($data);
        if ($fields === null || count($fields) !== self::FIELDS[$type] || $fields[0] !== $type
            || (str_contains($type, 'ed25519') && strlen($fields[1]) !== 32)) {
            return null;
        }

        return [
            'key' => $type.' '.$blob.($comment !== '' ? ' '.$comment : ''),
            'type' => $type,
            'comment' => $comment,
            // As ssh-keygen -l shows it.
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $data, true)), '='),
        ];
    }

    /**
     * Splits SSH wire data into its length-prefixed fields; null if the
     * data does not end exactly after the last field.
     *
     * @return list<string>|null
     */
    private static function fields(string $data): ?array
    {
        $fields = [];
        $offset = 0;
        while ($offset < strlen($data)) {
            if (strlen($data) - $offset < 4) {
                return null;
            }
            $length = unpack('N', $data, $offset)[1];
            if ($length > strlen($data) - $offset - 4) {
                return null;
            }
            $fields[] = substr($data, $offset + 4, $length);
            $offset += 4 + $length;
        }

        return $fields;
    }

    /**
     * Printable ASCII without spaces, 12 to 128 characters, as site-sftp
     * expects.
     */
    public static function validPassword(string $password): bool
    {
        return preg_match('/^[\x21-\x7e]{12,128}$/', $password) === 1;
    }

    /**
     * Letters and digits only, so it can be typed into any SFTP client.
     */
    public static function generatePassword(): string
    {
        return Str::password(24, letters: true, numbers: true, symbols: false, spaces: false);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
