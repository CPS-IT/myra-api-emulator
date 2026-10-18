# Real API answers (captures)

Answers of the real Myra API, recorded with Postman against a production account, then
**anonymised** (this repository is public): domains → `example.com` / `example.org`, IPs →
`192.0.2.0/24` / `2001:db8::/32`, ids, organisation ids and CNAMEs → made-up values. The
mapping is deterministic, so relations survive (the domain id in `/domains` is the id in the
`/domain/{id}/…` paths, records keep their `vhostId`). Error messages, codes, field names,
types and the envelope are unchanged — they are what the code depends on.

Each file: `request` (method, path, body as sent), `response` (status, content type, body),
`finding`. `index.json` lists all cases. These files are the source for test fixtures; do not
edit them, copy into `fixtures/` instead.

**Never commit raw captures.** They contain the origin IPs behind Myra, which allow
bypassing the CDN and its WAF.

## 2026-10-16

Both auth methods work on both versions; these cases used HMAC (key + secret) for v1 and the
Bearer token for v2. Cache clears used non-existent paths (`/__probe__/…`, not recursive).

| Case | Version | Request | Status | Finding |
|---|---|---|---|---|
| 1 | v1 | `GET /en/rapi/dnsRecords/{domain}/1` | 200 | envelope + `list`, paging fields |
| 2 | v1 | `PUT /en/rapi/cacheClear/{domain}` | 200 | `targetObject[CacheClearVO]` |
| 3 | v1 | same, `bulk` | 200 | bulk works in v1; `resource` echoes the last entry |
| 4 | v1 | wrong secret | 403 | `text/plain` "API key wrong or not provided.", no envelope |
| 5 | v1 | unknown domain | 403 | **empty body** — same as an auth failure |
| 6.1 | v1 | malformed body | 403 | violation "Invalid data format." |
| 6.2 | v1 | `fqdn` not in the domain | 403 | violation `DOMAIN_DOES_NOT_EXIST` with translations |
| 7 | v2 | `GET /domains` | 200 | envelope + `data`, paging fields, `id` per domain |
| 8 | v2 | `GET /domain/{id}/dns-records` | 200 | same DnsRecordVO as v1 |
| 9 | v2 | `PUT /domain/{id}/cache-clear` | 200 | **`error: false` is sent** (the spec omits it) |
| 10 | v2 | same, `bulk` | 200 | bulk works in v2 |
| 11 | v2 | wrong token | 403 | **empty body** |
| 12 | v2 | unknown domain id | 403 | violation "The entity does not exist."; without any token once 401 `{"error": "Session has been revoked"}` (a string) |
| 13.1 | v2 | malformed body | 403 | "Invalid data format." (also without a domain id) |
| 13.2 | v2 | `fqdn` not in the domain | 403 | `DOMAIN_DOES_NOT_EXIST` |
| 14 | v2 | `recursive: "x"` | 403 | violation on `recursive`, type error |
| 15 | v2 | domain **name** instead of id | 403 | rejected — v2 needs the numeric id |
| 16 | v2 | `GET /domains?page=2` | 200 | paging via `?page=`: echoed as `page: 2`; past the last page `data` is empty; `count` = total hits |
| 17 | v1 | `GET /en/rapi/domains/1` | 200 | same `DomainVO` as v2, field by field and ids; no `paused`/`pausedUntil` (the manual shows them) |
| 18 | v1 | `GET /en/rapi/domains/2` | 200 | page in the path, echoed; past the last page `list` is empty; `count` = total |
| 19 | v1 | `GET /en/rapi/redirects/example.com/1` | 200 | redirects of the zone apex: per vhost (subDomainName = the apex only) |
| 20 | v1 | `GET /en/rapi/redirects/www.example.com/1` | 200 | v1 takes a subdomain where the manual says {domain}: only that vhost's redirects |
| 21 | v1 | `GET /en/rapi/redirects/example.org/1` | 200 | no redirects: empty list, count 0 |
| 22 | v2 | `GET /domain/1000/redirects/example.com` | 200 | same DnsRedirectVO as v1; v2 lists per vHostName |
| 23 | v2 | `GET /domain/1000/redirects/example.com/90392` | 200 | get by id: data is a one-element list, no paging fields |
| 24 | v2 | `GET /domain/1140/redirects/example.org` | 403 | vHostName that is no vhost: 403 'The entity does not exist.' |
| 25 | v1 | `PUT /en/rapi/redirects/map.example.org` | 200 | v1 create: PUT, answer in targetObject; enabled: false is honoured although the manual does not list it |
| 26 | v1 | `POST /en/rapi/redirects/map.example.org` | 200 | v1 update: POST with id + modified, answer with the new modified |
| 27 | v1 | `POST /en/rapi/redirects/map.example.org` | 200 | optimistic lock: a stale modified is rejected with HTTP 200 (!) and error: true |
| 28 | v1 | `GET /en/rapi/redirects/map.example.org/1` | 200 | list after the update |
| 29 | v1 | `DELETE /en/rapi/redirects/map.example.org` | 200 | v1 delete: body {id, modified}; JSON answer although Content-Type is text/html |
| 30 | v1 | `GET /en/rapi/redirects/map.example.org/1` | 200 | list after the delete: the probe is gone |
| 31 | v2 | `POST /domain/1140/redirects/map.example.org` | 201 | v2 create: POST, HTTP 201, answer in data |
| 32 | v2 | `GET /domain/1140/redirects/map.example.org/90003` | 200 | get by id after the create |
| 33 | v2 | `PUT /domain/1140/redirects/map.example.org/90003` | 200 | v2 update: PUT …/{id} with id + modified |
| 34 | v2 | `PUT /domain/1140/redirects/map.example.org/90003` | 400 | optimistic lock: a stale modified is rejected with HTTP 400 and error: true |
| 35 | v2 | `GET /domain/1140/redirects/map.example.org` | 200 | list after the update |
| 36 | v2 | `DELETE /domain/1140/redirects/map.example.org/90003` | 204 | v2 delete: HTTP 204, empty body, no Content-Type |
| 37 | v2 | `GET /domain/1140/redirects/map.example.org` | 200 | list after the delete: the probe is gone |
| 38 | v1 | `GET /en/rapi/ipfilter/example.com/1` | 200 | IP filter of the apex: IpFilterVO, per vhost like redirects |
| 39 | v1 | `GET /en/rapi/ipfilter/www.example.com/1` | 200 | a vhost without entries: empty list |
| 40 | v2 | `GET /domain/1000/ip-filters/example.com` | 200 | same IpFilterVO as v1 |
| 41 | v2 | `GET /domain/1000/ip-filters/example.com/90393` | 200 | get by id: one-element data list |
| 42 | v2 | `GET /domain/1000/ip-filters/example.com/export` | 200 | export: text/csv with the columns IP, Expires on, Comments |
| 43 | v2 | `GET /domain/1140/ip-filters/example.org` | 403 | a vHostName that is no vhost: 403 'The entity does not exist.' |
| 44 | v1 | `PUT /en/rapi/ipfilter/map.example.org` | 200 | v1 create (probe: disabled blacklist entry for 192.0.2.1) |
| 45 | v1 | `POST /en/rapi/ipfilter/map.example.org` | 200 | v1 update with id + modified |
| 46 | v1 | `POST /en/rapi/ipfilter/map.example.org` | 200 | stale modified: HTTP 200 + error: true |
| 47 | v2 | `POST /domain/1140/ip-filters/map.example.org` | 201 | v2 create: HTTP 201 |
| 48 | v2 | `PUT /domain/1140/ip-filters/map.example.org/90002` | 200 | v2 update: PUT …/{id} |
| 49 | v2 | `PUT /domain/1140/ip-filters/map.example.org/90002` | 400 | stale modified: HTTP 400 |
| 50 | v2 | `GET /domain/1140/ip-filters/map.example.org/export?type=BLACKLIST` | 200 | export filtered by ?type=BLACKLIST |
| 51 | v2 | `POST /domain/1140/ip-filters/map.example.org/import?type=BLACKLIST` | 201 | import with one invalid line: HTTP 201 and error: false (the spec says true), the line as violation |
| 52 | v2 | `POST /domain/1140/ip-filters/map.example.org/import?type=BLACKLIST` | 201 | import signed with HMAC over Content-Type text/csv: works |
| 53 | v1 | `DELETE /en/rapi/ipfilter/map.example.org` | 200 | v1 delete: body {id, modified} |
| 54 | v2 | `GET /domain/1140/ip-filters/map.example.org` | 200 | list after the imports: expireDate appears only when set |
| 55 | v2 | `DELETE /domain/1140/ip-filters/map.example.org/90002` | 204 | v2 delete: HTTP 204, empty |
| 56 | v1 | `GET /en/rapi/cacheSettings/example.com/1` | 200 | cache settings of the apex: CacheSettingVO without a subdomain field |
| 57 | v1 | `GET /en/rapi/cacheSettings/www.example.com/1` | 200 | a vhost without rules: empty list |
| 58 | v1 | `GET /en/rapi/cacheSettings/www.example.org/1` | 500 | v1, a subdomain that is no vhost: HTTP 500, empty HTML — no envelope |
| 59 | v2 | `GET /domain/1000/example.com/cache-settings` | 200 | same CacheSettingVO as v1; path /domain/{id}/{vHostName}/cache-settings (vhost before area) |
| 60 | v2 | `GET /domain/1140/example.org/cache-settings` | 403 | a vHostName that is no vhost: 403 'The entity does not exist.' |
| 61 | v1 | `PUT /en/rapi/cacheSettings/map.example.org` | 200 | v1 create (probe: disabled rule for the exact path /__probe__/); notFoundTtl defaults to 60 |
| 62 | v1 | `POST /en/rapi/cacheSettings/map.example.org` | 200 | v1 update with id + modified |
| 63 | v1 | `POST /en/rapi/cacheSettings/map.example.org` | 200 | stale modified: HTTP 200 + error: true |
| 64 | v1 | `DELETE /en/rapi/cacheSettings/map.example.org` | 200 | v1 delete: body {id, modified} |
| 65 | v2 | `POST /domain/1140/map.example.org/cache-settings` | 201 | v2 create: HTTP 201 |
| 66 | v2 | `PUT /domain/1140/map.example.org/cache-settings/90002` | 200 | v2 update: PUT …/cache-settings/{id} |
| 67 | v2 | `PUT /domain/1140/map.example.org/cache-settings/90002` | 400 | stale modified: HTTP 400 |
| 68 | v2 | `DELETE /domain/1140/map.example.org/cache-settings/90002` | 204 | v2 delete: HTTP 204, empty |
| 69 | v1 | `GET /en/rapi/subdomainSetting/example.com` | 200 | subdomain settings: targetObject = 5 inheritance layers, domain = the vhost's own, parent = inherited (the manual's structure is outdated) |
| 70 | v1 | `GET /en/rapi/subdomainSetting/www.example.com` | 200 | a vhost with own settings: domain holds them, the last layer equals domain |
| 71 | v1 | `GET /en/rapi/subdomainSetting/example.com?flat=` | 200 | ?flat: the merged settings as a plain map, no envelope; v1 sends numbers as strings |
| 72 | v1 | `GET /en/rapi/subdomainSetting/www.example.org` | 500 | v1, a subdomain that is no vhost: HTTP 500, empty HTML |
| 73 | v2 | `GET /domain/1000/example.com/settings` | 200 | v2 GET answers in targetObject like v1 (not data) |
| 74 | v2 | `GET /domain/1140/example.org/settings` | 403 | a vHostName that is no vhost: 403 'The entity does not exist.' |
| 75 | v1 | `GET /en/rapi/subdomainSetting/map.example.org` | 200 | a vhost without own settings: domain is [] (a JSON list) |
| 76 | v1 | `GET /en/rapi/subdomainSetting/map.example.org?flat` | 200 | ?flat of that vhost (v1: numbers as strings) |
| 77 | v1 | `POST /en/rapi/subdomainSetting/map.example.org` | 200 | update with the unchanged own settings ({}): accepted, answer echoes them in targetObject |
| 78 | v2 | `GET /domain/1140/map.example.org/settings` | 200 | v2: same structure as v1 |
| 79 | v2 | `GET /domain/1140/map.example.org/settings?flat` | 200 | ?flat in v2 too (not in the spec); v2 sends numbers as numbers |
| 80 | v2 | `POST /domain/1140/map.example.org/settings` | 200 | v2 update: answer in data (the GET answers in targetObject) |
| 81 | v1 | `GET /en/rapi/dnsRecords/example.com/1?loadbalancer=true&recordTypes=A,AAAA,CNAME` | 200 | load balancing in v1: the DNS list with ?loadbalancer=true&recordTypes=A,AAAA,CNAME — records with upstreamOptions |
| 82 | v2 | `GET /domain/1000/load-balancing` | 200 | load balancing in v2: /domain/{id}/load-balancing, the same records |
| 83 | v2 | `GET /domain/1000/dns-records/1021` | 200 | get by id: one-element data list |
| 84 | v2 | `GET /domain/1000/vhost/1007/dns-records` | 200 | the records of one vhost, by its v2 vhostId |
| 85 | v1 | `PUT /en/rapi/dnsRecords/example.org` | 200 | v1 create (probe: inactive TXT __probe__.<domain>); a TXT record answers a partial UpstreamOptionsVO (backup, down only) |
| 86 | v1 | `POST /en/rapi/dnsRecords/example.org` | 200 | v1 update: the complete record as read, changes on top |
| 87 | v1 | `POST /en/rapi/dnsRecords/example.org` | 200 | stale modified: HTTP 200 + error: true |
| 88 | v1 | `PUT /en/rapi/dnsRecords/example.org` | 200 | probe A record (192.0.2.1, inactive) with default upstreamOptions (own id + modified) |
| 89 | v1 | `POST /en/rapi/dnsRecords/example.org` | 200 | load balancing changed through the normal update (weight 1 → 2) |
| 90 | v1 | `DELETE /en/rapi/dnsRecords/example.org` | 200 | v1 delete: body {id, modified} |
| 91 | v2 | `POST /domain/1140/dns-records` | 201 | v2 create: HTTP 201 |
| 92 | v2 | `PUT /domain/1140/dns-records/90009` | 200 | v2 update: PUT …/dns-records/{id} |
| 93 | v2 | `PUT /domain/1140/dns-records/90009` | 400 | stale modified: HTTP 400 |
| 94 | v2 | `POST /domain/1140/dns-records` | 201 | probe A record in v2 |
| 95 | v2 | `PUT /domain/1140/dns-records/90011` | 200 | load balancing changed through the normal update in v2 too (weight 1 → 2) |
| 96 | v2 | `PUT /domain/1140/dns-records/90011/load-balancing` | 500 | PUT …/{id}/load-balancing: HTTP 500 'An internal error occurred' for the inactive probe record (also with the upstream object's own id) — unverified |
| 97 | v2 | `DELETE /domain/1140/dns-records/90009` | 204 | v2 delete: HTTP 204 |
| 98 | v1 | `PUT /en/rapi/maintenance/map.example.org` | 200 | v1 create (probe: 2099, never active): Myra returns start/end in its own offset (+0100) — the same instant |
| 99 | v1 | `POST /en/rapi/maintenance/map.example.org` | 200 | v1 update: the complete object as read, changes on top |
| 100 | v1 | `POST /en/rapi/maintenance/map.example.org` | 200 | stale modified: HTTP 200 + error: true |
| 101 | v1 | `GET /en/rapi/maintenance/map.example.org/1` | 200 | list of the vhost with the probe |
| 102 | v1 | `DELETE /en/rapi/maintenance/map.example.org` | 200 | v1 delete: body {id, fqdn} as the manual says |
| 103 | v2 | `POST /domain/1140/map.example.org/maintenances` | 200 | v2 create: HTTP 200 (not 201), active settable (sent false) |
| 104 | v2 | `PUT /domain/1140/map.example.org/maintenances/90003` | 200 | v2 update: PUT …/maintenances/{id} |
| 105 | v2 | `PUT /domain/1140/map.example.org/maintenances/90003` | 400 | stale modified: HTTP 400 |
| 106 | v2 | `GET /domain/1140/maintenances/90003` | 200 | domain-wide get by id: adds permissions |
| 107 | v2 | `GET /domain/1140/maintenances?presentAndFuture=true` | 200 | domain-wide list with presentAndFuture=true |
| 108 | v2 | `POST /domain/1140/maintenance-templates` | 201 | template create: HTTP 201 (stored HTML only) |
| 109 | v2 | `PUT /domain/1140/maintenance-templates/90004` | 200 | template update with id + modified |
| 110 | v2 | `GET /domain/1140/maintenance-templates` | 200 | template list |
| 111 | v2 | `DELETE /domain/1140/maintenance-templates/90004` | 204 | template delete: HTTP 204 |
| 112 | v2 | `GET /domain/1140/map.example.org/maintenances` | 200 | list of the vhost with the probe |
| 113 | v2 | `DELETE /domain/1140/map.example.org/maintenances/90003` | 204 | v2 delete: HTTP 204 |
| 114 | v1 | `GET /en/rapi/errorpages/example.com/1` | 200 | v1 lists error pages grouped by subdomain ({subDomainName, error: {code: ErrorPageVO}}), without content, pageSize 10 |
| 115 | v2 | `GET /domain/1000/errorpages` | 200 | v2 lists them flat — UNPAGED: page 0, pageSize 10^16 (a naive 'next page' loop never ends; it did) |
| 116 | v2 | `GET /domain/1000/errorpages-grouped` | 200 | errorpages-grouped: data keyed by subdomain |
| 117 | v2 | `GET /domain/1000/errorpages/90400` | 200 | get by id: with content |
| 118 | v1 | `POST /en/rapi/errorpages/example.org` | 200 | v1 set (probe: code 413, free on the vhost): POST with pageContent + selection |
| 119 | v1 | `GET /en/rapi/errorpages/example.org/1` | 200 | the vhost's list with the probe page |
| 120 | v1 | `GET /en/rapi/errorpage/90001` | 200 | v1 errorpage/{id}: answered like a list — one group in list, with content |
| 121 | v1 | `POST /en/rapi/errorpages/example.org` | 200 | set again replaces the content (there is no update) |
| 122 | v1 | `DELETE /en/rapi/errorpages/example.org` | 200 | v1 delete: DELETE with the selection |
| 123 | v2 | `POST /domain/1140/errorpages` | 201 | v2 set: HTTP 201 |
| 124 | v2 | `GET /domain/1140/errorpages` | 200 | the list with the probe page, unpaged |
| 125 | v2 | `POST /domain/1140/errorpages` | 201 | set again: HTTP 201, content replaced |
| 126 | v2 | `DELETE /domain/1140/errorpages` | 204 | v2 delete: HTTP 204 |
| 127 | v1 | `GET /en/rapi/networks` | 200 | v1 lists only the enabled ranges (22) — exactly the enabled ones of v2 |
| 128 | v1 | `GET /en/rapi/networks?page=2&pageSize=10` | 200 | v1 pages by query (?page=&pageSize=), although the manual shows none |
| 129 | v1 | `GET /en/rapi/networks/2` | 403 | no /networks/{page} route: HTTP 403, empty body |
| 130 | v2 | `GET /ip-ranges` | 200 | v2 lists ALL ranges (37), incl. 15 disabled with validTo in the past; same objects and ids as v1 |
| 131 | v2 | `GET /ip-ranges?page=2&pageSize=10` | 200 | v2 pages by query (?page=&pageSize=; separate run, so other fake ids) |
| 132 | v1 | `POST /en/rapi/statistic/query` | 200 | statistic query (2 h; metric numbers, URLs scrubbed): StatisticVO WITHOUT envelope (no error key), targetObject null; histogram buckets {value} keyed by ms (the manual: min/max/avg/sum); response_codes stats = map code → {min,max,sum,avg} incl. key Other; dates echoed as +0200 |
| 133 | v1 | `POST /en/rapi/statistic/query` | 500 | an unknown data source: HTTP 500, empty body |
| 134 | v2 | `POST /statistic/query` | 200 | v2: the same request, the same StatisticVO as v1 (no envelope) |
| 135 | v2 | `GET /statistics/total-domain-requests/1792051200/1792058399` | 200 | all domains (GET, unix seconds in the path): {graph: name → {name, max, sum, data: [{x ms, y}]}, data: totals}; graph max/sum 0 |
| 136 | v2 | `GET /statistics/total-domain-traffic/1792051200/1792058399` | 200 | all domains: the graphs WITHOUT a graph/data wrapper |
| 137 | v2 | `POST /statistics/total-domain-requests/1792051200/1792058399` | 200 | POST with {domains, subDomains}: same shape as GET |
| 138 | v2 | `POST /statistics/http-status-codes/1792051200/1792058399` | 200 | a bare JSON list (no envelope): [{status_code, value}], all codes Myra knows (37) |
| 139 | v2 | `POST /statistics/http-status-codes/1792051200/1792058399` | 200 | a quiet domain: the same 37 codes, value 0 |
| 140 | v2 | `POST /statistics/top-urls/1792051200/1792058399` | 200 | bare list [{url, count, cost}], 10 entries |
| 141 | v2 | `POST /statistics/top-urls/1792051200/1792058399` | 200 | a quiet domain: the body null (not []) |
| 142 | v2 | `POST /statistics/cached-urls/1792051200/1792058399` | 200 | bare list [{url, count}] |
| 143 | v2 | `POST /statistics/world-map/1792051200/1792058399/total_requests/desc` | 200 | bare list per country: totals + astype list (ASN names redacted) |
| 144 | v2 | `POST /statistics/histogram/1792051200/1792058399/total-requests/hourly/0/1` | 200 | {group: name → {graphData: {name, max, sum, data}}} per vhost + ALL-{domainId}; data null for vhosts without requests |
| 145 | v2 | `POST /statistics/top-urls/1792051200/1792058399` | 400 | a domain not in the account: HTTP 400, INTERNAL_ERROR envelope |
| 146 | v2 | `POST /statistics/histogram/1792051200/1792058399/redacted-137/hourly/0/1` | 403 | an unknown chart: HTTP 403 "The entity does not exist." |
| 147 | v2 | `GET /subdomains` | 200 | all vhosts of the account (no objectType): label = FQDN, name/value with dot, domainName/domainId, isActive, paused, access, permissions |
| 148 | v2 | `GET /subdomains?page=2&pageSize=2` | 200 | the account list IGNORES paging: page 2, pageSize 2 in the answer, yet all 7 entries |
| 149 | v2 | `GET /subdomains?search=www` | 200 | ?search= matches parts of the name |
| 150 | v2 | `GET /subdomains?search=example.com&filterType=exact` | 200 | ?search=…&filterType=exact: only that name |
| 151 | v2 | `GET /domain/1000/subdomains` | 200 | the vhosts of one domain |
| 152 | v2 | `GET /domain/1000/subdomains?page=2&pageSize=2` | 200 | per domain, paging works |
| 153 | v2 | `GET /domain/1000/subdomains?search=www` | 200 | ?search= per domain |
| 154 | v2 | `GET /domain/1000/subdomains?active=true&isActive=true` | 200 | ?active=true&isActive=true: adds a field active, same entries (all are active) |
| 155 | v2 | `GET /domains/1000` | 200 | a domain by id: DomainVO with permissions, label, verified, environment |
| 156 | v2 | `GET /domains/1000/verification` | 200 | verification status: verified, recordNames, conflictingNames, foreignDescendantsExist |
| 157 | v2 | `GET /domains/1000/foreign-descendants` | 403 | foreign-descendants: HTTP 403, empty body (this account) |
| 158 | v1 | `GET /en/rapi/certificates/example.com/1` | 200 | v1 certificates of a domain: SslCertVO (subject, validity, fingerprint, serialNumber, subjectAlternatives, subdomains, intermediates, sslConfigurationName, managed, …) — fingerprints/serials faked as hex pairs |
| 159 | v1 | `GET /en/rapi/certificates/www.example.com/1` | 200 | v1 with a subdomain: the same 4 — certificates are per domain, not filtered by vhost |
| 160 | v1 | `GET /en/rapi/certificates/example.org/1` | 200 | a domain without certificates |
| 161 | v2 | `GET /domain/1000/ssl/certificates` | 200 | v2: the same SslCertVOs as v1 |
| 162 | v2 | `GET /domain/1000/ssl/certificates?page=2&pageSize=2` | 200 | v2 pages by query |
| 163 | v2 | `GET /domain/1000/ssl/certificates/90001` | 200 | v2 get by id: one-element data |
| 164 | v2 | `GET /ssl-configurations` | 200 | Myra's SSL configurations (name, ciphers, tls13Ciphers, protocols; names and cipher lists redacted by the anonymiser) |
| 165 | v2 | `GET /waf/conditions` | 200 | WAF condition catalogue (account-independent): WafConditionVO name, category, alias, key, availablePhases; labels redacted by the anonymiser |
| 166 | v2 | `GET /waf/conditions/matching-types` | 200 | matching types by category (default, score, country, …): a map, not a list |
| 167 | v2 | `GET /waf/actions` | 200 | WAF action catalogue: WafActionVO name, type, availablePhases; forceCustomValues is an int here (bool in conditions) |
| 168 | v2 | `GET /waf/actions/values` | 200 | allowed values per action type: {customKey: type → value → label, requiresValue: type → value → bool} |
| 169 | v2 | `GET /waf/countries` | 200 | 250 countries in ONE answer although pageSize says 50 (unpaged like the account's subdomains); names redacted |
| 170 | v2 | `GET /user/me` | 200 | the token's own user: UserExtendedVO (sessions with IP/user agent, rootGroupRoles); names redacted, e-mails userN@example.com |
| 171 | v2 | `GET /users` | 200 | the organisation's users: UserVO |
| 172 | v2 | `GET /users/90003` | 200 | a user by id: one-element data |
| 173 | v2 | `GET /user/90003/groups` | 200 | the groups of a user: GroupVO (roles, membersCount, type) |
| 174 | v2 | `GET /user/90003/api-keys` | 200 | the API keys of a user: ApiKeyVO with the public key only (no secret); key and name redacted |
| 175 | v2 | `GET /user/90003/activities` | 200 | a user's activities (logins): paged (176 entries, 50 per page); geo coordinates zeroed, browser versions came out as documentation IPs |
| 176 | v2 | `GET /user/90003/permissions` | 200 | a user's permissions: objectType (Domain, DnsRedirect, …), objectPermissionType, objectInstance, action |
| 177 | v2 | `GET /user/permissions` | 200 | the token user's permissions: UNPAGED (pageSize = count = 94) |
| 178 | v2 | `GET /user/tokens` | 200 | API tokens: TokenVO without the token value (name, enabled, expiryDate, lastUsedDate) |
| 179 | v2 | `GET /user/groups` | 200 | the organisation's groups |
| 180 | v2 | `GET /user/groups/90001` | 200 | a group by id (roles empty here, filled in the list) |
| 181 | v2 | `GET /user/group/90001/users` | 200 | the members of a group |
| 182 | v2 | `GET /user/group/90001/permissions` | 200 | the permissions of a group |
| 183 | v2 | `GET /organization/contacts` | 200 | organisation contacts: OrganizationContactVO (types, priorities, reminders); names and phone redacted |
| 184 | v2 | `GET /organization/contact-types` | 200 | contact types: key + label (no objectType) |
| 185 | v2 | `GET /organization/notes` | 200 | organisation notes: one OrganizationNoteVO without fields (none set) |
| 186 | v2 | `POST /domain/1140/map.example.org/waf-rules` | 403 | probe WAF rule (disabled, only /__probe__/waf, log only) on the unused domain: HTTP 403, EMPTY body — although the user holds DomainWafRule CREATE; nothing was created (verified). Likely WAF not available for that domain; not retried |
| 187 | v1 | `GET /en/rapi/bind/example.org` | 200 | v1 zone of a domain: DomainBindVO (SOA fields, version = serial, records with type/value/ttl/active, cnameAlt); Myra's name servers dns.dnsN.de stay (public) |
| 188 | v1 | `GET /en/rapi/bindRaw/example.org` | 200 | v1 BIND zone file: text/plain |
| 189 | v2 | `GET /domains/1140/bind/Myra` | 200 | v2 zone, ipTarget Myra: the same DomainBindVO as v1 (the SOA's primary name server rotates between calls) |
| 190 | v2 | `GET /domains/1140/bind/Origin` | 200 | ipTarget Origin: for this zone the same records as Myra (what differs is not visible here) |
| 191 | v2 | `GET /domains/1140/bind-raw/Myra` | 200 | v2 zone file, ipTarget Myra: text/plain |
| 192 | v2 | `GET /domains/1140/bind-raw/Origin` | 200 | v2 zone file, ipTarget Origin |

**Every failure is HTTP 403**, so the body decides: envelope with `error: true` → rejected
request (violations); no envelope → access denied.

Cases 19–24 come from `tests/Smoke/capture.php`, cases 25–37 from `tests/Smoke/write.php` (a disabled probe
redirect of `/__probe__/redirect` on an unused domain, created, changed and deleted through this
package) — both anonymised in memory, see their docblocks.
Cases 17/18 and the HMAC-on-v2 confirmation come from `tests/Smoke/run.php` (all four
version × auth combinations through this package, 12/12 passed): v2 signed with HMAC
works, including a query string (`/domains?page=2`); Bearer works on v1.

Missing, needed before the other areas are implemented: one list call and one harmless write
per version for subdomain settings, cache settings, redirects, IP filter, maintenance, SSL
certificates, statistics, IP ranges, load balancing.
