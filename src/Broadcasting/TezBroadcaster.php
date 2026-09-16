<?php

declare(strict_types=1);

namespace Cloudfort\Tez\Broadcasting;

use Cloudfort\Tez\Protocol;
use Cloudfort\Tez\TezClient;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
class TezBroadcaster extends Broadcaster
{
    private TezClient $client;

    public function __construct(TezClient $client)
    {
        $this->client = $client;
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function auth($request)
    {
        $channelName = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        // Validate channel name
        if (! is_string($channelName)) {
            throw new AccessDeniedHttpException();
        }
        try {
            Protocol::channel($channelName);
        } catch (InvalidArgumentException) {
            throw new AccessDeniedHttpException();
        }

        // Validate socket ID for guarded channels
        $guarded = Protocol::isGuarded($channelName);
        if ($guarded) {
            try {
                Protocol::socketId(is_string($socketId) ? $socketId : '');
            } catch (InvalidArgumentException) {
                throw new AccessDeniedHttpException();
            }
        }

        // Public channel → no auth needed
        if (! $guarded) {
            return [];
        }

        // Determine the name the callback was registered under (one prefix stripped).
        $callbackName = $this->stripOnePrefix($channelName);

        // Retrieve user using the NORMALIZED name so channel options (guards) are found.
        $user = $this->retrieveUser($request, $callbackName);
        if ($user === null) {
            throw new AccessDeniedHttpException();
        }

        // Let the base class find the callback, resolve models, invoke it, and
        // forward to validAuthenticationResponse().
        return $this->verifyUserCanAccessChannel($request, $callbackName);
    }

    /**
     * Build the authentication response for a guarded channel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return array
     */
    public function validAuthenticationResponse($request, $result)
    {
        $channelName = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        if (Protocol::isPresence($channelName)) {
            // Presence: callback must return a non-empty array.
            if (! is_array($result) || $result === []) {
                throw new AccessDeniedHttpException();
            }

            try {
                $callbackName = $this->stripOnePrefix($channelName);
                $user = $this->retrieveUser($request, $callbackName);
                $rawId = method_exists($user, 'getAuthIdentifierForBroadcasting')
                    ? $user->getAuthIdentifierForBroadcasting()
                    : $user->getAuthIdentifier();
                if (is_int($rawId)) {
                    $rawId = (string) $rawId;
                }
                $userId = Protocol::userId($rawId);

                // Validate user_info byte size.
                $infoJson = Protocol::json($result);
                if (strlen($infoJson) > Protocol::MAX_USER_INFO_BYTES) {
                    throw new AccessDeniedHttpException();
                }
            } catch (InvalidArgumentException) {
                throw new AccessDeniedHttpException();
            }

            return [
                'auth' => $this->client->createSubscriptionGrant($channelName, $socketId, $userId, $result),
            ];
        }

        // Private channel.
        return [
            'auth' => $this->client->createSubscriptionGrant($channelName, $socketId),
        ];
    }

    /**
     * Broadcast the given event to the supplied channels.
     *
     * @param  array  $channels
     * @param  string  $event
     * @param  array  $payload
     * @return void
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        // --- validate channels ---
        $channelNames = [];
        foreach ($channels as $channel) {
            $name = is_object($channel) ? (string) $channel : $channel;
            if (! is_string($name)) {
                throw new InvalidArgumentException('Broadcast channel must be a string or an object with a name property.');
            }
            Protocol::channel($name);
            $channelNames[] = $name;
        }

        // --- validate event ---
        if (! is_string($event)) {
            throw new InvalidArgumentException('Broadcast event must be a string.');
        }
        Protocol::event($event);

        // --- extract & validate socket id ---
        $socketId = null;
        if (array_key_exists('socket', $payload)) {
            $raw = $payload['socket'];
            if ($raw !== null) {
                if (! is_string($raw)) {
                    throw new InvalidArgumentException('Socket identifier must be a UUID string.');
                }
                if ($raw === '') {
                    throw new InvalidArgumentException('Socket identifier must be a UUID string.');
                }
                Protocol::socketId($raw);
                $socketId = $raw;
            }
            unset($payload['socket']);
        }

        // --- reject encrypted channels ---
        foreach ($channelNames as $name) {
            if (str_starts_with($name, 'private-encrypted-')) {
                throw new InvalidArgumentException('Encrypted channels are not supported.');
            }
        }

        $this->client->publish($channelNames, $event, $payload, $socketId);
    }

    /**
     * Strip exactly one private-/presence- prefix from the channel name.
     */
    private function stripOnePrefix(string $channel): string
    {
        if (str_starts_with($channel, 'presence-')) {
            return substr($channel, 9);
        }
        if (str_starts_with($channel, 'private-')) {
            return substr($channel, 8);
        }

        return $channel;
    }
}
