<?php

namespace Tests\Support\MySql\Concurrency;

use Tests\Support\MySql\Profile;

final class Barrier
{
    /** A bounded IPC primitive; sockets/pipes must be supplied by the coordinator. */
    public static function receive($stream, int $milliseconds): string
    {
        Profile::require(is_resource($stream) && $milliseconds > 0 && $milliseconds <= 30000, 'Invalid barrier deadline.');
        $deadline = hrtime(true) + $milliseconds * 1000000;
        $message = '';
        do {
            $remaining = max(0, $deadline - hrtime(true));
            $read = [$stream];
            $write = $except = [];
            Profile::require($remaining > 0 && stream_select($read, $write, $except, intdiv($remaining, 1000000000), intdiv($remaining % 1000000000, 1000)) > 0, 'Worker barrier timed out.');
            $byte = fread($stream, 1);
            Profile::require($byte !== false && $byte !== '', 'Worker barrier closed.');
            $message .= $byte;
            Profile::require(strlen($message) <= 1024, 'Worker barrier message is too large.');
        } while ($byte !== "\n");
        Profile::require(preg_match('/\A[a-z0-9_-]{1,100}\n\z/', $message) === 1, 'Invalid barrier message.');

        return rtrim($message, "\n");
    }

    public static function send($stream, string $message): void
    {
        Profile::require(is_resource($stream) && preg_match('/\A[a-z0-9_-]{1,100}\z/', $message) === 1, 'Invalid barrier message.');
        Profile::require(fwrite($stream, $message."\n") === strlen($message) + 1 && fflush($stream), 'Barrier write failed.');
    }
}
