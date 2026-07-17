<?php
// includes/chat_realtime.php
//
// Thin Redis Pub/Sub helpers for real-time chat delivery, with GRACEFUL
// DEGRADATION: if the phpredis extension is missing, or the Redis server is
// down / unreachable, every function fails quietly (returns null / no-op) so
// the caller can fall back to plain DB behaviour and nothing breaks.
//
// Publisher (ChatController): chatRealtimePublish($me, $partner)
// Subscriber (ChatStream):    $r = chatRealtimeConnect(); $r->subscribe([...])
//
// Both sides MUST derive the channel name the same way — use chatRealtimeChannel().

/**
 * Deterministic channel name for a pair of users (order-independent), so the
 * sender and receiver always publish/subscribe to the same channel.
 */
function chatRealtimeChannel(string $a, string $b): string
{
    $pair = [$a, $b];
    sort($pair, SORT_STRING);
    return 'flixo:chat:' . sha1($pair[0] . '|' . $pair[1]);
}

/**
 * Open a Redis connection, or return null if Redis is unavailable for ANY
 * reason (extension missing, connection refused, auth failure, timeout...).
 *
 * @param float $timeout    connect timeout in seconds (keep short on the
 *                          publish path so a dead Redis never stalls sends)
 * @param bool  $persistent use pconnect() to reuse sockets across requests
 *                          (good for publishing; never use for subscribe)
 */
function chatRealtimeConnect(float $timeout = 1.0, bool $persistent = false): ?Redis
{
    if (!extension_loaded('redis') || !class_exists('Redis')) {
        return null;
    }

    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);

    try {
        $redis = new Redis();
        $ok = $persistent
            ? @$redis->pconnect($host, $port, $timeout, 'flixo_chat')
            : @$redis->connect($host, $port, $timeout);

        if (!$ok) {
            return null;
        }

        $pass = getenv('REDIS_PASSWORD');
        if ($pass !== false && $pass !== '') {
            $redis->auth($pass);
        }
        $db = getenv('REDIS_DB');
        if ($db !== false && $db !== '') {
            $redis->select((int) $db);
        }

        return $redis;
    } catch (Throwable $e) {
        error_log('chatRealtimeConnect: ' . $e->getMessage());
        return null;
    }
}

/**
 * Publish a "new message" signal for a conversation. No-op (and never throws)
 * when Redis is unavailable — the message is already persisted in MySQL, so the
 * subscriber's DB fallback / reconnect catch-up will still deliver it.
 *
 * @param array<string,mixed> $data optional extra payload
 */
function chatRealtimePublish(string $sender, string $receiver, array $data = []): void
{
    // Short timeout + persistent connection so a down Redis adds minimal latency
    // to the message-send request path.
    $redis = chatRealtimeConnect(0.5, true);
    if (!$redis instanceof Redis) {
        return;
    }

    try {
        $channel = chatRealtimeChannel($sender, $receiver);
        $payload = json_encode($data ?: ['t' => time()], JSON_UNESCAPED_UNICODE);
        $redis->publish($channel, $payload);
    } catch (Throwable $e) {
        error_log('chatRealtimePublish: ' . $e->getMessage());
    }
    // Persistent connection: intentionally NOT closed (returned to the pool).
}
