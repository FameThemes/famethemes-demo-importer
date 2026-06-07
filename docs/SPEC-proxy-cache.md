# SPEC — Studio Proxy Cache

`Studio_Proxy_Controller` sits between the React wizard and the remote
`design-library.pressmaximum.com` studio. Without local caching every
admin pageview triggers a fresh cross-host round trip (~1–3 s from
VN/EU links). This document defines the cache strategy — TTLs, keys,
invalidation, debug headers.

---

## 1. What is cached

| Endpoint | Method | Cached | TTL | Constant |
|---|---|---|---|---|
| `/studio/me` | GET | no | — | — |
| `/studio/templates` | GET | **yes** | 10 minutes | `LIST_TEMPLATES_TTL` |
| `/studio/templates/{id}` | GET | no | — | — |
| `/studio/templates/{id}/options` | GET | yes | 2 hours | (inline `2 * HOUR_IN_SECONDS`) |
| `/studio/categories` | GET | no | — | — |

`/studio/templates/{id}` is intentionally not cached: the wizard reads
`requirements` + `theme_options` + `preview_url` straight off
`items[]` in the list response (see the studio snapshot system —
`PressMaximum/blocksify-design-studio:Snapshot/ItemSnapshot.php`),
so the detail endpoint is no longer on the hot path.

---

## 2. List endpoint cache — `list_templates()`

### 2.1 Cache key

Composed from every query param the request actually carries:

```php
ksort( $query );                      // theme=x&search=y == search=y&theme=x
$cache_key = 'ft_demo_importer_tpl_list_' . md5( wp_json_encode( $query ) );
```

Sorting collapses param-order variations to a single key. The `md5`
keeps the option name under WP's 172-character transient-key budget
no matter how many filters stack up.

Different filter combos cache separately:

```
?theme=customify&view_context=site&per_page=-1
?theme=customify&view_context=site&per_page=24
?theme=customify&category=hero&view_context=site
```

…each lands on its own transient. No cross-contamination.

### 2.2 TTL — why 10 minutes

| Factor | Reasoning |
|---|---|
| Catalog freshness | Studio admin updates templates ~1–3× per day at most |
| User expectation | < 15 minutes for catalog-style data feels "fresh enough" |
| Cost reduction | 10 minutes covers a typical admin session — once per session = once-cold |
| Trade-off | 1 minute defeats the cache; 1 hour strands admins behind stale data |

The `LIST_TEMPLATES_TTL` constant lives on
`Studio_Proxy_Controller` — change in one place to retune.

### 2.3 What we cache

Only `2xx` responses with an array body:

```php
$status = (int) ( $res['status'] ?? 0 );
if ( $status >= 200 && $status < 300 && is_array( $res['body'] ?? null ) ) {
    set_transient(
        $cache_key,
        [ 'status' => $status, 'body' => $res['body'] ],
        self::LIST_TEMPLATES_TTL
    );
}
```

Error envelopes (502 unreachable, 403 unauthorized, …) are NEVER
cached. The next request retries the remote so a transient outage
doesn't lock the wizard out for 10 minutes.

### 2.4 Bypass: `?no_cache=1`

Admin (or curl-debug) can force a fresh remote read:

```
/wp-json/ft-demo-importer/v1/studio/templates?per_page=-1&view_context=site&no_cache=1
```

Behaviour:

1. **Wipe** the existing transient for this query map
   (`delete_transient`).
2. Fetch the remote.
3. Persist the fresh response under the same key (so subsequent
   users see the updated data immediately — they don't all also
   have to pass `no_cache=1`).
4. Return with header `X-FDI-Cache: BYPASS`.

The wipe-before-refetch is intentional. If we only skipped the read,
the stale entry would survive until TTL and other users could still
see it. Persisting the refreshed body globally is the actual
"refresh" UX.

### 2.5 Response headers

Every list response carries `X-FDI-Cache` so the wizard (or
DevTools / curl) can tell where the body came from:

| Value | Meaning |
|---|---|
| `HIT` | Served from local transient. ~0–5 ms response time. |
| `MISS` | Cache empty → fetched remote → stored. Cold path. |
| `BYPASS` | `?no_cache=1` was set → cache wiped → fetched remote → re-stored. |

Inspect with:

```bash
curl -I -H "Cookie: …admin-session…" \
  'https://customify.wp.local/wp-json/ft-demo-importer/v1/studio/templates?per_page=-1&view_context=site'
```

---

## 3. Options endpoint cache — `get_template_options()`

`get_template_options` caches the parsed `options.json` body for 2
hours. The cache key includes the **basename of the source asset
URL**:

```php
$cache_key = 'ft_demo_importer_tpl_opts_' . md5( $basename );
```

This is the auto-invalidation trick: every time the studio
regenerates the asset (re-submit → new URL with bumped query string
or hash), the basename changes and a new cache entry is created.
The old entry expires naturally. **No invalidation hook needed.**

This is why the list endpoint TTL is shorter than the options TTL —
list responses can't auto-invalidate the same way (the response URL
doesn't include a version-bumped fragment we could key on).

---

## 4. Flushing the cache

### 4.1 Single query
Pass `?no_cache=1` on the URL (see §2.4).

### 4.2 All list entries
Catalog-wide invalidation, e.g. after a known big update:

```bash
studio wp transient delete --all
```

Heavy-handed (drops WP's own transients too) but reliable.

For a scoped flush we'd need to walk `wp_options` for
`_transient_ft_demo_importer_tpl_*`. Not yet implemented — add when
the use case actually shows up.

### 4.3 Programmatic
```php
delete_transient( 'ft_demo_importer_tpl_list_' . md5( wp_json_encode( $query ) ) );
```

---

## 5. What this cache does NOT solve

- **Initial cold load**: the very first request after WP cache is
  empty still pays the full remote cost (~1–3 s). The cache only
  helps the 2nd-through-Nth load within the TTL window.
- **Cross-user cold misses**: when one admin's transient expires,
  the next admin to land on the page pays the cold cost again.
- **Cross-host failures**: if `design-library.pressmaximum.com` is
  down, the cache hides nothing — the next MISS shows the error.

For cross-host resilience we'd want stale-while-revalidate semantics
(serve stale entry while async re-fetching). WP transients don't
support that natively; would require either:
- a custom two-key scheme (`*_fresh` + `*_stale`) with a background
  cron refresher, OR
- an external cache (Redis with TTL + grace), OR
- a CDN layer (Cloudflare with stale-if-error) — best long-term fix
  because it benefits every consumer of the studio API, not just
  this importer.

---

## 6. References

- `inc/generic/REST/class-studio-proxy-controller.php` —
  `list_templates()` / `get_template_options()` implementations.
- `inc/generic/Studio/class-remote-client.php` — the cURL-backed
  client wrapped by the proxy. Same-origin short-circuits to in-
  process REST dispatch.
- Studio's `PressMaximum/blocksify-design-studio` repo, especially
  `includes/Snapshot/ItemSnapshot.php` — explains why the list
  response now carries everything the wizard needs (so the detail
  endpoint stays uncached without UX regression).
