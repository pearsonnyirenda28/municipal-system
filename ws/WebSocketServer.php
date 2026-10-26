<?php
/**
 * ws/WebSocketServer.php
 * See ws/server.php for the "why no library" reasoning.
 *
 * Protocol notes for anyone reading this without RFC 6455 open:
 *  - The handshake is a plain HTTP GET with an Upgrade: websocket header;
 *    the server replies with 101 Switching Protocols and a
 *    Sec-WebSocket-Accept header derived from the client's key.
 *  - After that, both sides speak in "frames": a small binary header
 *    (fin/opcode/mask bit/length) followed by the payload. Frames FROM
 *    the client MUST be masked (the spec's XOR obfuscation against
 *    naive proxies); frames FROM the server must NOT be.
 *  - This server only ever sends opcode 0x1 (text) frames containing
 *    JSON, and only needs to understand opcode 0x8 (close) and 0x9
 *    (ping) coming back.
 */

final class WebSocketServer
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'; // fixed by RFC 6455, not a secret
    private const POLL_INTERVAL_SECONDS = 3;

    /** @var resource */
    private $listener;

    /** Keyed by (int) socket resource id. Each: socket, handshakeDone, recvBuffer, userId, role, lastHash, lastPoll */
    private array $clients = [];

    public function __construct(private int $port)
    {
        $address = "tcp://0.0.0.0:{$port}";
        $listener = @stream_socket_server($address, $errno, $errstr);
        if ($listener === false) {
            fwrite(STDERR, "Could not start WebSocket server on port {$port}: {$errstr} (errno {$errno})\n");
            fwrite(STDERR, "If that says 'Address already in use', the server is probably already running.\n");
            exit(1);
        }
        $this->listener = $listener;
        echo "WebSocket server listening on ws://0.0.0.0:{$port}\n";
        echo "Polling every " . self::POLL_INTERVAL_SECONDS . "s per connected client. Ctrl+C to stop.\n";
    }

    public function run(): void
    {
        while (true) {
            $read = [$this->listener];
            foreach ($this->clients as $c) {
                $read[] = $c['socket'];
            }

            $write = $except = [];
            // 1-second select timeout so we still get a chance to poll
            // for notifications even when nobody's sending us anything.
            $changed = @stream_select($read, $write, $except, 1);
            if ($changed === false) {
                continue; // interrupted by a signal (e.g. Ctrl+C) - loop back around
            }

            foreach ($read as $socket) {
                if ($socket === $this->listener) {
                    $this->acceptConnection();
                } else {
                    $this->handleClientData($socket);
                }
            }

            $this->pollNotifications();
        }
    }

    private function acceptConnection(): void
    {
        $socket = @stream_socket_accept($this->listener, 0);
        if ($socket === false) {
            return;
        }
        stream_set_blocking($socket, false);
        $id = (int) $socket;
        $this->clients[$id] = [
            'socket' => $socket, 'handshakeDone' => false, 'recvBuffer' => '',
            'userId' => null, 'role' => null, 'lastHash' => null, 'lastPoll' => 0,
        ];
    }

    private function handleClientData($socket): void
    {
        $id = (int) $socket;
        if (!isset($this->clients[$id])) {
            return;
        }
        $chunk = @fread($socket, 8192);
        if ($chunk === false || $chunk === '') {
            $this->disconnect($id);
            return;
        }
        $this->clients[$id]['recvBuffer'] .= $chunk;

        if (!$this->clients[$id]['handshakeDone']) {
            $this->tryHandshake($id);
            return;
        }

        $this->consumeFrames($id);
    }

    // ------------------------------------------------------------
    // Handshake
    // ------------------------------------------------------------

    private function tryHandshake(int $id): void
    {
        $buffer = $this->clients[$id]['recvBuffer'];
        if (!str_contains($buffer, "\r\n\r\n")) {
            return; // headers not fully received yet - wait for more data
        }

        [$headerBlock] = explode("\r\n\r\n", $buffer, 2);
        $lines = explode("\r\n", $headerBlock);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        $socket = $this->clients[$id]['socket'];

        if (empty($headers['sec-websocket-key'])) {
            fwrite($socket, "HTTP/1.1 400 Bad Request\r\n\r\n");
            $this->disconnect($id);
            return;
        }

        $accept = base64_encode(sha1($headers['sec-websocket-key'] . self::GUID, true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
        fwrite($socket, $response);

        $this->clients[$id]['handshakeDone'] = true;
        $this->clients[$id]['recvBuffer'] = '';

        // Authenticate using the same PHPSESSID cookie the browser
        // already sends on every normal page request - the WebSocket
        // handshake is still a plain HTTP request, so the cookie rides
        // along automatically (httponly blocks JS reading it, not the
        // browser sending it).
        $sessionId = $this->extractSessionId($headers['cookie'] ?? '');
        [$userId, $role] = $sessionId ? $this->lookupSession($sessionId) : [null, null];
        if ($userId && !$this->sessionIsStillValid($sessionId, $userId)) {
            $userId = null;   // timed out or deactivated: treat as not logged in
        }

        if (!$userId) {
            // No valid session - nothing to push. Close politely (4001
            // is in the "application-specific" custom code range the
            // spec reserves) rather than leaving a dead connection open.
            $this->sendFrame($socket, json_encode(['type' => 'auth_error', 'message' => 'Not logged in.']));
            $this->sendClose($socket, 4001, 'unauthenticated');
            $this->disconnect($id);
            return;
        }

        $this->clients[$id]['userId'] = $userId;
        $this->clients[$id]['role'] = $role;
        $this->clients[$id]['sessionId'] = $sessionId;
        // Force an immediate check on the next loop tick instead of
        // waiting a full POLL_INTERVAL after connecting.
        $this->clients[$id]['lastPoll'] = 0;
    }

    private function extractSessionId(string $cookieHeader): ?string
    {
        foreach (explode(';', $cookieHeader) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name === (defined('SESSION_COOKIE_NAME') ? SESSION_COOKIE_NAME : session_name())) {
                return $value !== '' ? $value : null;
            }
        }
        return null;
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function lookupSession(string $sessionId): array
    {
        // Deliberately NOT session_id()+session_start(): both silently
        // fail on every call after this script's first console output,
        // because PHP's session_start() always tries to send a
        // Set-Cookie header, and PHP treats any prior echo/print as
        // "headers already sent" even in CLI SAPI where there's no real
        // HTTP response to send headers on. session_decode() has the
        // same problem (it needs an active session, which needs
        // session_start()). Confirmed by testing - both approaches
        // return an empty session on every lookup after the very first
        // line this script prints, which would have made every user
        // after the first appear logged out.
        //
        // Reading and parsing the session file directly sidesteps all
        // of that. Handles exactly the value types this app's $_SESSION
        // actually uses (see includes/auth.php - user_id, full_name,
        // role, last_activity are all string/int) and stops rather than
        // guessing if it hits anything else.
        $path = session_save_path() . '/sess_' . basename($sessionId);
        if (!is_file($path)) {
            return [null, null];
        }

        $data = $this->parsePhpSessionFile((string) file_get_contents($path));
        $userId = $data['user_id'] ?? null;
        $role = $data['role'] ?? null;

        return [$userId !== null ? (int) $userId : null, $role !== null ? (string) $role : null];
    }

    /**
     * Is this session still allowed to receive data? The handshake used to be the ONLY check,
     * so a tab left open stayed connected - and kept receiving notifications - after its
     * session idled out, after logout elsewhere, and after an administrator deactivated the
     * account. Re-checked on every poll, using the same rules as includes/auth.php:
     * the session file must still exist, must not have idled past SESSION_TIMEOUT_SECONDS,
     * and the account must still be active with the same password it logged in with.
     */
    private function sessionIsStillValid(string $sessionId, int $userId): bool
    {
        $path = session_save_path() . '/sess_' . basename($sessionId);
        if (!is_file($path)) {
            return false;                       // logged out, or the session was cleaned up
        }
        $data = $this->parsePhpSessionFile((string) file_get_contents($path));
        if ((int) ($data['user_id'] ?? 0) !== $userId) {
            return false;
        }
        $last = (int) ($data['last_activity'] ?? 0);
        if ($last > 0 && (time() - $last) > SESSION_TIMEOUT_SECONDS) {
            return false;                       // idle timeout (WebSocket traffic is not user activity)
        }
        try {
            try {
                $stmt = getDb()->prepare('SELECT is_active, password_hash FROM Users WHERE user_id = :id');
                $stmt->execute(['id' => $userId]);
                $u = $stmt->fetch();
            } catch (Throwable $noColumn) {      // is_active is optional - see sessionProblem() in includes/auth.php
                $stmt = getDb()->prepare('SELECT password_hash FROM Users WHERE user_id = :id');
                $stmt->execute(['id' => $userId]);
                $u = $stmt->fetch();
                if ($u) { $u['is_active'] = null; }
            }
        } catch (Throwable $e) {
            return true;                        // cannot tell - do not cut everyone off over a database hiccup
        }
        if (!$u || ($u['is_active'] !== null && (int) $u['is_active'] === 0)) {
            return false;
        }
        if (!empty($data['pw_fingerprint'])
            && !hash_equals((string) $data['pw_fingerprint'], hash('sha256', (string) $u['password_hash']))) {
            return false;                       // password changed or reset since this session logged in
        }
        return true;
    }

    private function parsePhpSessionFile(string $raw): array
    {
        $result = [];
        $offset = 0;
        $len = strlen($raw);

        while ($offset < $len) {
            $pipePos = strpos($raw, '|', $offset);
            if ($pipePos === false) {
                break;
            }
            $key = substr($raw, $offset, $pipePos - $offset);
            $offset = $pipePos + 1;
            $type = $raw[$offset] ?? '';

            if ($type === 's') {
                if (!preg_match('/^s:(\d+):"/', substr($raw, $offset), $m)) break;
                $strLen = (int) $m[1];
                $headerLen = strlen($m[0]);
                $result[$key] = substr($raw, $offset + $headerLen, $strLen);
                $offset += $headerLen + $strLen + 2; // +2 for the closing "; 
            } elseif ($type === 'i') {
                if (!preg_match('/^i:(-?\d+);/', substr($raw, $offset), $m)) break;
                $result[$key] = (int) $m[1];
                $offset += strlen($m[0]);
            } elseif ($type === 'b') {
                if (!preg_match('/^b:([01]);/', substr($raw, $offset), $m)) break;
                $result[$key] = (bool) $m[1];
                $offset += strlen($m[0]);
            } elseif ($type === 'N' && substr($raw, $offset, 2) === 'N;') {
                $result[$key] = null;
                $offset += 2;
            } else {
                break; // array/object/float or anything unexpected - stop rather than misparse
            }
        }

        return $result;
    }

    // ------------------------------------------------------------
    // Frame decoding (client -> server): we only act on close/ping,
    // everything else is read and discarded.
    // ------------------------------------------------------------

    private function consumeFrames(int $id): void
    {
        $buffer = &$this->clients[$id]['recvBuffer'];

        while (strlen($buffer) >= 2) {
            $byte1 = ord($buffer[0]);
            $byte2 = ord($buffer[1]);
            $opcode = $byte1 & 0x0F;
            $masked = ($byte2 & 0x80) !== 0;
            $len = $byte2 & 0x7F;

            $offset = 2;
            if ($len === 126) {
                if (strlen($buffer) < 4) return;
                $len = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if (strlen($buffer) < 10) return;
                $high = unpack('N', substr($buffer, 2, 4))[1];
                $low = unpack('N', substr($buffer, 6, 4))[1];
                $len = ($high << 32) + $low;
                $offset = 10;
            }

            $maskKey = '';
            if ($masked) {
                if (strlen($buffer) < $offset + 4) return;
                $maskKey = substr($buffer, $offset, 4);
                $offset += 4;
            }

            if (strlen($buffer) < $offset + $len) {
                return; // full frame not received yet
            }

            $payload = substr($buffer, $offset, $len);
            if ($masked) {
                $unmasked = '';
                for ($i = 0; $i < $len; $i++) {
                    $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
                }
                $payload = $unmasked;
            }

            $buffer = substr($buffer, $offset + $len);

            match ($opcode) {
                0x8 => $this->disconnect($id),                      // close
                0x9 => $this->sendPong($id, $payload),               // ping
                default => null,                                     // text/binary/pong from client - not needed
            };

            if (!isset($this->clients[$id])) {
                return; // disconnect() above already tore this client down
            }
        }
    }

    private function sendPong(int $id, string $payload): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $this->sendFrame($this->clients[$id]['socket'], $payload, 0xA);
    }

    // ------------------------------------------------------------
    // Frame encoding (server -> client): never masked, per spec.
    // ------------------------------------------------------------

    private function sendFrame($socket, string $payload, int $opcode = 0x1): void
    {
        $len = strlen($payload);
        $header = chr(0x80 | $opcode); // FIN=1

        if ($len <= 125) {
            $header .= chr($len);
        } elseif ($len <= 65535) {
            $header .= chr(126) . pack('n', $len);
        } else {
            $header .= chr(127) . pack('N', 0) . pack('N', $len);
        }

        @fwrite($socket, $header . $payload);
    }

    private function sendClose($socket, int $code, string $reason): void
    {
        $this->sendFrame($socket, pack('n', $code) . $reason, 0x8);
    }

    private function disconnect(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]['socket']);
            unset($this->clients[$id]);
        }
    }

    // ------------------------------------------------------------
    // Notification polling - reuses includes/notifications.php exactly
    // as the header bell does, so there is exactly one place that
    // decides what counts as a notification.
    // ------------------------------------------------------------

    private function pollNotifications(): void
    {
        if (!$this->clients) {
            return;
        }

        $now = time();
        $db = null;

        foreach ($this->clients as $id => $client) {
            if (!$client['handshakeDone'] || !$client['userId']) {
                continue;
            }
            if ($now - $client['lastPoll'] < self::POLL_INTERVAL_SECONDS) {
                continue;
            }

            $db ??= getDb(); // lazy-open, and reused for every client this tick

            if (!$this->sessionIsStillValid((string) ($client['sessionId'] ?? ''), (int) $client['userId'])) {
                $this->sendFrame($client['socket'], json_encode(['type' => 'auth_error', 'message' => 'Your session has ended.']));
                $this->sendClose($client['socket'], 4001, 'session ended');
                $this->disconnect($id);
                continue;
            }

            try {
                $items = getNotifications($db, ['id' => $client['userId'], 'role' => $client['role']]);
            } catch (Throwable $e) {
                fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Notification poll failed for user {$client['userId']}: " . $e->getMessage() . "\n");
                $this->clients[$id]['lastPoll'] = $now;
                continue;
            }

            $hash = md5(json_encode($items));
            $this->clients[$id]['lastPoll'] = $now;

            if ($hash === $client['lastHash']) {
                continue; // nothing changed for this user since last check
            }

            $isFirstPush = $client['lastHash'] === null;
            $this->clients[$id]['lastHash'] = $hash;

            $payload = [
                'type' => 'notifications',
                'count' => array_sum(array_column($items, 'count')),
                'items' => $items,
                // The client only pops a toast for genuinely new activity,
                // not for the first snapshot right after connecting.
                'isInitial' => $isFirstPush,
            ];
            $this->sendFrame($client['socket'], json_encode($payload));
        }
    }
}
