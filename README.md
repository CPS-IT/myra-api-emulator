# Myra API emulator

A local stand-in for the [Myra Cloud](https://www.myrasecurity.com/) API, v1 and v2, for development
and tests: no request reaches Myra, and a client cannot tell the difference. Every answer is a real
one — captured from the Myra API, anonymised — so the shapes, error messages and status codes are
Myra's, not ours to get wrong.

It is used by [cpsit/myra-web-api](https://github.com/CPS-IT/myra-web-api) for its integration tests
and by TYPO3 projects with [cpsit/myra-cloud-connector](https://github.com/CPS-IT/myra-cloud-connector)
for local CDN cache clearing.

## Container image

```bash
docker run --rm -p 8080:8080 ghcr.io/cps-it/myra-api-emulator:1
curl -H 'Authorization: Bearer test-token' http://127.0.0.1:8080/domains
```

`linux/amd64` and `linux/arm64`, tags `1.2.3`, `1.2`, `1` per release and `main`. Pin a version.

### As a DDEV service

`.ddev/docker-compose.myra.yaml`:

```yaml
services:
  myra:
    container_name: ddev-${DDEV_SITENAME}-myra
    image: ghcr.io/cps-it/myra-api-emulator:1.0.0
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
    expose:
      - "8080"
    environment:
      - VIRTUAL_HOST=$DDEV_HOSTNAME
      - HTTPS_EXPOSE=8446:8080
      - MYRA_EMULATOR_ACCOUNT=/etc/myra/account.json
    volumes:
      - "./myra/account.json:/etc/myra/account.json:ro"
```

The client's endpoint is then `https://<project>.ddev.site:8446` — only configuration changes, no
code.

## The account

Without configuration the emulator is the captured account: `example.com` (id 1000) and
`example.org` (id 1140). A project adds its own domains with an account file — what an admin set up
in the Myra dashboard:

```json
{
    "domains": [
        {
            "name": "example.net",
            "records": ["example.net", "www.example.net", {"name": "old.example.net", "active": false}]
        }
    ]
}
```

A record given as a name is an active A record; given as an object, its fields override that. API
v2 lists the domains after the captured ones (ids from 4711), lists their DNS records and takes
their cache clears. The zone is checked like Myra does: clearing a host outside the domain is
rejected with `DOMAIN_DOES_NOT_EXIST`.

## Failure paths

```bash
curl -X POST -d '{"mode":"reject"}' http://127.0.0.1:8080/_emulator/mode   # every call: wrong credentials (403)
curl -X POST -d '{"mode":"down"}'   http://127.0.0.1:8080/_emulator/mode   # every call: HTTP 503
curl -X POST http://127.0.0.1:8080/_emulator/reset                          # empty the call log, mode normal
curl http://127.0.0.1:8080/_emulator/calls                                  # what the client sent
```

The mode and the call log are runtime state: a new container starts from the fixtures and the
account file again.

## Options

| Environment | Default | |
|---|---|---|
| `MYRA_EMULATOR_TOKEN` | `test-token` | Bearer token |
| `MYRA_EMULATOR_KEY` / `MYRA_EMULATOR_SECRET` | `test-key` / `test-secret` | HMAC credentials (the signature is checked) |
| `MYRA_EMULATOR_ACCOUNT` | – | the account file |
| `MYRA_EMULATOR_PAGE_SIZE` | `50` | items per page, like Myra |
| `MYRA_EMULATOR_LOG` / `MYRA_EMULATOR_STATE` | temp directory | call log, mode |

What is captured and what is assumed (and answered like its captured neighbour) is listed in the
header of [public/index.php](public/index.php).

## In PHP tests

```bash
composer require --dev cpsit/myra-api-emulator
```

```php
$server = new CPSIT\MyraApiEmulator\Server(['MYRA_EMULATOR_PAGE_SIZE' => '2']); // php -S on a free port
$client = new Myracloud\WebApi\V2\Client(new BearerToken('test-token'), $server->baseUri());
// …
$server->calls();        // the requests, from the call log
$server->mode('down');   // a failure path
$server->reset();        // between tests
```

`CPSIT\MyraApiEmulator\Paths::fixtures()` is the directory of the captured answers, for tests that
read them directly; `Paths::router()` is the script for `php -S`.

## Captures and fixtures

`fixtures/` holds the answers the emulator gives, `<version>/<area>/<case>.json`; `docs/captures/`
the anonymised captures they come from, and how they were recorded. **Never commit raw captures**:
they contain the origin IPs behind Myra, which allow bypassing the CDN and its WAF.

## Development

```bash
composer ci        # validate, lint, PHP-CS-Fixer, PHPStan (max), tests
docker build -t myra-api-emulator .
```

## License

MIT, see [LICENSE](LICENSE).
