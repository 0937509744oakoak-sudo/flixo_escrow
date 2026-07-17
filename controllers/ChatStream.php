<?php
// controllers/ChatStream.php
//
// Server-Sent Events (SSE) endpoint that pushes chat updates in near real-time,
// replacing the old 3-second HTTP polling in assets/js/chat.js.
//
// Design notes for horizontal scaling / not blocking other requests:
//   1. session_write_close() is called IMMEDIATELY after reading the session.
//      PHP holds an exclusive lock on the session file for the whole request;
//      without releasing it, this long-lived stream would block EVERY other
//      request from the same user (send message, fetch, navigate...).
//   2. X-Accel-Buffering: no  disables proxy buffering (Nginx) so events are
//      flushed to the client instantly instead of being held in a buffer.
//   3. Bounded duration (MAX_LIFETIME): the stream self-terminates after ~30s
//      and the browser's EventSource auto-reconnects (resuming from Last-Event-ID).
//      This prevents a single connection from pinning a PHP worker forever and
//      lets load balancers rotate connections across instances.
//   4. Reads only rows with id > lastId (lightweight), and works across
//      multiple app instances because every instance shares the same MySQL.

require_once __DIR__ . '/../includes/init.php';

// --- Read auth state, then release the session lock right away ---
$me = $_SESSION['user'] ?? null;
$partner = trim($_GET['partner'] ?? '');
session_write_close(); // ★ critical: unblock this user's other requests

// --- SSE headers ---
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // ★ disable Nginx/proxy buffering

// Discard any output buffering so flush() reaches the client immediately.
while (ob_get_level() > 0) {
    ob_end_flush();
}

if ($me === null) {
    // Not authenticated: tell the client to stop retrying, then exit.
    echo "event: unauthorized\n";
    echo "data: {}\n\n";
    @flush();
    exit;
}

if ($partner === '') {
    echo "event: error\n";
    echo "data: {\"message\":\"missing partner\"}\n\n";
    @flush();
    exit;
}

// Where to resume from: EventSource resends the last id via Last-Event-ID header.
$lastId = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last_id'] ?? 0);

@set_time_limit(0);
ignore_user_abort(false); // detect client disconnects so we stop promptly

$start        = time();
$MAX_LIFETIME = 30;    // seconds before we close and let the client reconnect
$POLL_MS      = 1500;  // gap between DB checks (lightweight id > lastId query)

// Suggest the reconnect delay (ms) to the browser.
echo "retry: 3000\n\n";
@flush();

/**
 * Send one SSE frame.
 * @param array<string,mixed> $data
 */
$sendEvent = static function (string $event, array $data, ?int $id = null): void {
    if ($id !== null) {
        echo 'id: ' . $id . "\n";
    }
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
};

$stmt = $conn->prepare(
    "SELECT id, sender_name FROM messages
      WHERE id > ?
        AND ((sender_name = ? AND receiver_name = ?)
          OR (sender_name = ? AND receiver_name = ?))
      ORDER BY id ASC"
);

if ($stmt === false) {
    $sendEvent('error', ['message' => 'query failed']);
    exit;
}

// Fresh connection (no Last-Event-ID / last_id): baseline to the newest message
// so we DON'T replay the whole history as events — the client already loads
// existing messages once via fetchMessages(). We only push what arrives after.
if ($lastId === 0) {
    $baseStmt = $conn->prepare(
        "SELECT COALESCE(MAX(id), 0) AS max_id FROM messages
          WHERE (sender_name = ? AND receiver_name = ?)
             OR (sender_name = ? AND receiver_name = ?)"
    );
    if ($baseStmt) {
        $baseStmt->bind_param('ssss', $me, $partner, $partner, $me);
        $baseStmt->execute();
        $baseRow = $baseStmt->get_result()->fetch_assoc();
        $lastId = (int) ($baseRow['max_id'] ?? 0);
        $baseStmt->close();
    }
}

// Emit every message newer than $lastId, advance $lastId, return how many.
// Shared by BOTH the Redis path and the DB-poll fallback so behaviour matches.
$emitNew = static function () use ($stmt, &$lastId, $me, $partner, $sendEvent): int {
    $stmt->bind_param('issss', $lastId, $me, $partner, $partner, $me);
    $stmt->execute();
    $res = $stmt->get_result();
    $n = 0;
    while ($row = $res->fetch_assoc()) {
        $lastId = (int) $row['id'];
        $n++;
        // Client re-renders via fetchMessages() on any 'message' event, so we
        // keep the payload minimal. id becomes the Last-Event-ID for resume.
        $sendEvent('message', [
            'id'    => $lastId,
            'is_me' => ($row['sender_name'] === $me),
        ], $lastId);
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    return $n;
};

// ---------------------------------------------------------------------------
// Preferred path: Redis Pub/Sub. Instant delivery, NO per-iteration DB polling.
// If Redis is missing / down / errors out at any point, we transparently fall
// back to the DB-poll loop below (graceful degradation).
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../includes/chat_realtime.php';
$redis = chatRealtimeConnect(1.5); // non-persistent: subscribe monopolises it

if ($redis instanceof Redis) {
    $channel = chatRealtimeChannel($me, $partner);
    $redisOk = true;

    try {
        // Catch up once on anything inserted before we subscribed.
        $emitNew();

        // subscribe() blocks; we cap each block with a read timeout so we can
        // still send keep-alives and honour connection_aborted() + MAX_LIFETIME.
        $PING_WINDOW = 15; // seconds per blocking window
        while (!connection_aborted() && (time() - $start) < $MAX_LIFETIME) {
            $remaining = $MAX_LIFETIME - (time() - $start);
            $window = (int) max(1, min($PING_WINDOW, $remaining));
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $window);

            try {
                $redis->subscribe([$channel], static function ($r, $chan, $payload) use ($emitNew) {
                    $emitNew();        // a peer published → push new rows now
                    $r->unsubscribe(); // hand control back to the outer loop
                });
            } catch (RedisException $e) {
                // Expected when no message arrives within the window (read
                // timeout). A truly dead socket also lands here → reconnect.
                try { $redis->close(); } catch (Throwable $ignore) {}
                $redis = chatRealtimeConnect(1.5);
                if (!$redis instanceof Redis) {
                    $redisOk = false; // Redis gone → DB-poll fallback
                    break;
                }
            }

            // Keep-alive so idle connections aren't dropped by intermediaries.
            echo ": ping " . time() . "\n\n";
            @flush();
        }
    } catch (Throwable $e) {
        error_log('ChatStream: Redis loop error, falling back to DB poll — ' . $e->getMessage());
        $redisOk = false;
    }

    if ($redisOk) {
        if ($redis instanceof Redis) {
            try { $redis->close(); } catch (Throwable $ignore) {}
        }
        $stmt->close();
        exit;
    }
    // else: fall through to the DB-poll loop for the remaining lifetime.
}

// ---------------------------------------------------------------------------
// Fallback path: DB polling (Redis missing/down). Lightweight — selects only
// rows with id > lastId every $POLL_MS.
// ---------------------------------------------------------------------------
while (!connection_aborted() && (time() - $start) < $MAX_LIFETIME) {
    $emitNew();

    echo ": ping " . time() . "\n\n";
    @flush();

    if (connection_aborted()) {
        break;
    }
    usleep($POLL_MS * 1000);
}

$stmt->close();
exit;
