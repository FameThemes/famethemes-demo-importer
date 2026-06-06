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

import { useState, useEffect, useMemo, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { close as closeIcon } from '@wordpress/icons';

import { studio, jobs } from '../api';
import { useJob } from '../hooks/useJob';
import { PALETTES as FALLBACK_PALETTES, FONTS as FALLBACK_FONTS } from '../placeholders';

/**
 * Host-provided palettes win when present (Customify adapter publishes
 * Customizer presets + user-saved palettes via window.ftDemoImporter.
 * palettes). Otherwise fall back to the plugin's placeholder set so the
 * step still renders something on themes without an adapter.
 */
function getPalettes() {
	if ( typeof window !== 'undefined' ) {
		const fromHost = window.ftDemoImporter?.palettes;
		if ( Array.isArray( fromHost ) && fromHost.length > 0 ) {
			return fromHost;
		}
	}
	return FALLBACK_PALETTES;
}

/**
 * Same pattern for font pairs — Customify adapter publishes 6 curated
 * pairs via `window.ftDemoImporter.fonts`.
 */
function getFonts() {
	if ( typeof window !== 'undefined' ) {
		const fromHost = window.ftDemoImporter?.fonts;
		if ( Array.isArray( fromHost ) && fromHost.length > 0 ) {
			return fromHost;
		}
	}
	return FALLBACK_FONTS;
}

const STEPS = [
	{ key: 'style', label: __('Style', 'famethemes-demo-importer') },
	{ key: 'plugins', label: __('Plugins', 'famethemes-demo-importer') },
	{ key: 'content', label: __('Content & options', 'famethemes-demo-importer') },
];

// Baseline importer dependency — always shown at the top of the
// Required plugins list, even if the Studio API doesn't include it.
const BLOCKSIFY_SLUG = 'blocksify';
const BLOCKSIFY_NAME = 'Blocksify';
const BLOCKSIFY_DESC = __(
	'Block library required to render Studio templates.',
	'famethemes-demo-importer'
);

const PHASES = [
	{ key: 'fetching', from: 0, to: 10, label: __('Fetching template assets', 'famethemes-demo-importer') },
	{ key: 'installing_plugins', from: 10, to: 30, label: __('Installing required plugins', 'famethemes-demo-importer') },
	{ key: 'extracting', from: 30, to: 45, label: __('Extracting uploads', 'famethemes-demo-importer') },
	{ key: 'importing_content', from: 45, to: 90, label: __('Importing content', 'famethemes-demo-importer') },
	{ key: 'applying_options', from: 90, to: 100, label: __('Applying theme options', 'famethemes-demo-importer') },
];

export function PreviewPanel({ template, onClose }) {
	const [detail, setDetail] = useState(null);
	const [detailErr, setDetailErr] = useState(null);

	const [step, setStep] = useState(0);
	const [palette, setPalette] = useState(null);
	const [typography, setTypography] = useState(null);
	const [pluginsSkip, setPluginsSkip] = useState([]);
	const [contentEnabled, setContentEnabled] = useState(true);
	const [optWidgets, setOptWidgets] = useState(true);
	const [optCustomizer, setOptCustomizer] = useState(true);

	const [jobId, setJobId] = useState(null);
	const [starting, setStarting] = useState(false);
	const [startError, setStartError] = useState(null);

	const iframeRef = useRef(null);

	// Resolve full data records (with colors / families) for the
	// currently selected palette + font pair. Memoised so the iframe
	// postMessage effect doesn't re-fire on unrelated state changes.
	const palettes = useMemo(() => getPalettes(), []);
	const fonts = useMemo(() => getFonts(), []);
	const currentPalette = useMemo(
		() => (palette ? palettes.find((p) => p.id === palette) || null : null),
		[palette, palettes]
	);
	const currentFont = useMemo(
		() => (typography ? fonts.find((f) => f.id === typography) || null : null),
		[typography, fonts]
	);

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
	const sendStyleToIframe = useCallback(() => {
		const win = iframeRef.current?.contentWindow;
		if (!win) {
			return;
		}
		try {
			win.postMessage(
				{
					type: 'fdi-preview-style',
					palette: currentPalette,
					font: currentFont,
				},
				'*'
			);
		} catch (e) {
			// Iframe not ready / cross-origin restriction during nav —
			// safe to ignore; next selection change will retry.
		}
	}, [currentPalette, currentFont]);

	// Re-send on every selection change + on iframe load (caught via
	// the `load` event below). Replays guarantee the iframe gets the
	// latest state even if it navigated mid-session.
	useEffect(() => {
		sendStyleToIframe();
	}, [sendStyleToIframe]);

	// Handshake: Studio's iframe can post `{type:'fdi-preview-ready'}`
	// to ask the parent for the current selection (handles late-load
	// race where the iframe's listener registers after our last send).
	useEffect(() => {
		const handler = (event) => {
			if (
				event?.data?.type === 'fdi-preview-ready' &&
				iframeRef.current?.contentWindow === event.source
			) {
				sendStyleToIframe();
			}
		};
		window.addEventListener('message', handler);
		return () => window.removeEventListener('message', handler);
	}, [sendStyleToIframe]);

	const { job } = useJob(jobId);
	const importing = jobId !== null;
	const status = job?.status || (importing ? 'queued' : 'idle');
	const percent = job?.progress?.percent | 0;
	const isDone = status === 'completed' || status === 'failed' || status === 'cancelled';

	// Detail fetch — needed for the recommended plugins list + canonical preview URL.
	useEffect(() => {
		let cancelled = false;
		setDetail(null);
		setDetailErr(null);
		studio.getTemplate(template.id)
			.then((res) => { if (!cancelled) setDetail(res); })
			.catch((e) => { if (!cancelled) setDetailErr(e?.message || String(e)); });
		return () => { cancelled = true; };
	}, [template.id]);

	// Lock page scroll while the wizard is open — same dance as the
	// previous PreviewPanel, prevents wp-admin from scrolling behind
	// the overlay.
	useEffect(() => {
		document.body.classList.add('fdi-wizard-open');
		return () => {
			document.body.classList.remove('fdi-wizard-open');
		};
	}, []);

	const title = template.title || template.name || `#${template.id}`;

	const rawIframeUrl = detail?.frame_url
		|| detail?.preview_route
		|| detail?.demo_url
		|| detail?.preview_url
		|| template.preview_url
		|| '';

	// Cache-bust the preview URL — Studio sites typically front WordPress
	// with a page cache (Cloudflare, WP Rocket, LiteSpeed, etc.) that
	// snapshots HTML for top-level navigation, and the cached snapshot
	// can be missing the `customify-preview-bridge` <script> tag if the
	// plugin was activated AFTER the snapshot was written. Adding a
	// per-template cachebust forces the origin to render fresh and ship
	// the script. The token is stable per (template.id, mount) so the
	// browser still caches subresources within a session.
	const iframeUrl = useMemo(() => {
		if (!rawIframeUrl) return '';
		const sep = rawIframeUrl.includes('?') ? '&' : '?';
		return rawIframeUrl + sep + '_fdi_cb=' + template.id;
	}, [rawIframeUrl, template.id]);

	// Plugins — split into required vs recommended for the sidebar UI.
	// Blocksify is always pinned at the top of the required list because
	// the importer needs the Blocksify block library to apply Studio
	// templates regardless of what any individual template declares.
	const plugins = useMemo(() => {
		const list = Array.isArray(detail?.requirements?.plugins)
			? detail.requirements.plugins
			: [];
		const mapped = list.map((p) => ({
			slug: p.slug,
			name: p.name || p.slug,
			required: Boolean(p.required),
			installed: Boolean(p.installed),
			source: p.source || '',
			desc: p.description || p.desc || '',
		}));

		const existingIdx = mapped.findIndex((p) => p.slug === BLOCKSIFY_SLUG);
		const existing = existingIdx >= 0 ? mapped.splice(existingIdx, 1)[0] : null;
		const blocksify = existing
			? { ...existing, required: true }
			: {
				slug: BLOCKSIFY_SLUG,
				name: BLOCKSIFY_NAME,
				required: true,
				installed: false,
				source: 'wordpress.org',
				desc: BLOCKSIFY_DESC,
			};

		return [blocksify, ...mapped];
	}, [detail]);

	const requiredPlugins = plugins.filter((p) => p.required);
	const recommendedPlugins = plugins.filter((p) => !p.required);

	const isPluginChecked = (p) => {
		if (p.installed) return false;
		if (p.required) return true;
		return !pluginsSkip.includes(p.slug);
	};

	const togglePlugin = (slug) => {
		setPluginsSkip((prev) =>
			prev.includes(slug) ? prev.filter((s) => s !== slug) : [...prev, slug]
		);
	};

	const allOptionalUnchecked = recommendedPlugins.length > 0
		&& recommendedPlugins.filter((p) => !p.installed).every((p) => pluginsSkip.includes(p.slug));

	const bulkToggleOptional = () => {
		const optInstallable = recommendedPlugins.filter((p) => !p.installed);
		setPluginsSkip(allOptionalUnchecked ? [] : optInstallable.map((p) => p.slug));
	};

	const handleStart = () => {
		setStartError(null);
		setStarting(true);
		jobs.create({
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
				font:    typography,
			},
		})
			.then((res) => {
				if (res?.job_id) setJobId(res.job_id);
			})
			.catch((err) => {
				setStartError(err.message || __('Failed to start import job.', 'famethemes-demo-importer'));
			})
			.finally(() => { setStarting(false); });
	};

	const handleClose = () => {
		if (importing && !isDone) {
			// eslint-disable-next-line no-alert
			const ok = window.confirm(
				__('Importing in the background. Are you sure you want to leave?', 'famethemes-demo-importer')
			);
			if (!ok) return;
			jobs.cancel(jobId).catch(() => { /* runner picks it up at next safe boundary */ });
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
		setStep((s) => s + 1);
	};
	const back = () => setStep((s) => Math.max(0, s - 1));

	const isFirst = step === 0;
	const isLast = step === STEPS.length - 1;
	const showSteps = !importing;
	const showInstall = importing && !(status === 'completed');
	const showDone = status === 'completed';

	const showWarning = !contentEnabled && (optWidgets || optCustomizer);

	return (
		<div className="fdi-wizard" role="dialog" aria-modal="true" aria-labelledby="fdi-wizard-title">


			<div className="fdi-wizard__body">
				<aside className="fdi-sidebar">
					<header className="fdi-sidebar__header">
						<div className="fdi-sidebar__title">
							{__('Setting up:', 'famethemes-demo-importer')}
							<strong id="fdi-wizard-title">{title}</strong>
						</div>
						<div className="fdi-sidebar__divider" />
					</header>

					<div className="fdi-sidebar__body">
						{detailErr && (
							<div className="fdi-error">{detailErr}</div>
						)}

						{showSteps && (
							<>
								{step === 0 && (
									<StyleStep
										palette={palette} setPalette={setPalette}
										typography={typography} setTypography={setTypography}
									/>
								)}
								{step === 1 && (
									<PluginsStep
										required={requiredPlugins}
										recommended={recommendedPlugins}
										isChecked={isPluginChecked}
										onToggle={togglePlugin}
										bulkLabel={allOptionalUnchecked
											? __('Check all', 'famethemes-demo-importer')
											: __('Uncheck all', 'famethemes-demo-importer')
										}
										onBulkToggle={bulkToggleOptional}
										loadingDetail={!detail}
									/>
								)}
								{step === 2 && (
									<ContentStep
										contentEnabled={contentEnabled} setContentEnabled={setContentEnabled}
										optWidgets={optWidgets} setOptWidgets={setOptWidgets}
										optCustomizer={optCustomizer} setOptCustomizer={setOptCustomizer}
										showWarning={showWarning}
									/>
								)}

								{startError && (
									<div className="fdi-error">{startError}</div>
								)}
							</>
						)}

						{showInstall && (
							<InstallProgress
								status={status}
								percent={percent}
								phases={PHASES}
								message={job?.progress?.message || ''}
								error={job?.error}
								warnings={job?.warnings}
							/>
						)}

						{showDone && (
							<DoneScreen onClose={handleClose} home={window.ftDemoImporter?.home || '/'} />
						)}
					</div>

					<footer className="fdi-sidebar__footer">
						{showSteps && (
							<div className="fdi-step-actions">
								<Button
									variant="tertiary"
									onClick={isFirst ? handleClose : back}
								>
									{isFirst
										? __('Close', 'famethemes-demo-importer')
										: __('← Back', 'famethemes-demo-importer')
									}
								</Button>
								<div className="fdi-step-actions__spacer" />
								<Button variant="tertiary" onClick={next}>
									{__('Skip', 'famethemes-demo-importer')}
								</Button>
								<Button
									variant="primary"
									onClick={next}
									isBusy={starting}
									disabled={starting}
								>
									{isLast
										? (starting
											? __('Starting…', 'famethemes-demo-importer')
											: __('Start →', 'famethemes-demo-importer'))
										: __('Next →', 'famethemes-demo-importer')
									}
								</Button>
							</div>
						)}
						{showInstall && (
							<div className="fdi-step-actions">
								<div className="fdi-step-actions__spacer" />
								<Button variant="secondary" isDestructive onClick={handleClose}>
									{__('Cancel import', 'famethemes-demo-importer')}
								</Button>
							</div>
						)}
					</footer>
				</aside>

				<div className="fdi-preview">
					{iframeUrl ? (
						<iframe
							ref={iframeRef}
							className="fdi-preview__iframe"
							src={iframeUrl}
							title={sprintf( /* translators: %s: template title */ __('Preview of %s', 'famethemes-demo-importer'), title)}
							loading="lazy"
							onLoad={sendStyleToIframe}
						/>
					) : (
						<div className="fdi-preview__fallback">
							{detail ? __('No preview URL available.', 'famethemes-demo-importer') : <Spinner />}
						</div>
					)}
				</div>
			</div>
		</div>
	);
}

// ── Step 0 ──────────────────────────────────────────────────────────────────

function StyleStep({ palette, setPalette, typography, setTypography }) {
	const palettes = getPalettes();
	const fonts = getFonts();

	// Load every pair's heading + body family into the admin page so the
	// "Ag" preview chip and the label both render in their real font.
	// Without this the inline `style={{ fontFamily }}` falls back to the
	// generic family (serif), which is exactly the misrender the user
	// was seeing. One <link> per family, deduped via a stable id; the
	// nodes live for the rest of the admin session — no cleanup needed.
	useEffect(() => {
		const familyToWeights = new Map();
		const note = (family, weight) => {
			if (!family) return;
			const set = familyToWeights.get(family) || new Set();
			set.add(400);
			if (weight) set.add(weight);
			familyToWeights.set(family, set);
		};
		fonts.forEach((f) => {
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

	return (
		<section>
			<h3 className="fdi-step__heading">
				{__('Choose a style', 'famethemes-demo-importer')}
			</h3>
			<p className="fdi-step__lede">
				{__('Pick a color palette and font pair. Both apply after content is imported and can be changed later from the Customizer.', 'famethemes-demo-importer')}
			</p>

			<div className="fdi-style-section">
				<h4 className="fdi-style-subheading">
					{__('Color palette', 'famethemes-demo-importer')}
				</h4>
				<div className="fdi-tile-grid">
					<button
						type="button"
						className={'fdi-tile fdi-tile--skip' + (palette === null ? ' is-selected' : '')}
						onClick={() => setPalette(null)}
					>
						<span className="fdi-tile__skip-dash" />
						<span className="fdi-tile__label">{__('Keep current', 'famethemes-demo-importer')}</span>
					</button>
					{palettes.map((p) => (
						<button
							key={p.id}
							type="button"
							className={'fdi-tile' + (palette === p.id ? ' is-selected' : '')}
							onClick={() => setPalette(p.id)}
						>
							<span className="fdi-tile__swatches">
								{p.colors.map((c, i) => (
									<span key={i} className="fdi-tile__swatch" style={{ background: c }} />
								))}
							</span>
							<span className="fdi-tile__label">{p.name}</span>
						</button>
					))}
				</div>
			</div>

			<div className="fdi-style-section">
				<h4 className="fdi-style-subheading">
					{__('Typography', 'famethemes-demo-importer')}
				</h4>
				<div className="fdi-tile-grid">
					<button
						type="button"
						className={'fdi-tile fdi-tile--skip' + (typography === null ? ' is-selected' : '')}
						onClick={() => setTypography(null)}
					>
						<span className="fdi-tile__skip-dash" />
						<span className="fdi-tile__label">{__('Keep current', 'famethemes-demo-importer')}</span>
					</button>
					{fonts.map((f) => {
						const fontStyle = { fontFamily: `'${f.heading}', serif`, fontWeight: f.weight };
						return (
							<button
								key={f.id}
								type="button"
								className={'fdi-tile fdi-tile--font' + (typography === f.id ? ' is-selected' : '')}
								onClick={() => setTypography(f.id)}
							>
								<span className="fdi-tile__font-heading" style={fontStyle}>Ag</span>
								<span className="fdi-tile__label" style={fontStyle}>{f.heading} · {f.body}</span>
							</button>
						);
					})}
				</div>
			</div>
		</section>
	);
}

// ── Step 1 ──────────────────────────────────────────────────────────────────

function PluginsStep({ required, recommended, isChecked, onToggle, bulkLabel, onBulkToggle, loadingDetail }) {
	const renderCard = (p) => {
		const checked = isChecked(p);
		const classes = ['fdi-plugin'];
		if (checked) classes.push('is-checked');
		if (p.installed) classes.push('is-installed');
		if (p.required) classes.push('is-required');

		const disabled = p.required || p.installed;
		const trailing = p.installed
			? <span className="fdi-plugin__installed-tag">{__('Already installed', 'famethemes-demo-importer')}</span>
			: (p.source && p.source !== 'wordpress.org')
				? <span className="fdi-plugin__source">{p.source}</span>
				: null;

		// Use a div so the card can wrap whatever block-level content
		// the design wants. Toggleable cards still expose checkbox
		// semantics (role + aria-checked + Space/Enter activation) so
		// keyboard users get the same interaction the old <button> gave.
		const interactive = !disabled;
		const handleClick = interactive ? () => onToggle(p.slug) : undefined;
		const handleKeyDown = interactive
			? (e) => {
				if (e.key === ' ' || e.key === 'Enter') {
					e.preventDefault();
					onToggle(p.slug);
				}
			}
			: undefined;

		return (
			<div
				key={p.slug}
				className={classes.join(' ')}
				role={interactive ? 'checkbox' : undefined}
				aria-checked={interactive ? checked : undefined}
				aria-disabled={disabled || undefined}
				tabIndex={interactive ? 0 : undefined}
				onClick={handleClick}
				onKeyDown={handleKeyDown}
			>
				<div className="fdi-plugin__head">
					<span className="fdi-plugin__name">{p.name}</span>
					{trailing}
					<span className="fdi-plugin__check" aria-hidden="true" />
				</div>
				{p.desc && <div className="fdi-plugin__desc">{p.desc}</div>}
			</div>
		);
	};

	return (
		<section>
			<h3 className="fdi-step__heading">
				{__('Required & recommended plugins', 'famethemes-demo-importer')}
			</h3>
			<p className="fdi-step__lede">
				{__('Required plugins are needed for the demo to work and will be installed automatically. You can uncheck any recommended one you don’t want.', 'famethemes-demo-importer')}
			</p>

			{loadingDetail ? (
				<div className="fdi-loading"><Spinner /></div>
			) : (
				<div className="fdi-plugins">
					{required.length > 0 && (
						<div className="fdi-plugins-section">
							<h4 className="fdi-plugins-section__title">
								{__('Required', 'famethemes-demo-importer')}
							</h4>
							<div className="fdi-plugins-grid">
								{required.map(renderCard)}
							</div>
						</div>
					)}

					{recommended.length > 0 && (
						<div className="fdi-plugins-section">
							<h4 className="fdi-plugins-section__title">
								{__('Recommended', 'famethemes-demo-importer')}
								<button
									type="button"
									className="fdi-plugins-section__bulk"
									onClick={onBulkToggle}
								>
									{bulkLabel}
								</button>
							</h4>
							<div className="fdi-plugins-grid">
								{recommended.map(renderCard)}
							</div>
						</div>
					)}

					{required.length === 0 && recommended.length === 0 && (
						<p className="fdi-step__hint">
							{__('This template doesn’t require any plugins.', 'famethemes-demo-importer')}
						</p>
					)}
				</div>
			)}
		</section>
	);
}

// ── Step 2 ──────────────────────────────────────────────────────────────────

function ContentStep({ contentEnabled, setContentEnabled, optWidgets, setOptWidgets, optCustomizer, setOptCustomizer, showWarning }) {
	const toggleCard = (checked, label, desc, onToggle) => (
		<button
			type="button"
			className={'fdi-plugin' + (checked ? ' is-checked' : '')}
			onClick={onToggle}
			aria-pressed={checked}
		>
			<div className="fdi-plugin__head">
				<span className="fdi-plugin__name">{label}</span>
				<span className="fdi-plugin__check" aria-hidden="true" />
			</div>
			<div className="fdi-plugin__desc">{desc}</div>
		</button>
	);

	return (
		<section>
			<h3 className="fdi-step__heading">
				{__('Content & options', 'famethemes-demo-importer')}
			</h3>
			<p className="fdi-step__lede">
				{__('Choose what to import: demo content, widgets and Customizer settings.', 'famethemes-demo-importer')}
			</p>

			<div className="fdi-style-section">
				<h4 className="fdi-style-subheading">
					{__('Demo content', 'famethemes-demo-importer')}
				</h4>
				<div className="fdi-plugins-grid">
					{toggleCard(
						contentEnabled,
						__('Import demo content', 'famethemes-demo-importer'),
						__('Sample posts, pages, categories and media so the site matches the demo.', 'famethemes-demo-importer'),
						() => setContentEnabled(!contentEnabled)
					)}
				</div>
				{showWarning && (
					<div className="fdi-warning">
						{__('⚠ Widgets & menus may reference pages that won’t exist if you skip demo content.', 'famethemes-demo-importer')}
					</div>
				)}
			</div>

			<div className="fdi-style-section">
				<h4 className="fdi-style-subheading">
					{__('Theme options', 'famethemes-demo-importer')}
				</h4>
				<div className="fdi-plugins-grid">
					{toggleCard(
						optWidgets,
						__('Widgets', 'famethemes-demo-importer'),
						__('Sidebar and footer widget areas from the demo.', 'famethemes-demo-importer'),
						() => setOptWidgets(!optWidgets)
					)}
					{toggleCard(
						optCustomizer,
						__('Customizer settings', 'famethemes-demo-importer'),
						__('Header, footer, blog layout, container width, social links… (theme mods)', 'famethemes-demo-importer'),
						() => setOptCustomizer(!optCustomizer)
					)}
				</div>
			</div>
		</section>
	);
}

// ── Install ─────────────────────────────────────────────────────────────────

function InstallProgress({ status, percent, phases, message, error, warnings }) {
	const pct = Math.max(0, Math.min(100, percent));
	return (
		<section className="fdi-install">
			<div className="fdi-install__overall">
				<div className="fdi-install__pct">{pct}%</div>
				<div className="fdi-install__label">
					{status === 'failed' ? __('Failed', 'famethemes-demo-importer')
						: status === 'queued' ? __('Starting…', 'famethemes-demo-importer')
							: (message || __('Working…', 'famethemes-demo-importer'))}
				</div>
				<div className="fdi-install__bar">
					<div className="fdi-install__bar-fill" style={{ width: `${pct}%` }} />
				</div>
			</div>

			<ol className="fdi-install__phases">
				{phases.map((p) => {
					let cls = 'is-pending';
					if (percent >= p.to) cls = 'is-done';
					else if (percent >= p.from) cls = (status === 'failed' ? 'is-failed' : 'is-running');
					return (
						<li key={p.key} className={`fdi-install__phase ${cls}`}>
							<span className="fdi-install__phase-icon" aria-hidden="true" />
							<span className="fdi-install__phase-label">{p.label}</span>
						</li>
					);
				})}
			</ol>

			{status === 'failed' && (
				<div className="fdi-status is-error">
					✗ {error || __('Import failed.', 'famethemes-demo-importer')}
				</div>
			)}
			{status === 'cancelled' && (
				<div className="fdi-status is-warning">
					{__('Import cancelled.', 'famethemes-demo-importer')}
				</div>
			)}

			{Array.isArray(warnings) && warnings.length > 0 && (
				<details className="fdi-warnings">
					<summary>
						{__('Warnings', 'famethemes-demo-importer')} ({warnings.length})
					</summary>
					<ul>
						{warnings.map((w, i) => <li key={i}>{w}</li>)}
					</ul>
				</details>
			)}
		</section>
	);
}

// ── Done ────────────────────────────────────────────────────────────────────

function DoneScreen({ onClose, home }) {
	return (
		<section className="fdi-done">
			<div className="fdi-done__check">✓</div>
			<h3 className="fdi-done__heading">
				{__('Your site is ready', 'famethemes-demo-importer')}
			</h3>
			<p className="fdi-done__lede">
				{__('Demo content has been imported and styling applied.', 'famethemes-demo-importer')}
			</p>
			<div className="fdi-done__actions">
				<Button variant="primary" href={home}>
					{__('View site →', 'famethemes-demo-importer')}
				</Button>
				<Button variant="secondary" href="post-new.php?post_type=page">
					{__('Edit home page', 'famethemes-demo-importer')}
				</Button>
				<Button variant="secondary" href="customize.php">
					{__('Open Customizer', 'famethemes-demo-importer')}
				</Button>
				<Button variant="tertiary" onClick={onClose}>
					{__('Close', 'famethemes-demo-importer')}
				</Button>
			</div>
		</section>
	);
}
