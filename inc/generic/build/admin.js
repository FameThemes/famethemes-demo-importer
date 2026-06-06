/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./src/generic/api.js"
/*!****************************!*\
  !*** ./src/generic/api.js ***!
  \****************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   jobs: () => (/* binding */ jobs),
/* harmony export */   studio: () => (/* binding */ studio)
/* harmony export */ });
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/api-fetch */ "@wordpress/api-fetch");
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__);
/**
 * REST client for the Generic track. Wraps `@wordpress/api-fetch` so the
 * nonce + namespace handling is one place — components just call
 * `studio.listTemplates({...})` / `jobs.create(...)` etc.
 *
 * All routes under `ft-demo-importer/v1/`. Auth: cookie + REST nonce
 * (apiFetch attaches automatically when `wp.apiFetch.createNonceMiddleware`
 * is registered — the Generic_Dashboard's enqueue path passes the nonce
 * via `ftDemoImporter.restNonce`).
 */


const NS = '/ft-demo-importer/v1';

// Boot the nonce middleware once per page load. The localized
// `ftDemoImporter.restNonce` is fresh per request.
if (window.ftDemoImporter && window.ftDemoImporter.restNonce) {
  _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default().use(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default().createNonceMiddleware(window.ftDemoImporter.restNonce));
}

// ---------------------------------------------------------------- Studio

const studio = {
  me() {
    return _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: `${NS}/studio/me`
    });
  },
  /**
   * @param {{ search?: string, category?: string, page?: number, per_page?: number }} params
   */
  listTemplates(params = {}) {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') {
        qs.append(k, v);
      }
    });
    const suffix = qs.toString() ? `?${qs}` : '';
    return _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: `${NS}/studio/templates${suffix}`
    });
  },
  getTemplate(id) {
    return _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: `${NS}/studio/templates/${id}`
    });
  },
  listCategories() {
    return _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: `${NS}/studio/categories`
    });
  }
};

// ---------------------------------------------------------------- Jobs

const jobs = {
  /**
   * @param {{ template_id: number, import_content?: boolean, import_uploads?: boolean,
   *           overwrite_existing?: boolean, replace_settings?: boolean,
   *           plugins_skip?: string[] }} config
   */
  create(config) {
    return _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: `${NS}/theme/jobs`,
      method: 'POST',
      data: config
    });
  },
  get(id) {
    return _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: `${NS}/theme/jobs/${id}`
    });
  },
  latest() {
    return _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: `${NS}/theme/jobs/latest`
    });
  },
  cancel(id) {
    return _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: `${NS}/theme/jobs/${id}/cancel`,
      method: 'POST'
    });
  }
};

/***/ },

/***/ "./src/generic/components/App.jsx"
/*!****************************************!*\
  !*** ./src/generic/components/App.jsx ***!
  \****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   App: () => (/* binding */ App)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _TemplateGrid__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./TemplateGrid */ "./src/generic/components/TemplateGrid.jsx");
/* harmony import */ var _PreviewPanel__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./PreviewPanel */ "./src/generic/components/PreviewPanel.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__);
/**
 * Generic-track admin app — top-level.
 *
 * UX matches OnePress: card click → fullscreen preview (sidebar + iframe).
 * "Import Now" button INSIDE the preview is what actually starts the
 * job; same panel hosts the progress steps. App owns the "which
 * template is previewed?" state — PreviewPanel owns the job state.
 */





function App() {
  const [preview, setPreview] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.Fragment, {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_TemplateGrid__WEBPACK_IMPORTED_MODULE_1__.TemplateGrid, {
      onSelect: setPreview
    }), preview && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_PreviewPanel__WEBPACK_IMPORTED_MODULE_2__.PreviewPanel, {
      template: preview,
      onClose: () => setPreview(null)
    })]
  });
}

/***/ },

/***/ "./src/generic/components/PreviewPanel.jsx"
/*!*************************************************!*\
  !*** ./src/generic/components/PreviewPanel.jsx ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   PreviewPanel: () => (/* binding */ PreviewPanel)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../api */ "./src/generic/api.js");
/* harmony import */ var _hooks_useJob__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../hooks/useJob */ "./src/generic/hooks/useJob.js");
/* harmony import */ var _placeholders__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ../placeholders */ "./src/generic/placeholders.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__);
/**
 * Fullscreen import wizard — left sidebar drives a 3-step config
 * (Style → Plugins → Content & options), right side renders a
 * scrollable preview iframe of the template. Layout cloned from
 * mockup3.html (UX research).
 *
 *   ┌── 320px sidebar (left) ──────┐ ┌── iframe pane ──────────────────────┐
 *   │ Setting up: <title>        ✕ │ │                                     │
 *   │─ scroll ──────────────────── │ │              <iframe />             │
 *   │  Step 0: Style               │ │                                     │
 *   │  Step 1: Plugins             │ │                                     │
 *   │  Step 2: Content & options   │ │                                     │
 *   │  Install                     │ │                                     │
 *   │  Done                        │ │                                     │
 *   │─ footer ──────────────────── │ │                                     │
 *   │  Back ──────── Skip · Next → │ │                                     │
 *   └──────────────────────────────┘ └─────────────────────────────────────┘
 *
 * The job-runner state is identical to the prior PreviewPanel — once
 * the user clicks Start on the last step, we POST to `/theme/jobs`
 * and switch the sidebar into the install + done views, driven by the
 * `useJob` polling hook.
 *
 * Style step (palette + typography) is presented from `placeholders.js`
 * because no backend wires those choices into the import job yet.
 * Selections are tracked in local state so the UX flows correctly;
 * they're omitted from the create-job payload until the Studio adds
 * a styles surface.
 */









/**
 * Host-provided palettes win when present (Customify adapter publishes
 * Customizer presets + user-saved palettes via window.ftDemoImporter.
 * palettes). Otherwise fall back to the plugin's placeholder set so the
 * step still renders something on themes without an adapter.
 */

function getPalettes() {
  if (typeof window !== 'undefined') {
    const fromHost = window.ftDemoImporter?.palettes;
    if (Array.isArray(fromHost) && fromHost.length > 0) {
      return fromHost;
    }
  }
  return _placeholders__WEBPACK_IMPORTED_MODULE_5__.PALETTES;
}

/**
 * Same pattern for font pairs — Customify adapter publishes 6 curated
 * pairs via `window.ftDemoImporter.fonts`.
 */
function getFonts() {
  if (typeof window !== 'undefined') {
    const fromHost = window.ftDemoImporter?.fonts;
    if (Array.isArray(fromHost) && fromHost.length > 0) {
      return fromHost;
    }
  }
  return _placeholders__WEBPACK_IMPORTED_MODULE_5__.FONTS;
}
const STEPS = [{
  key: 'style',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Style', 'famethemes-demo-importer')
}, {
  key: 'plugins',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Plugins', 'famethemes-demo-importer')
}, {
  key: 'content',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Content & options', 'famethemes-demo-importer')
}];

// Baseline importer dependency — always shown at the top of the
// Required plugins list, even if the Studio API doesn't include it.
const BLOCKSIFY_SLUG = 'blocksify';
const BLOCKSIFY_NAME = 'Blocksify';
const BLOCKSIFY_DESC = (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Block library required to render Studio templates.', 'famethemes-demo-importer');
const PHASES = [{
  key: 'fetching',
  from: 0,
  to: 10,
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Fetching template assets', 'famethemes-demo-importer')
}, {
  key: 'installing_plugins',
  from: 10,
  to: 30,
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Installing required plugins', 'famethemes-demo-importer')
}, {
  key: 'extracting',
  from: 30,
  to: 45,
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Extracting uploads', 'famethemes-demo-importer')
}, {
  key: 'importing_content',
  from: 45,
  to: 90,
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Importing content', 'famethemes-demo-importer')
}, {
  key: 'applying_options',
  from: 90,
  to: 100,
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Applying theme options', 'famethemes-demo-importer')
}];
function PreviewPanel({
  template,
  onClose
}) {
  const [detail, setDetail] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [detailErr, setDetailErr] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [step, setStep] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(0);
  const [palette, setPalette] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [typography, setTypography] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [pluginsSkip, setPluginsSkip] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [contentEnabled, setContentEnabled] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [optWidgets, setOptWidgets] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [optCustomizer, setOptCustomizer] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [jobId, setJobId] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [starting, setStarting] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [startError, setStartError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const iframeRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);

  // Resolve full data records (with colors / families) for the
  // currently selected palette + font pair. Memoised so the iframe
  // postMessage effect doesn't re-fire on unrelated state changes.
  const palettes = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => getPalettes(), []);
  const fonts = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => getFonts(), []);
  const currentPalette = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => palette ? palettes.find(p => p.id === palette) || null : null, [palette, palettes]);
  const currentFont = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => typography ? fonts.find(f => f.id === typography) || null : null, [typography, fonts]);

  // Push current Style step selections into the preview iframe over
  // postMessage. Cross-origin by design — Studio's preview server
  // implements a listener for `type: 'fdi-preview-style'` and maps
  // the payload onto CSS variables / font links. When the listener
  // isn't installed yet, the message is silently dropped and the
  // in-pane overlay chip below still gives the user feedback.
  //
  // Contract (documented for the Studio side):
  //   {
  //     type: 'fdi-preview-style',
  //     palette: { id, name, colors: [primary, secondary, accent, text, surface, base] } | null,
  //     font:    { id, heading, body, weight } | null,
  //   }
  const sendStyleToIframe = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    const win = iframeRef.current?.contentWindow;
    if (!win) {
      return;
    }
    try {
      win.postMessage({
        type: 'fdi-preview-style',
        palette: currentPalette,
        font: currentFont
      }, '*');
    } catch (e) {
      // Iframe not ready / cross-origin restriction during nav —
      // safe to ignore; next selection change will retry.
    }
  }, [currentPalette, currentFont]);

  // Re-send on every selection change + on iframe load (caught via
  // the `load` event below). Replays guarantee the iframe gets the
  // latest state even if it navigated mid-session.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    sendStyleToIframe();
  }, [sendStyleToIframe]);

  // Handshake: Studio's iframe can post `{type:'fdi-preview-ready'}`
  // to ask the parent for the current selection (handles late-load
  // race where the iframe's listener registers after our last send).
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const handler = event => {
      if (event?.data?.type === 'fdi-preview-ready' && iframeRef.current?.contentWindow === event.source) {
        sendStyleToIframe();
      }
    };
    window.addEventListener('message', handler);
    return () => window.removeEventListener('message', handler);
  }, [sendStyleToIframe]);
  const {
    job
  } = (0,_hooks_useJob__WEBPACK_IMPORTED_MODULE_4__.useJob)(jobId);
  const importing = jobId !== null;
  const status = job?.status || (importing ? 'queued' : 'idle');
  const percent = job?.progress?.percent | 0;
  const isDone = status === 'completed' || status === 'failed' || status === 'cancelled';

  // Detail fetch — needed for the recommended plugins list + canonical preview URL.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    let cancelled = false;
    setDetail(null);
    setDetailErr(null);
    _api__WEBPACK_IMPORTED_MODULE_3__.studio.getTemplate(template.id).then(res => {
      if (!cancelled) setDetail(res);
    }).catch(e => {
      if (!cancelled) setDetailErr(e?.message || String(e));
    });
    return () => {
      cancelled = true;
    };
  }, [template.id]);

  // Lock page scroll while the wizard is open — same dance as the
  // previous PreviewPanel, prevents wp-admin from scrolling behind
  // the overlay.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    document.body.classList.add('fdi-wizard-open');
    return () => {
      document.body.classList.remove('fdi-wizard-open');
    };
  }, []);
  const title = template.title || template.name || `#${template.id}`;
  const rawIframeUrl = detail?.frame_url || detail?.preview_route || detail?.demo_url || detail?.preview_url || template.preview_url || '';

  // Cache-bust the preview URL — Studio sites typically front WordPress
  // with a page cache (Cloudflare, WP Rocket, LiteSpeed, etc.) that
  // snapshots HTML for top-level navigation, and the cached snapshot
  // can be missing the `customify-preview-bridge` <script> tag if the
  // plugin was activated AFTER the snapshot was written. Adding a
  // per-template cachebust forces the origin to render fresh and ship
  // the script. The token is stable per (template.id, mount) so the
  // browser still caches subresources within a session.
  const iframeUrl = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => {
    if (!rawIframeUrl) return '';
    const sep = rawIframeUrl.includes('?') ? '&' : '?';
    return rawIframeUrl + sep + '_fdi_cb=' + template.id;
  }, [rawIframeUrl, template.id]);

  // Plugins — split into required vs recommended for the sidebar UI.
  // Blocksify is always pinned at the top of the required list because
  // the importer needs the Blocksify block library to apply Studio
  // templates regardless of what any individual template declares.
  const plugins = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => {
    const list = Array.isArray(detail?.requirements?.plugins) ? detail.requirements.plugins : [];
    const mapped = list.map(p => ({
      slug: p.slug,
      name: p.name || p.slug,
      required: Boolean(p.required),
      installed: Boolean(p.installed),
      source: p.source || '',
      desc: p.description || p.desc || ''
    }));
    const existingIdx = mapped.findIndex(p => p.slug === BLOCKSIFY_SLUG);
    const existing = existingIdx >= 0 ? mapped.splice(existingIdx, 1)[0] : null;
    const blocksify = existing ? {
      ...existing,
      required: true
    } : {
      slug: BLOCKSIFY_SLUG,
      name: BLOCKSIFY_NAME,
      required: true,
      installed: false,
      source: 'wordpress.org',
      desc: BLOCKSIFY_DESC
    };
    return [blocksify, ...mapped];
  }, [detail]);
  const requiredPlugins = plugins.filter(p => p.required);
  const recommendedPlugins = plugins.filter(p => !p.required);
  const isPluginChecked = p => {
    if (p.installed) return false;
    if (p.required) return true;
    return !pluginsSkip.includes(p.slug);
  };
  const togglePlugin = slug => {
    setPluginsSkip(prev => prev.includes(slug) ? prev.filter(s => s !== slug) : [...prev, slug]);
  };
  const allOptionalUnchecked = recommendedPlugins.length > 0 && recommendedPlugins.filter(p => !p.installed).every(p => pluginsSkip.includes(p.slug));
  const bulkToggleOptional = () => {
    const optInstallable = recommendedPlugins.filter(p => !p.installed);
    setPluginsSkip(allOptionalUnchecked ? [] : optInstallable.map(p => p.slug));
  };
  const handleStart = () => {
    setStartError(null);
    setStarting(true);
    _api__WEBPACK_IMPORTED_MODULE_3__.jobs.create({
      template_id: template.id,
      import_content: contentEnabled,
      import_uploads: true,
      replace_settings: optWidgets || optCustomizer,
      plugins_skip: pluginsSkip,
      // Carry the wizard's Style step selections through to the
      // job runner. Theme adapter consumes these inside
      // `after_phase('applying_options')` to write theme_mods
      // (palette → 6 color slots) and install Google Fonts into
      // the WP Font Library (typography pair).
      style: {
        palette: palette,
        font: typography
      }
    }).then(res => {
      if (res?.job_id) setJobId(res.job_id);
    }).catch(err => {
      setStartError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to start import job.', 'famethemes-demo-importer'));
    }).finally(() => {
      setStarting(false);
    });
  };
  const handleClose = () => {
    if (importing && !isDone) {
      // eslint-disable-next-line no-alert
      const ok = window.confirm((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Importing in the background. Are you sure you want to leave?', 'famethemes-demo-importer'));
      if (!ok) return;
      _api__WEBPACK_IMPORTED_MODULE_3__.jobs.cancel(jobId).catch(() => {/* runner picks it up at next safe boundary */});
    }
    // Successful import wrote theme_mods + installed fonts via the
    // adapter — the dashboard host (or standalone page) likely
    // shows stale data until a refetch. Hard reload keeps it
    // simple: closes the wizard AND picks up the new state in one
    // step. Cancelled / failed runs just close without reload.
    if (status === 'completed') {
      window.location.reload();
      return;
    }
    onClose();
  };
  const next = () => {
    if (step >= STEPS.length - 1) {
      handleStart();
      return;
    }
    setStep(s => s + 1);
  };
  const back = () => setStep(s => Math.max(0, s - 1));
  const isFirst = step === 0;
  const isLast = step === STEPS.length - 1;
  const showSteps = !importing;
  const showInstall = importing && !(status === 'completed');
  const showDone = status === 'completed';
  const showWarning = !contentEnabled && (optWidgets || optCustomizer);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
    className: "fdi-wizard",
    role: "dialog",
    "aria-modal": "true",
    "aria-labelledby": "fdi-wizard-title",
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-wizard__body",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("aside", {
        className: "fdi-sidebar",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("header", {
          className: "fdi-sidebar__header",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
            className: "fdi-sidebar__title",
            children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Setting up:', 'famethemes-demo-importer'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("strong", {
              id: "fdi-wizard-title",
              children: title
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
            className: "fdi-sidebar__divider"
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
          className: "fdi-sidebar__body",
          children: [detailErr && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
            className: "fdi-error",
            children: detailErr
          }), showSteps && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.Fragment, {
            children: [step === 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(StyleStep, {
              palette: palette,
              setPalette: setPalette,
              typography: typography,
              setTypography: setTypography
            }), step === 1 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(PluginsStep, {
              required: requiredPlugins,
              recommended: recommendedPlugins,
              isChecked: isPluginChecked,
              onToggle: togglePlugin,
              bulkLabel: allOptionalUnchecked ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Check all', 'famethemes-demo-importer') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Uncheck all', 'famethemes-demo-importer'),
              onBulkToggle: bulkToggleOptional,
              loadingDetail: !detail
            }), step === 2 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(ContentStep, {
              contentEnabled: contentEnabled,
              setContentEnabled: setContentEnabled,
              optWidgets: optWidgets,
              setOptWidgets: setOptWidgets,
              optCustomizer: optCustomizer,
              setOptCustomizer: setOptCustomizer,
              showWarning: showWarning
            }), startError && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
              className: "fdi-error",
              children: startError
            })]
          }), showInstall && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(InstallProgress, {
            status: status,
            percent: percent,
            phases: PHASES,
            message: job?.progress?.message || '',
            error: job?.error,
            warnings: job?.warnings
          }), showDone && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(DoneScreen, {
            onClose: handleClose,
            home: window.ftDemoImporter?.home || '/'
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("footer", {
          className: "fdi-sidebar__footer",
          children: [showSteps && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
            className: "fdi-step-actions",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
              variant: "tertiary",
              onClick: isFirst ? handleClose : back,
              children: isFirst ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Close', 'famethemes-demo-importer') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('← Back', 'famethemes-demo-importer')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
              className: "fdi-step-actions__spacer"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
              variant: "tertiary",
              onClick: next,
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Skip', 'famethemes-demo-importer')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
              variant: "primary",
              onClick: next,
              isBusy: starting,
              disabled: starting,
              children: isLast ? starting ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Starting…', 'famethemes-demo-importer') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Start →', 'famethemes-demo-importer') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Next →', 'famethemes-demo-importer')
            })]
          }), showInstall && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
            className: "fdi-step-actions",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
              className: "fdi-step-actions__spacer"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
              variant: "secondary",
              isDestructive: true,
              onClick: handleClose,
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Cancel import', 'famethemes-demo-importer')
            })]
          })]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "fdi-preview",
        children: iframeUrl ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("iframe", {
          ref: iframeRef,
          className: "fdi-preview__iframe",
          src: iframeUrl,
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: template title */(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Preview of %s', 'famethemes-demo-importer'), title),
          loading: "lazy",
          onLoad: sendStyleToIframe
        }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
          className: "fdi-preview__fallback",
          children: detail ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No preview URL available.', 'famethemes-demo-importer') : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Spinner, {})
        })
      })]
    })
  });
}

// ── Step 0 ──────────────────────────────────────────────────────────────────

function StyleStep({
  palette,
  setPalette,
  typography,
  setTypography
}) {
  const palettes = getPalettes();
  const fonts = getFonts();

  // Load every pair's heading + body family into the admin page so the
  // "Ag" preview chip and the label both render in their real font.
  // Without this the inline `style={{ fontFamily }}` falls back to the
  // generic family (serif), which is exactly the misrender the user
  // was seeing. One <link> per family, deduped via a stable id; the
  // nodes live for the rest of the admin session — no cleanup needed.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const familyToWeights = new Map();
    const note = (family, weight) => {
      if (!family) return;
      const set = familyToWeights.get(family) || new Set();
      set.add(400);
      if (weight) set.add(weight);
      familyToWeights.set(family, set);
    };
    fonts.forEach(f => {
      note(f.heading, f.weight);
      if (f.body !== f.heading) note(f.body, 400);
    });
    familyToWeights.forEach((weights, family) => {
      const id = 'fdi-font-' + family.toLowerCase().replace(/[^a-z0-9]+/g, '-');
      if (document.getElementById(id)) return;
      const link = document.createElement('link');
      link.id = id;
      link.rel = 'stylesheet';
      const encoded = encodeURIComponent(family).replace(/%20/g, '+');
      const weightsStr = Array.from(weights).sort((a, b) => a - b).join(',');
      link.href = `https://fonts.googleapis.com/css?family=${encoded}:${weightsStr}&display=swap`;
      document.head.appendChild(link);
    });
  }, [fonts]);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("section", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h3", {
      className: "fdi-step__heading",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Choose a style', 'famethemes-demo-importer')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "fdi-step__lede",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Pick a color palette and font pair. Both apply after content is imported and can be changed later from the Customizer.', 'famethemes-demo-importer')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-style-section",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h4", {
        className: "fdi-style-subheading",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Color palette', 'famethemes-demo-importer')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "fdi-tile-grid",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("button", {
          type: "button",
          className: 'fdi-tile fdi-tile--skip' + (palette === null ? ' is-selected' : ''),
          onClick: () => setPalette(null),
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
            className: "fdi-tile__skip-dash"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
            className: "fdi-tile__label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Keep current', 'famethemes-demo-importer')
          })]
        }), palettes.map(p => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("button", {
          type: "button",
          className: 'fdi-tile' + (palette === p.id ? ' is-selected' : ''),
          onClick: () => setPalette(p.id),
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
            className: "fdi-tile__swatches",
            children: p.colors.map((c, i) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
              className: "fdi-tile__swatch",
              style: {
                background: c
              }
            }, i))
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
            className: "fdi-tile__label",
            children: p.name
          })]
        }, p.id))]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-style-section",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h4", {
        className: "fdi-style-subheading",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Typography', 'famethemes-demo-importer')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "fdi-tile-grid",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("button", {
          type: "button",
          className: 'fdi-tile fdi-tile--skip' + (typography === null ? ' is-selected' : ''),
          onClick: () => setTypography(null),
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
            className: "fdi-tile__skip-dash"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
            className: "fdi-tile__label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Keep current', 'famethemes-demo-importer')
          })]
        }), fonts.map(f => {
          const fontStyle = {
            fontFamily: `'${f.heading}', serif`,
            fontWeight: f.weight
          };
          return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("button", {
            type: "button",
            className: 'fdi-tile fdi-tile--font' + (typography === f.id ? ' is-selected' : ''),
            onClick: () => setTypography(f.id),
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
              className: "fdi-tile__font-heading",
              style: fontStyle,
              children: "Ag"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("span", {
              className: "fdi-tile__label",
              style: fontStyle,
              children: [f.heading, " \xB7 ", f.body]
            })]
          }, f.id);
        })]
      })]
    })]
  });
}

// ── Step 1 ──────────────────────────────────────────────────────────────────

function PluginsStep({
  required,
  recommended,
  isChecked,
  onToggle,
  bulkLabel,
  onBulkToggle,
  loadingDetail
}) {
  const renderCard = p => {
    const checked = isChecked(p);
    const classes = ['fdi-plugin'];
    if (checked) classes.push('is-checked');
    if (p.installed) classes.push('is-installed');
    if (p.required) classes.push('is-required');
    const disabled = p.required || p.installed;
    const trailing = p.installed ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
      className: "fdi-plugin__installed-tag",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Already installed', 'famethemes-demo-importer')
    }) : p.source && p.source !== 'wordpress.org' ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
      className: "fdi-plugin__source",
      children: p.source
    }) : null;

    // Use a div so the card can wrap whatever block-level content
    // the design wants. Toggleable cards still expose checkbox
    // semantics (role + aria-checked + Space/Enter activation) so
    // keyboard users get the same interaction the old <button> gave.
    const interactive = !disabled;
    const handleClick = interactive ? () => onToggle(p.slug) : undefined;
    const handleKeyDown = interactive ? e => {
      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        onToggle(p.slug);
      }
    } : undefined;
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: classes.join(' '),
      role: interactive ? 'checkbox' : undefined,
      "aria-checked": interactive ? checked : undefined,
      "aria-disabled": disabled || undefined,
      tabIndex: interactive ? 0 : undefined,
      onClick: handleClick,
      onKeyDown: handleKeyDown,
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "fdi-plugin__head",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
          className: "fdi-plugin__name",
          children: p.name
        }), trailing, /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
          className: "fdi-plugin__check",
          "aria-hidden": "true"
        })]
      }), p.desc && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "fdi-plugin__desc",
        children: p.desc
      })]
    }, p.slug);
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("section", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h3", {
      className: "fdi-step__heading",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Required & recommended plugins', 'famethemes-demo-importer')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "fdi-step__lede",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Required plugins are needed for the demo to work and will be installed automatically. You can uncheck any recommended one you don’t want.', 'famethemes-demo-importer')
    }), loadingDetail ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "fdi-loading",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Spinner, {})
    }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-plugins",
      children: [required.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "fdi-plugins-section",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h4", {
          className: "fdi-plugins-section__title",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Required', 'famethemes-demo-importer')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
          className: "fdi-plugins-grid",
          children: required.map(renderCard)
        })]
      }), recommended.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "fdi-plugins-section",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("h4", {
          className: "fdi-plugins-section__title",
          children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Recommended', 'famethemes-demo-importer'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
            type: "button",
            className: "fdi-plugins-section__bulk",
            onClick: onBulkToggle,
            children: bulkLabel
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
          className: "fdi-plugins-grid",
          children: recommended.map(renderCard)
        })]
      }), required.length === 0 && recommended.length === 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
        className: "fdi-step__hint",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('This template doesn’t require any plugins.', 'famethemes-demo-importer')
      })]
    })]
  });
}

// ── Step 2 ──────────────────────────────────────────────────────────────────

function ContentStep({
  contentEnabled,
  setContentEnabled,
  optWidgets,
  setOptWidgets,
  optCustomizer,
  setOptCustomizer,
  showWarning
}) {
  const toggleCard = (checked, label, desc, onToggle) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("button", {
    type: "button",
    className: 'fdi-plugin' + (checked ? ' is-checked' : ''),
    onClick: onToggle,
    "aria-pressed": checked,
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-plugin__head",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
        className: "fdi-plugin__name",
        children: label
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
        className: "fdi-plugin__check",
        "aria-hidden": "true"
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "fdi-plugin__desc",
      children: desc
    })]
  });
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("section", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h3", {
      className: "fdi-step__heading",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Content & options', 'famethemes-demo-importer')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "fdi-step__lede",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Choose what to import: demo content, widgets and Customizer settings.', 'famethemes-demo-importer')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-style-section",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h4", {
        className: "fdi-style-subheading",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Demo content', 'famethemes-demo-importer')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "fdi-plugins-grid",
        children: toggleCard(contentEnabled, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Import demo content', 'famethemes-demo-importer'), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Sample posts, pages, categories and media so the site matches the demo.', 'famethemes-demo-importer'), () => setContentEnabled(!contentEnabled))
      }), showWarning && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "fdi-warning",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('⚠ Widgets & menus may reference pages that won’t exist if you skip demo content.', 'famethemes-demo-importer')
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-style-section",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h4", {
        className: "fdi-style-subheading",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Theme options', 'famethemes-demo-importer')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "fdi-plugins-grid",
        children: [toggleCard(optWidgets, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Widgets', 'famethemes-demo-importer'), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Sidebar and footer widget areas from the demo.', 'famethemes-demo-importer'), () => setOptWidgets(!optWidgets)), toggleCard(optCustomizer, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Customizer settings', 'famethemes-demo-importer'), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Header, footer, blog layout, container width, social links… (theme mods)', 'famethemes-demo-importer'), () => setOptCustomizer(!optCustomizer))]
      })]
    })]
  });
}

// ── Install ─────────────────────────────────────────────────────────────────

function InstallProgress({
  status,
  percent,
  phases,
  message,
  error,
  warnings
}) {
  const pct = Math.max(0, Math.min(100, percent));
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("section", {
    className: "fdi-install",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-install__overall",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "fdi-install__pct",
        children: [pct, "%"]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "fdi-install__label",
        children: status === 'failed' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed', 'famethemes-demo-importer') : status === 'queued' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Starting…', 'famethemes-demo-importer') : message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Working…', 'famethemes-demo-importer')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "fdi-install__bar",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
          className: "fdi-install__bar-fill",
          style: {
            width: `${pct}%`
          }
        })
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("ol", {
      className: "fdi-install__phases",
      children: phases.map(p => {
        let cls = 'is-pending';
        if (percent >= p.to) cls = 'is-done';else if (percent >= p.from) cls = status === 'failed' ? 'is-failed' : 'is-running';
        return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("li", {
          className: `fdi-install__phase ${cls}`,
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
            className: "fdi-install__phase-icon",
            "aria-hidden": "true"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
            className: "fdi-install__phase-label",
            children: p.label
          })]
        }, p.key);
      })
    }), status === 'failed' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-status is-error",
      children: ["\u2717 ", error || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Import failed.', 'famethemes-demo-importer')]
    }), status === 'cancelled' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "fdi-status is-warning",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Import cancelled.', 'famethemes-demo-importer')
    }), Array.isArray(warnings) && warnings.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("details", {
      className: "fdi-warnings",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("summary", {
        children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Warnings', 'famethemes-demo-importer'), " (", warnings.length, ")"]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("ul", {
        children: warnings.map((w, i) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("li", {
          children: w
        }, i))
      })]
    })]
  });
}

// ── Done ────────────────────────────────────────────────────────────────────

function DoneScreen({
  onClose,
  home
}) {
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("section", {
    className: "fdi-done",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "fdi-done__check",
      children: "\u2713"
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h3", {
      className: "fdi-done__heading",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Your site is ready', 'famethemes-demo-importer')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "fdi-done__lede",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Demo content has been imported and styling applied.', 'famethemes-demo-importer')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-done__actions",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
        variant: "primary",
        href: home,
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('View site →', 'famethemes-demo-importer')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
        variant: "secondary",
        href: "post-new.php?post_type=page",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Edit home page', 'famethemes-demo-importer')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
        variant: "secondary",
        href: "customize.php",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Open Customizer', 'famethemes-demo-importer')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
        variant: "tertiary",
        onClick: onClose,
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Close', 'famethemes-demo-importer')
      })]
    })]
  });
}

/***/ },

/***/ "./src/generic/components/TemplateCard.jsx"
/*!*************************************************!*\
  !*** ./src/generic/components/TemplateCard.jsx ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   TemplateCard: () => (/* binding */ TemplateCard)
/* harmony export */ });
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__);
/**
 * One template tile in the grid — 3:4 thumb, title row with Preview +
 * Import buttons. Pro badge pins to the top-right of the thumb when
 * the template is gated. Clicking the thumb (or Import) opens the
 * wizard; Preview routes to the template's demo URL in a new tab.
 *
 * Buttons use `@wordpress/components` `<Button>` so they inherit the
 * admin scheme and accessibility behavior of every other WP admin
 * surface — no custom button styling on this component.
 */




function TemplateCard({
  template,
  onSelect
}) {
  // Studio's preview_image is a nested attachment-shape (see
  // `docs/studio/rest-api.md` §preview_image): full / medium / thumb
  // crops, plus a flat top-level `url`. Prefer the largest crop the
  // browser will actually render at 3:4 card width — `full` typically
  // matches the source upload size; `medium` is the 300x200 thumbnail
  // fallback for templates with smaller source images.
  //
  // `template.preview_url` is the iframe demo route (e.g. `?pmbd_preview=N`),
  // NOT an image URL — used by PreviewPanel.jsx, never by the card.
  const preview = template.preview_image;
  const thumb = preview?.full?.url || preview?.medium?.url || preview?.url || template.thumb_url || '';
  const name = template.title || template.name || `#${template.id}`;
  const isPro = Boolean(template.is_pro || template.pro);
  const demoUrl = template.demo_url || template.frame_url || template.preview_route || '';
  const openWizard = () => onSelect(template);
  const handleThumbClick = e => {
    // Buttons inside the body row handle their own clicks via
    // `<Button onClick>`; only the thumb surface itself opens the
    // wizard, so stopPropagation on the buttons isn't needed.
    e.preventDefault();
    openWizard();
  };
  const openDemo = e => {
    e.preventDefault();
    if (demoUrl) {
      window.open(demoUrl, '_blank', 'noopener,noreferrer');
    } else {
      openWizard();
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("article", {
    className: "fdi-card",
    "data-template-id": template.id,
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("div", {
      className: "fdi-card__thumb",
      onClick: handleThumbClick,
      role: "button",
      tabIndex: 0,
      onKeyDown: e => {
        if (e.key === 'Enter') handleThumbClick(e);
      },
      "aria-label": name,
      children: [isPro && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("span", {
        className: "fdi-card__pro-badge",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Pro', 'famethemes-demo-importer')
      }), thumb && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("img", {
        src: thumb,
        alt: "",
        loading: "lazy"
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("div", {
      className: "fdi-card__body",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("div", {
        className: "fdi-card__title",
        title: name,
        children: name
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("div", {
        className: "fdi-card__actions",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Button, {
          variant: "secondary",
          onClick: openDemo,
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Preview', 'famethemes-demo-importer')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Button, {
          variant: "primary",
          onClick: openWizard,
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Import', 'famethemes-demo-importer')
        })]
      })]
    })]
  });
}

/***/ },

/***/ "./src/generic/components/TemplateGrid.jsx"
/*!*************************************************!*\
  !*** ./src/generic/components/TemplateGrid.jsx ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   TemplateGrid: () => (/* binding */ TemplateGrid)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_icons__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/icons */ "./node_modules/.pnpm/@wordpress+icons@13.2.0_react@19.2.7/node_modules/@wordpress/icons/build-module/library/category.mjs");
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../api */ "./src/generic/api.js");
/* harmony import */ var _TemplateCard__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./TemplateCard */ "./src/generic/components/TemplateCard.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__);
/**
 * Starter Templates page — page header + category pills + search +
 * card grid. Matches mockup3.html (UX research). All colors / buttons
 * use WP admin's `--wp-admin-theme-color` so the dashboard reflects
 * the user's admin color scheme.
 *
 * Categories come from `GET /studio/categories` (Studio template
 * categories scoped to the active theme). An "All" pseudo-entry is
 * prepended client-side. Filtering is client-side once the page of
 * templates is loaded — keeps the topbar feeling instant. Search runs
 * through the existing `?search=` query param so longer libraries
 * still return relevant results from the server.
 */








const PER_PAGE = 24;
function TemplateGrid({
  onSelect
}) {
  const [items, setItems] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [total, setTotal] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(0);
  const [page, setPage] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(1);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [categories, setCategories] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [activeCat, setActiveCat] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('all');
  const [search, setSearch] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');

  // Categories — one-shot fetch on mount. Failure leaves the strip
  // empty (just the "All" pill) rather than blocking the grid.
  //
  // Studio response shape: `{ type, categories: [...], total, uncategorized }`.
  // Accept a bare array or `{items:[...]}` too for resilience against
  // future Studio versions that might normalize the envelope.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    let cancelled = false;
    _api__WEBPACK_IMPORTED_MODULE_4__.studio.listCategories().then(res => {
      if (cancelled) {
        return;
      }
      const list = Array.isArray(res) ? res : res?.categories || res?.items || [];
      setCategories(list);
    }).catch(() => {/* silent — strip just shows "All" */});
    return () => {
      cancelled = true;
    };
  }, []);

  // Templates — refetch when page or search changes. Category filter
  // is applied client-side (see filtered below) so toggling pills
  // doesn't refire the network call.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    let cancelled = false;
    setLoading(true);
    _api__WEBPACK_IMPORTED_MODULE_4__.studio.listTemplates({
      page,
      per_page: PER_PAGE,
      search
    }).then(res => {
      if (cancelled) {
        return;
      }
      const incoming = Array.isArray(res?.items) ? res.items : [];
      setItems(prev => page === 1 ? incoming : [...prev, ...incoming]);
      // Studio shape: `{items, meta:{page, per_page, total, total_pages}}`.
      // Fall back to root `total` and finally to the page's own
      // length so a missing envelope doesn't make `hasMore` lie.
      setTotal(res?.meta?.total ?? res?.total ?? incoming.length);
      setError(null);
    }).catch(e => {
      if (!cancelled) {
        setError(e.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Failed to load templates.', 'famethemes-demo-importer'));
      }
    }).finally(() => {
      if (!cancelled) {
        setLoading(false);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [page, search]);
  const filtered = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => {
    if (activeCat === 'all') {
      return items;
    }
    return items.filter(t => {
      const slugs = Array.isArray(t.category_slugs) ? t.category_slugs : Array.isArray(t.categories) ? t.categories.map(c => c.slug || c) : [];
      return slugs.includes(activeCat);
    });
  }, [items, activeCat]);
  const hasMore = items.length < total;
  const handleSearch = value => {
    setSearch(value);
    setPage(1);
  };
  const isEmbedded = !!(typeof window !== 'undefined' && window.ftDemoImporter?.embedded);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
    className: 'fdi-grid-page' + (isEmbedded ? ' is-embedded' : ''),
    children: [!isEmbedded && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("header", {
      className: "fdi-page-header",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h1", {
        className: "wp-heading-inline",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Starter Templates', 'famethemes-demo-importer')
      })
    }), error && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
      status: "error",
      isDismissible: false,
      children: error
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-topbar",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "fdi-categories",
        children: (() => {
          const allLabel = (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('All', 'famethemes-demo-importer');
          const choices = [{
            label: allLabel,
            value: 'all'
          }, ...categories.map(c => {
            const slug = c.slug || c.id || c.name;
            return {
              label: c.name || c.label || slug,
              value: String(slug)
            };
          })];
          const current = choices.find(ch => ch.value === String(activeCat));
          const triggerText = current ? current.label : allLabel;
          return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.DropdownMenu, {
            icon: _wordpress_icons__WEBPACK_IMPORTED_MODULE_3__["default"],
            text: triggerText,
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Filter by category', 'famethemes-demo-importer'),
            toggleProps: {
              className: 'fdi-categories__toggle'
            },
            popoverProps: {
              placement: 'bottom-start'
            },
            children: ({
              onClose
            }) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.MenuGroup, {
              children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.MenuItemsChoice, {
                choices: choices,
                value: String(activeCat),
                onSelect: slug => {
                  setActiveCat(slug);
                  onClose();
                }
              })
            })
          });
        })()
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "fdi-search",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.SearchControl, {
          __nextHasNoMarginBottom: true,
          value: search,
          onChange: handleSearch,
          placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Search templates…', 'famethemes-demo-importer'),
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Search templates', 'famethemes-demo-importer'),
          hideLabelFromVision: true
        })
      })]
    }), loading && items.length === 0 ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "fdi-loading",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Spinner, {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Loading templates from Studio…', 'famethemes-demo-importer')
      })]
    }) : filtered.length === 0 ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "fdi-empty",
      children: search || activeCat !== 'all' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('No templates match your filter.', 'famethemes-demo-importer') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('No templates available yet.', 'famethemes-demo-importer')
    }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "fdi-grid",
      children: filtered.map(t => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_TemplateCard__WEBPACK_IMPORTED_MODULE_5__.TemplateCard, {
        template: t,
        onSelect: onSelect
      }, t.id))
    }), hasMore && !loading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "fdi-load-more",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
        variant: "secondary",
        onClick: () => setPage(p => p + 1),
        isBusy: loading,
        disabled: loading,
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Load more', 'famethemes-demo-importer')
      })
    })]
  });
}

/***/ },

/***/ "./src/generic/hooks/useJob.js"
/*!*************************************!*\
  !*** ./src/generic/hooks/useJob.js ***!
  \*************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   useJob: () => (/* binding */ useJob)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../api */ "./src/generic/api.js");
/**
 * Polling hook for an in-flight import job.
 *
 * Returns the latest job state, kicks off polling on mount, stops on
 * unmount or when the job enters a terminal status (completed / failed
 * / cancelled). Cleanup is automatic — no need for caller to clear the
 * interval.
 *
 * Polling cadence comes from `ftDemoImporter.pollIntervalMs` (set by
 * Generic_Dashboard::enqueue_assets); defaults to 2000 ms.
 */



const TERMINAL = ['completed', 'failed', 'cancelled'];
const INTERVAL_MS = window.ftDemoImporter && window.ftDemoImporter.pollIntervalMs || 2000;
function useJob(jobId) {
  const [job, setJob] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const timerRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    if (!jobId) {
      return undefined;
    }
    let cancelled = false;
    const tick = () => {
      _api__WEBPACK_IMPORTED_MODULE_1__.jobs.get(jobId).then(res => {
        if (cancelled) {
          return;
        }
        setJob(res);
        if (!TERMINAL.includes(res.status)) {
          timerRef.current = setTimeout(tick, INTERVAL_MS);
        }
      }).catch(e => {
        if (cancelled) {
          return;
        }
        setError(e.message || 'Failed to poll job.');
      });
    };
    tick();
    return () => {
      cancelled = true;
      if (timerRef.current) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }
    };
  }, [jobId]);
  return {
    job,
    error
  };
}

/***/ },

/***/ "./src/generic/placeholders.js"
/*!*************************************!*\
  !*** ./src/generic/placeholders.js ***!
  \*************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   FONTS: () => (/* binding */ FONTS),
/* harmony export */   PALETTES: () => (/* binding */ PALETTES)
/* harmony export */ });
/**
 * Placeholder data for features that don't have a backend yet.
 *
 * Color palettes and font pairs are presented in the wizard's "Style"
 * step so the UX can be reviewed end-to-end, but the user's selection
 * isn't sent to the job runner — those fields land in the future when
 * the Studio exposes a style payload alongside templates.
 *
 * Once that backend lands, move these arrays into a `/studio/styles`
 * REST response and feed the wizard from there.
 */

const PALETTES = [{
  id: 'warm',
  name: 'Warm Sunset',
  colors: ['#1c1c1c', '#e85d04', '#faa307', '#ffd6a5']
}, {
  id: 'cool',
  name: 'Ocean Cool',
  colors: ['#0f1f3a', '#2196f3', '#87ceeb', '#f7f9fc']
}, {
  id: 'forest',
  name: 'Forest',
  colors: ['#1f3a23', '#6b8e23', '#c4d8a2', '#f1f5e8']
}, {
  id: 'mono',
  name: 'Monochrome',
  colors: ['#0a0a0a', '#404040', '#b0b0b0', '#f4f4f4']
}, {
  id: 'bold',
  name: 'Bold Pop',
  colors: ['#d81b60', '#fdd835', '#1a237e', '#fafafa']
}];
const FONTS = [{
  id: 'inter-inter',
  heading: 'Inter',
  body: 'Inter',
  weight: 600
}, {
  id: 'playfair-source',
  heading: 'Playfair Display',
  body: 'Source Sans 3',
  weight: 600
}, {
  id: 'lora-merri',
  heading: 'Lora',
  body: 'Merriweather',
  weight: 400
}, {
  id: 'poppins-roboto',
  heading: 'Poppins',
  body: 'Roboto',
  weight: 600
}, {
  id: 'mont-opensans',
  heading: 'Montserrat',
  body: 'Open Sans',
  weight: 600
}, {
  id: 'bebas-lato',
  heading: 'Bebas Neue',
  body: 'Lato',
  weight: 400
}];

/***/ },

/***/ "./src/generic/admin.scss"
/*!********************************!*\
  !*** ./src/generic/admin.scss ***!
  \********************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ },

/***/ "react/jsx-runtime"
/*!**********************************!*\
  !*** external "ReactJSXRuntime" ***!
  \**********************************/
(module) {

module.exports = window["ReactJSXRuntime"];

/***/ },

/***/ "@wordpress/api-fetch"
/*!**********************************!*\
  !*** external ["wp","apiFetch"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["apiFetch"];

/***/ },

/***/ "@wordpress/components"
/*!************************************!*\
  !*** external ["wp","components"] ***!
  \************************************/
(module) {

module.exports = window["wp"]["components"];

/***/ },

/***/ "@wordpress/dom-ready"
/*!**********************************!*\
  !*** external ["wp","domReady"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["domReady"];

/***/ },

/***/ "@wordpress/element"
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
(module) {

module.exports = window["wp"]["element"];

/***/ },

/***/ "@wordpress/i18n"
/*!******************************!*\
  !*** external ["wp","i18n"] ***!
  \******************************/
(module) {

module.exports = window["wp"]["i18n"];

/***/ },

/***/ "@wordpress/primitives"
/*!************************************!*\
  !*** external ["wp","primitives"] ***!
  \************************************/
(module) {

module.exports = window["wp"]["primitives"];

/***/ },

/***/ "./node_modules/.pnpm/@wordpress+icons@13.2.0_react@19.2.7/node_modules/@wordpress/icons/build-module/library/category.mjs"
/*!*********************************************************************************************************************************!*\
  !*** ./node_modules/.pnpm/@wordpress+icons@13.2.0_react@19.2.7/node_modules/@wordpress/icons/build-module/library/category.mjs ***!
  \*********************************************************************************************************************************/
(__unused_webpack___webpack_module__, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ category_default)
/* harmony export */ });
/* harmony import */ var _wordpress_primitives__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/primitives */ "@wordpress/primitives");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
// packages/icons/src/library/category.tsx


var category_default = /* @__PURE__ */ (0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)(_wordpress_primitives__WEBPACK_IMPORTED_MODULE_0__.SVG, { xmlns: "http://www.w3.org/2000/svg", viewBox: "0 0 24 24", children: /* @__PURE__ */ (0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)(_wordpress_primitives__WEBPACK_IMPORTED_MODULE_0__.Path, { fillRule: "evenodd", clipRule: "evenodd", d: "M6 5.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5H6a.5.5 0 01-.5-.5V6a.5.5 0 01.5-.5zM4 6a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm11-.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5h-3a.5.5 0 01-.5-.5V6a.5.5 0 01.5-.5zM13 6a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2h-3a2 2 0 01-2-2V6zm5 8.5h-3a.5.5 0 00-.5.5v3a.5.5 0 00.5.5h3a.5.5 0 00.5-.5v-3a.5.5 0 00-.5-.5zM15 13a2 2 0 00-2 2v3a2 2 0 002 2h3a2 2 0 002-2v-3a2 2 0 00-2-2h-3zm-9 1.5h3a.5.5 0 01.5.5v3a.5.5 0 01-.5.5H6a.5.5 0 01-.5-.5v-3a.5.5 0 01.5-.5zM4 15a2 2 0 012-2h3a2 2 0 012 2v3a2 2 0 01-2 2H6a2 2 0 01-2-2v-3z" }) });

//# sourceMappingURL=category.mjs.map


/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		if (!(moduleId in __webpack_modules__)) {
/******/ 			delete __webpack_module_cache__[moduleId];
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!******************************!*\
  !*** ./src/generic/admin.js ***!
  \******************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _admin_scss__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./admin.scss */ "./src/generic/admin.scss");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/dom-ready */ "@wordpress/dom-ready");
/* harmony import */ var _wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _components_App__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./components/App */ "./src/generic/components/App.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);
/**
 * Generic-track admin app entry.
 *
 * Two modes, decided by PHP via `window.ftDemoImporter.embedded`:
 *
 *   - Standalone (default): auto-mounts `<App/>` into
 *     `#ft-demo-importer-app` rendered by `Generic_Dashboard::dashboard()`.
 *
 *   - Embedded: host page (e.g. Customify dashboard) imports the importer
 *     bundle via the adapter's `embed_host_hook()` and calls
 *     `window.ftDemoImporter.mount(el)` from its own React lifecycle.
 *     Auto-mount is skipped — the host owns the mount slot's DOM node.
 *
 * The mount/unmount API is intentionally generic — any future theme
 * adapter that opts into embedding gets the same contract for free.
 */






const roots = new WeakMap();
function mount(el) {
  if (!el || roots.has(el)) {
    return;
  }
  const root = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.createRoot)(el);
  root.render(/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_components_App__WEBPACK_IMPORTED_MODULE_3__.App, {}));
  roots.set(el, root);
}
function unmount(el) {
  const root = roots.get(el);
  if (root) {
    root.unmount();
    roots.delete(el);
  }
}

// PHP `wp_localize_script` has already populated `window.ftDemoImporter`
// with REST root, nonce, etc. Merge the public mount API on top —
// `Object.assign` preserves the boot data instead of clobbering it.
window.ftDemoImporter = Object.assign(window.ftDemoImporter || {}, {
  mount,
  unmount
});
_wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_2___default()(() => {
  if (window.ftDemoImporter?.embedded) {
    return;
  }
  const el = document.getElementById('ft-demo-importer-app');
  if (el) {
    mount(el);
  }
});
})();

/******/ })()
;
//# sourceMappingURL=admin.js.map