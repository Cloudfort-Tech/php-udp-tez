<?php

declare(strict_types=1);

namespace Cloudfort\Tez;

use InvalidArgumentException;
use JsonException;

/** @internal Shared validation for the plain client and Laravel adapter. */
final class Protocol
{
    public const PUBLISH_PATH = '/v1/channels/events';
    public const GRANT_TTL = 60;
    public const MAX_BODY_BYTES = 65536;
    public const MAX_CHANNELS = 16;
    public const MAX_USER_INFO_BYTES = 1024;

    public static function channel(mixed $channel): string
    {
        if (! is_string($channel)
            || preg_match('/\A[A-Za-z0-9._:-]{1,200}\z/D', $channel) !== 1
            || str_starts_with($channel, 'private-encrypted-')) {
            throw new InvalidArgumentException('Channel must be 1-200 ASCII letters, digits, or ._:-; encrypted channels are unsupported.');
        }

        return $channel;
    }

    public static function isGuarded(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || self::isPresence($channel);
    }

    public static function isPresence(string $channel): bool
    {
        return str_starts_with($channel, 'presence-');
    }

    public static function event(mixed $event): string
    {
        return self::text($event, 200, 'Event');
    }

    public static function userId(mixed $userId): string
    {
        return self::text($userId, 128, 'User identifier');
    }

    public static function socketId(mixed $socketId): string
    {
        if (! is_string($socketId)
            || preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D', $socketId) !== 1) {
            throw new InvalidArgumentException('Socket identifier must be a UUID string.');
        }

        return $socketId;
    }

    public static function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Value must be representable as valid JSON.', 0, $exception);
        }
    }

    public static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function text(mixed $value, int $maxBytes, string $label): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > $maxBytes
            || preg_match('//u', $value) !== 1 || preg_match('/\p{Cc}/u', $value) !== 0) {
            throw new InvalidArgumentException($label.' must be nonempty UTF-8, at most '.$maxBytes.' bytes, without control characters.');
        }

        return $value;
    }
}
