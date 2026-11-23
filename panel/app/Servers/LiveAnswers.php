<?php

namespace App\Servers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Answers of scripts that a web request waits for (E-079, E-086): the
 * database tool and the website logs.
 *
 * Web requests run as ow10000 and cannot read the SSH key, so a job on the
 * queue "queries" (service owpanel-query) runs the script and puts its
 * answer here, encrypted with the app key. The web request waits for it
 * and removes it when it reads it.
 */
final class LiveAnswers
{
    private const PREFIX = 'live-answer:';

    /**
     * How long an answer stays if nobody picks it up.
     */
    private const KEEP_SECONDS = 120;

    /**
     * @param  array<mixed>  $answer
     */
    public static function put(string $id, array $answer): void
    {
        Cache::put(
            self::PREFIX.$id,
            Crypt::encryptString((string) json_encode($answer, JSON_INVALID_UTF8_SUBSTITUTE)),
            self::KEEP_SECONDS,
        );
    }

    /**
     * The answer, once; null while there is none.
     *
     * @return array<mixed>|null
     */
    public static function take(string $id): ?array
    {
        $value = Cache::pull(self::PREFIX.$id);
        if (! is_string($value)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($value), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['error' => 'invalid_result'];
        }

        return is_array($data) ? $data : ['error' => 'invalid_result'];
    }

    /**
     * Waits for the answer; null after the time is up. With the sync queue
     * in tests the answer is already there.
     *
     * @return array<mixed>|null
     */
    public static function wait(string $id): ?array
    {
        $deadline = microtime(true) + (float) config('owpanel.queries.wait_seconds', 45);
        do {
            $answer = self::take($id);
            if ($answer !== null) {
                return $answer;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
