<?php

declare(strict_types=1);

/**
 * Myra API emulator (v1 and v2) for integration tests and local development.
 *
 *     php -S 127.0.0.1:8080 public/index.php
 *
 * Answers come from fixtures/ (anonymised captures, see docs/captures): the success
 * bodies, and the failures as Myra sends them — v1 `text/plain` 403 for a wrong secret,
 * empty 403 for an unknown v1 domain or a wrong v2 token, 401 with an error string without
 * a token, violations for rejected requests.
 *
 * ASSUMED, not captured (each answered like its captured neighbour):
 * - v1 without Authorization header or with a wrong token → the v1 wrong-secret answer;
 * - v2 with wrong HMAC credentials → the v2 wrong-token answer;
 * - v1 unknown domain on PUT cacheClear → the GET dnsRecords answer;
 * - v1 `recursive` not a boolean → the v2 wrong-type answer;
 * - paging (MYRA_EMULATOR_PAGE_SIZE cuts the captured lists), the echo of a cache clear
 *   (built like the captured ones) and the 404 "Not emulated" envelope;
 * - writes of per-vhost areas (redirects, IP filter, cache settings) are echoed in the captured shapes but
 *   stateless: nothing is stored, a stale `modified` is not detected; v2 lists of
 *   www.example.com are the v1 capture's objects in a v2 envelope; the IP filter export is
 *   built from the captured list;
 * - error pages: the captured answers whatever the selection; nothing is stored;
 * - maintenance: the account has none, so both vhosts answer the probe's captured list;
 * - DNS record writes are echoed in the captured shapes (stateless); the dedicated v2 load
 *   balancing PUT answers the captured HTTP 500;
 * - users and organisation: writes echoed (spec only), deletes 204, the permission check grants;
 * - domain writes: echoed (manual/spec only), deletes answer like the other deletes;
 * - zones (BIND): every domain answers the captured zone of example.org;
 * - SSL certificates: writes echoed without certificate, key and password (Myra never returns
 *   them — manual); v2 lists of example.org are the empty v1 list;
 * - subdomains: the account list ignores paging (captured) and filters like Myra; a verification
 *   check answers 409 DOMAIN_VERIFICATION_ALREADY_VERIFIED (spec; the account's domains are verified);
 * - statistics: the captured answers whatever the query or period; v2 dashboard: example.org
 *   answers the quiet domain's captures, histogram charts other than total-* are unknown;
 * - subdomain settings: v2 settings of www.example.com are the v1 capture, `?flat` answers
 *   the apex (v1) or the probed vhost (v2) capture whatever the vhost; POST is echoed.
 * - an account file (MYRA_EMULATOR_ACCOUNT) adds the domains of a project, as an admin set
 *   them up in Myra: v2 lists them after the captured ones (ids from 4711), lists their DNS
 *   records and takes their cache clears, with the same zone check. Their objects take the
 *   captured shape of the first list entry. Everything else of them is not emulated, and v1
 *   does not see them.
 * - the modes: `reject` answers every call like wrong credentials, `down` with HTTP 503.
 *
 * Authentication is checked independently of the package's own code: HMAC recomputed
 * after the v2 spec (docs/v2/openapi.json, info.description §2.1.2), or a Bearer token.
 * Both work with both versions, like at Myra.
 *
 * Environment (all optional):
 *   MYRA_EMULATOR_KEY / MYRA_EMULATOR_SECRET   HMAC credentials (test-key / test-secret)
 *   MYRA_EMULATOR_TOKEN                        Bearer token (test-token)
 *   MYRA_EMULATOR_PAGE_SIZE                    items per page (50, like Myra)
 *   MYRA_EMULATOR_LOG                          JSON-lines call log (sys_get_temp_dir()/myra-emulator.jsonl)
 *   MYRA_EMULATOR_ACCOUNT                      JSON file with the domains of a project (none):
 *       {"domains": [{"name": "example.net", "records": ["example.net", "www.example.net"]}]}
 *       a record is a name (an active A record) or a DnsRecordVO's fields, e.g.
 *       {"name": "old.example.net", "active": false}
 *   MYRA_EMULATOR_STATE                        runtime state: the mode (sys_get_temp_dir()/myra-emulator-state.json)
 *
 * Control (not logged): GET /_emulator/calls, POST /_emulator/reset (calls and mode),
 * GET|POST /_emulator/mode with {"mode": "normal"|"reject"|"down"}.
 */

namespace CPSIT\MyraApiEmulator;

use Throwable;

// Without Composer: the image runs this file as it is.
require_once __DIR__ . '/../src/Paths.php';
require_once __DIR__ . '/../src/Answer.php';
require_once __DIR__ . '/../src/Emulator.php';

// ---------------------------------------------------------------- request handling

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$body = (string) file_get_contents('php://input');
// Only the headers the emulator needs (the built-in server puts Content-Length without HTTP_).
$headers = [];
foreach (['authorization' => 'HTTP_AUTHORIZATION', 'date' => 'HTTP_DATE', 'content-length' => 'CONTENT_LENGTH', 'content-type' => 'CONTENT_TYPE'] as $name => $key) {
    if (is_string($_SERVER[$key] ?? null)) {
        $headers[$name] = $_SERVER[$key];
    }
}
$log = Emulator::env('MYRA_EMULATOR_LOG', sys_get_temp_dir() . '/myra-emulator.jsonl');

if (str_starts_with($uri, '/_emulator/')) {
    header('Content-Type: application/json');
    if ($method === 'POST' && $uri === '/_emulator/reset') {
        file_put_contents($log, '');
        Emulator::setMode('normal');
        echo '{"reset":true}';
    } elseif ($uri === '/_emulator/mode') {
        $requested = json_decode($body, true);
        if ($method === 'POST' && !(is_array($requested) && is_string($requested['mode'] ?? null) && Emulator::setMode($requested['mode']))) {
            http_response_code(400);
            echo '{"error":"mode must be normal, reject or down"}';

            return;
        }
        echo json_encode(['mode' => Emulator::mode()]);
    } else {
        $lines = is_file($log) ? (array) file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        echo '[' . implode(',', $lines) . ']';
    }

    return;
}

$emulator = new Emulator($method, $uri, $body, $headers);
try {
    $answer = $emulator->handle();
} catch (Throwable $exception) {
    // A broken emulator (e.g. an unreadable account file) must not look like a success: the
    // built-in server would print the error with HTTP 200.
    $answer = new Answer(500, 'application/json', ['error' => true, 'violationList' => [['propertyPath' => '', 'message' => 'Emulator error: ' . $exception->getMessage()]], 'warningList' => []]);
}
$authorization = $headers['authorization'] ?? '';
file_put_contents($log, json_encode([
    'method' => $method,
    'uri' => $uri,
    'auth' => str_starts_with($authorization, 'MYRA ') ? 'hmac' : (str_starts_with($authorization, 'Bearer ') ? 'bearer' : 'none'),
    'auth_ok' => $authorization !== '' && $emulator->authenticated($authorization),
    'body' => $body,
    // announced vs received: a difference means the client sent a body stream it had not rewound
    'content_length' => isset($headers['content-length']) ? (int) $headers['content-length'] : null, // string → int
    'body_bytes' => strlen($body),
    'status' => $answer->status,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
$answer->send();
