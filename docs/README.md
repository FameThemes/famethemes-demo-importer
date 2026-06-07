# FameThemes Demo Importer — Documentation

Index for all importer plugin documentation. Every file is either a **SPEC** (canonical reference for one subsystem) or a **guide** (cross-cutting).

For agent-facing rules see [`../AGENTS.md`](../AGENTS.md).

---

## Start here

| You are… | Read |
|---|---|
| New contributor | [SPEC-pipeline.md](SPEC-pipeline.md) — the 5-phase import flow |
| Adding support for a new theme | [SPEC-adapter.md](SPEC-adapter.md) |
| Embedding the importer in a theme dashboard | [SPEC-embed-contract.md](SPEC-embed-contract.md) |
| Adding fields to the Style step | [SPEC-style-step.md](SPEC-style-step.md) |
| Looking for a filter / action signature | [api-reference.md](api-reference.md) |

---

## How it all fits together

The big picture — two tracks, three layers.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Plugin boot — famethemes-demo-importer.php @ plugins_loaded                │
│    │                                                                         │
│    ▼                                                                         │
│  demo_contents_is_legacy_theme($template) ──── YES → inc/legacy/bootstrap.php│
│                                                       (frozen — OnePress)    │
│                                                                              │
│                                              NO  → inc/generic/bootstrap.php │
│                                                                              │
│  ──── Generic track ────────────────────────────────────────────────────     │
│                                                                              │
│  bootstrap.php wires:                                                        │
│    • Theme_Adapter registry  ── Theme_Detector::active_adapter() picks one   │
│    • Generic_Dashboard       ── admin UI surface                             │
│    • Studio Remote_Client    ── HTTP to the Studio server                    │
│    • Studio_Proxy_Controller ── REST proxy for the React UI                  │
│    • Job_Controller          ── REST routes for the wizard                   │
│    • Importer_Runner         ── WP-Cron handler                              │
│    • Adapter→credential bridges (filter chain)                               │
│                                                                              │
│  Adapter lookup:                                                             │
│    Theme_Detector::active_adapter()                                          │
│      → Adapter_Registry::resolve(template, stylesheet)                       │
│        → exact slug match  ── Customify_Adapter / other                      │
│        → fall back         ── Default_Adapter (no-op)                        │
│                                                                              │
│  Wizard runtime path:                                                        │
│    React UI in src/generic/components/                                       │
│      → studio.listTemplates / api.js                                         │
│      → Studio_Proxy_Controller → Remote_Client → Studio HTTP                 │
│      → user picks template + Style step config (palette / font)              │
│      → jobs.create() → Job_Controller → Job_Store + Importer_Runner.enqueue  │
│      → wp-cron fires → Importer_Runner.run(job_id)                           │
│        → 5 phases (see SPEC-pipeline.md)                                     │
│        → adapter.after_phase('applying_options') applies Style step          │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## SPECs

| File | Scope |
|---|---|
| [SPEC-pipeline.md](SPEC-pipeline.md) | 5-phase background job pipeline — fetching → installing_plugins → extracting → importing_content → applying_options |
| [SPEC-adapter.md](SPEC-adapter.md) | Theme_Adapter abstract contract + Customify_Adapter implementation |
| [SPEC-style-step.md](SPEC-style-step.md) | Color palette + typography pair → theme_mods + WP Font Library install |
| [SPEC-embed-contract.md](SPEC-embed-contract.md) | Embedding the React UI in a host theme's dashboard |
| [SPEC-proxy-cache.md](SPEC-proxy-cache.md) | Local transient cache on `Studio_Proxy_Controller` — TTLs, keys, `?no_cache=1` bypass |
| [api-reference.md](api-reference.md) | Public filters, actions, REST routes — signature + file:line |

---

## Repo layout

```
famethemes-demo-importer/
├── famethemes-demo-importer.php    Main plugin file + track router
├── AGENTS.md                       Rules for AI agents / contributors
├── docs/                           THIS FOLDER
├── inc/
│   ├── legacy/                     FROZEN — OnePress family (do not touch)
│   └── generic/                    Active development surface
│       ├── bootstrap.php           Wires the generic track
│       ├── class-generic-dashboard.php
│       ├── class-adapter-registry.php
│       ├── class-theme-detector.php
│       ├── Adapters/               Per-theme adapters
│       │   ├── class-theme-adapter.php       (abstract)
│       │   ├── class-default-adapter.php
│       │   ├── class-customify-adapter.php
│       │   └── customify/                    (Customify-specific helpers)
│       │       └── class-font-installer.php
│       ├── Jobs/                   Background-job orchestration
│       │   ├── class-importer-runner.php
│       │   └── class-job-store.php
│       ├── REST/                   REST controllers
│       │   ├── class-studio-proxy-controller.php
│       │   └── class-job-controller.php
│       ├── Settings/               Options Store
│       │   └── class-options-store.php
│       ├── Steps/                  Pipeline steps (one per phase)
│       │   ├── class-asset-fetcher.php
│       │   ├── class-plugin-installer.php
│       │   ├── class-uploads-extractor.php
│       │   ├── class-content-importer.php
│       │   └── class-options-importer.php
│       ├── Studio/                 HTTP client for the Studio server
│       │   └── class-remote-client.php
│       └── build/                  Webpack output (committed)
└── src/                            JS / SCSS sources
    └── generic/
        ├── admin.js                Mount/unmount entry
        ├── admin.scss
        ├── api.js                  REST client wrapper
        ├── components/             React tree (App, TemplateGrid, PreviewPanel, …)
        ├── hooks/
        └── placeholders.js         Fallback palette + font data
```
