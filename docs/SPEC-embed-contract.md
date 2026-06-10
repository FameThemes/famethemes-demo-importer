# SPEC: Embed contract (host dashboard)

The importer's React tree mounts in one of two places:

1. **Standalone** — its own `admin.php?page=famethemes-demo-importer` page (default).
2. **Embedded** — inside another theme's dashboard, driven by the host's lifecycle.

This SPEC covers mode 2. The Customify dashboard is the first consumer; the contract is theme-agnostic so a future theme can adopt the same plumbing without forking the importer.

---

## Three layers

```
                                  ┌────────────────────────────────────┐
Theme adapter publishes  ───────► │ Theme_Adapter::embed_host_hook()   │
the host hook + boot filter       │ Theme_Adapter::embed_boot_filter() │
                                  │ Theme_Adapter::boot_payload()      │
                                  └────────────────────────────────────┘
                                                  │
                                                  ▼
Generic dashboard reacts ────────► Generic_Dashboard::enqueue_assets()
                                    • Detects $hook === $embed_host_hook
                                    • Enqueues the React bundle on the host page
                                    • Sets embedded: true in the localize
                                                  │
                                                  ▼
React entry adapts ──────────────► src/generic/admin.js
                                    • Skips auto-mount when embedded
                                    • Exposes window.ftDemoImporter.mount(el) / unmount(el)
                                                  │
                                                  ▼
Host component drives lifecycle ─► Theme's StarterTemplates.jsx
                                    • Render <div id="ft-demo-importer-app">
                                    • useEffect → window.ftDemoImporter.mount(slotRef.current)
                                    • Cleanup → window.ftDemoImporter.unmount(slotRef.current)
```

---

## Adapter side

[`Theme_Adapter`](../inc/generic/Adapters/class-theme-adapter.php) exposes three optional methods + a payload getter:

```php
public function embed_host_hook(): ?string;
public function embed_boot_filter(): ?string;
public function embed_boot_key(): string;     // default 'importer'
public function boot_payload(): array;
```

Customify's implementation ([`Customify_Adapter`](../inc/generic/Adapters/class-customify-adapter.php)):

```php
public function embed_host_hook(): ?string {
    if ( ! function_exists( 'customify_dashboard_v2_boot_data' ) ) {
        return null;
    }
    return 'toplevel_page_customify';
}

public function embed_boot_filter(): ?string {
    return 'customify_dashboard_localize';
}

public function boot_payload(): array {
    return [
        'palettes' => [ /* preset + user palettes */ ],
        // FALLBACK ONLY — wizard prefers the template's own
        // `theme_options.typography` shipped per item by the studio.
        'fonts'    => $this->curated_font_pairs(),
    ];
}
```

Plus `register_integration()` (called at boot):

```php
add_filter( 'customify_dashboard_localize', [ $this, 'inject_boot_metadata' ] );
add_filter( 'ft_demo_importer_show_generic_menu', '__return_false' );
add_action( 'admin_init', [ $this, 'redirect_standalone_to_embed' ] );
```

The `inject_boot_metadata` filter pushes `{ active: true, version, standaloneUrl }` under `boot.importer`. The host theme's React tree reads `boot.importer.active` to decide whether to flip to embedded mode.

---

## Generic side

[`Generic_Dashboard::enqueue_assets()`](../inc/generic/class-generic-dashboard.php) sniffs the host hook:

```php
$adapter   = apply_filters( 'ft_demo_importer_active_adapter', null );
$host_hook = $adapter ? $adapter->embed_host_hook() : null;
$is_host   = $host_hook && (string) $hook === (string) $host_hook;

if ( ! $own_page && ! $is_host ) {
    return;
}
// … enqueue …
if ( $is_host && ! $own_page ) {
    $base['embedded'] = true;
}
```

Adapter `boot_payload()` is merged into the localize too — keys the adapter publishes (e.g. `palettes`, `fonts`) become `window.ftDemoImporter.palettes` / `.fonts`.

---

## React side

[`src/generic/admin.js`](../src/generic/admin.js):

```js
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { App } from './components/App';

const roots = new WeakMap();

function mount( el ) {
    if ( ! el || roots.has( el ) ) return;
    const root = createRoot( el );
    root.render( <App /> );
    roots.set( el, root );
}

function unmount( el ) {
    const root = roots.get( el );
    if ( root ) { root.unmount(); roots.delete( el ); }
}

window.ftDemoImporter = Object.assign( window.ftDemoImporter || {}, { mount, unmount } );

domReady( () => {
    if ( window.ftDemoImporter?.embedded ) return;   // host drives mount
    const el = document.getElementById( 'ft-demo-importer-app' );
    if ( el ) mount( el );
} );
```

**Hard rules for `admin.js`:**

- The mount API surface (`mount` / `unmount` / `embedded`) is public — anything else added to `window.ftDemoImporter` must stay backward-compatible.
- `WeakMap` tracking lets `mount()` be idempotent (calling mount twice on the same node is a no-op) so React StrictMode's double-effect doesn't double-mount.
- Never auto-mount in embedded mode — the host's effect cleanup is the only safe unmount signal.

---

## Host side (theme component)

Customify's [`StarterTemplates.jsx`](../../../themes/customify/src/backend/admin/dashboard-v2/tabs/StarterTemplates.jsx):

```jsx
const importerActive = !! boot?.importer?.active;

useEffect( () => {
    if ( ! importerActive ) return undefined;
    const el = slotRef.current;
    const api = typeof window !== 'undefined' ? window.ftDemoImporter : null;
    if ( ! el || ! api?.mount ) return undefined;
    api.mount( el );
    return () => api.unmount?.( el );
}, [ importerActive ] );

return importerActive
    ? <div ref={ slotRef } id="ft-demo-importer-app" />
    : <InstallCta />;
```

---

## What happens when the plugin isn't active

The adapter is registered only when the plugin is loaded — so `boot.importer` is absent on a fresh site. The host component falls through to its install CTA, which one-click activates the plugin via `POST /wp/v2/plugins`. After activation, `window.location.reload()` so the next PHP request renders with `boot.importer.active = true`.

The plugin's `activated_plugin` redirect is silenced on REST / AJAX / CRON / CLI contexts to keep the JSON envelope intact (see AGENTS.md §3.6).

---

## Hardening checklist (for new host themes)

When adopting this contract for a different theme:

1. The host page's hook suffix is the only thing the adapter publishes — don't hard-code the URL.
2. The host's boot data MUST be enqueued AFTER the importer's payload is filterable but BEFORE the React bundle reads it. Customify uses `wp_localize_script` → priority-10 hooks; if your dashboard uses something custom, mirror the timing.
3. The host's slot div MUST exist when the React effect runs. SSR or async render → guard with `slotRef.current` null check.
4. The host MUST call `unmount(el)` on cleanup. Skipping it leaks the React tree on tab switches.
5. The host's reload-after-install behaviour is mandatory — the importer bundle isn't loaded until plugin assets enqueue, which only fires on a fresh PHP request.
