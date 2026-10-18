<?php

declare(strict_types=1);

namespace CPSIT\MyraApiEmulator\Tests;

use CPSIT\MyraApiEmulator\Server;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The emulator over HTTP, as a client sees it. The answers themselves are covered where they
 * are used: the integration tests of cpsit/myra-web-api run its clients against this emulator.
 */
#[CoversNothing]
final class EmulatorTest extends TestCase
{
    private const TOKEN = 'Authorization: Bearer test-token';

    private static ?Server $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new Server();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        self::server()->reset();
    }

    public function testListsTheCapturedDomains(): void
    {
        [$status, $body] = self::request(self::server(), 'GET', '/domains');

        self::assertSame(200, $status);
        self::assertSame(['example.com', 'example.org'], array_column(self::data($body), 'name'));
    }

    public function testAWrongTokenIsDenied(): void
    {
        [$status] = self::request(self::server(), 'GET', '/domains', [], ['Authorization: Bearer wrong-token']);

        self::assertSame(403, $status);
    }

    public function testAnUnknownRouteIsNotEmulated(): void
    {
        [$status, $body] = self::request(self::server(), 'GET', '/no-such-area');

        self::assertSame(404, $status);
        self::assertStringContainsString('Not emulated: GET /no-such-area', $body);
    }

    /**
     * A project's domains from an account file, as an admin set them up in Myra: listed after
     * the captured ones, with their DNS records, and cleared within their zone only.
     */
    public function testAnAccountFileAddsTheDomainsOfAProject(): void
    {
        $account = (string) tempnam(sys_get_temp_dir(), 'myra-account-');
        file_put_contents($account, json_encode(['domains' => [
            ['name' => 'example.net', 'records' => ['www.example.net', ['name' => 'old.example.net', 'active' => false]]],
        ]]));
        $server = new Server(['MYRA_EMULATOR_ACCOUNT' => $account]);

        try {
            [, $domains] = self::request($server, 'GET', '/domains');
            self::assertSame(['example.com' => 1000, 'example.org' => 1140, 'example.net' => 4711], array_column(self::data($domains), 'id', 'name'));

            [, $records] = self::request($server, 'GET', '/domain/4711/dns-records');
            self::assertSame(['www.example.net' => true, 'old.example.net' => false], array_column(self::data($records), 'active', 'name'));

            [$status, $cleared] = self::request($server, 'PUT', '/domain/4711/cache-clear', ['fqdn' => 'www.example.net', 'resource' => '/']);
            self::assertSame([200, 'www.example.net'], [$status, self::data($cleared)[0]['fqdn'] ?? null]);

            [$status, $rejected] = self::request($server, 'PUT', '/domain/4711/cache-clear', ['fqdn' => 'www.example.com', 'resource' => '/']);
            self::assertSame(403, $status);
            self::assertStringContainsString('DOMAIN_DOES_NOT_EXIST', $rejected);
        } finally {
            $server->stop();
            unlink($account);
        }
    }

    /**
     * The failure paths on demand: `reject` like wrong credentials, `down` like an outage;
     * reset() switches back.
     */
    public function testTheModesRejectOrTakeMyraDown(): void
    {
        self::server()->mode('reject');
        self::assertSame(403, self::request(self::server(), 'GET', '/domains')[0]);

        self::server()->mode('down');
        self::assertSame(503, self::request(self::server(), 'GET', '/domains')[0]);
        self::assertSame([200, '{"mode":"down"}'], self::request(self::server(), 'GET', '/_emulator/mode', [], []));

        self::request(self::server(), 'POST', '/_emulator/reset', [], []);
        self::assertSame(200, self::request(self::server(), 'GET', '/domains')[0]);
    }

    /**
     * A broken emulator must not look like a success (the built-in server answers a fatal
     * error with HTTP 200).
     */
    public function testAnUnreadableAccountFileIsAServerError(): void
    {
        $server = new Server(['MYRA_EMULATOR_ACCOUNT' => sys_get_temp_dir() . '/no-such-myra-account.json']);

        try {
            [$status, $body] = self::request($server, 'GET', '/domains');
            self::assertSame(500, $status);
            self::assertStringContainsString('Unreadable account file', $body);
        } finally {
            $server->stop();
        }
    }

    public function testAnUnknownModeIsRefused(): void
    {
        self::assertSame(400, self::request(self::server(), 'POST', '/_emulator/mode', ['mode' => 'broken'], [])[0]);
    }

    public function testLogsTheCallsButNotTheControlRequests(): void
    {
        self::request(self::server(), 'PUT', '/domain/1000/cache-clear', ['fqdn' => 'www.example.com', 'resource' => '/']);
        self::request(self::server(), 'GET', '/_emulator/mode', [], []);

        $calls = self::server()->calls();
        self::assertSame([['PUT', '/domain/1000/cache-clear', 200, true]], array_map(static fn(array $c): array => [$c['method'], $c['uri'], $c['status'], $c['auth_ok']], $calls));
    }

    /**
     * @param non-empty-string $method
     * @param array<string, mixed> $body
     * @param list<string>|null $headers null: the Bearer token
     * @return array{int, string}
     */
    private static function request(Server $server, string $method, string $path, array $body = [], ?array $headers = null): array
    {
        $curl = curl_init($server->baseUri() . $path);
        if ($curl === false) {
            throw new LogicException('No curl handle.');
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [...($headers ?? [self::TOKEN]), 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body === [] ? '' : (string) json_encode($body),
        ]);
        $answer = curl_exec($curl);

        return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), is_string($answer) ? $answer : ''];
    }

    /**
     * @return list<array<mixed>>
     */
    private static function data(string $body): array
    {
        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        if (!is_array($data)) {
            throw new LogicException('No data in ' . $body);
        }

        return array_values(array_filter($data, is_array(...)));
    }

    private static function server(): Server
    {
        return self::$server ?? throw new LogicException('The emulator is not running.');
    }
}
