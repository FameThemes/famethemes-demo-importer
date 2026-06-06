/**
 * Default style builder — no-op for unknown themes.
 *
 * Returns an empty string so the iframe's `<style id="cpb-overrides">`
 * stays empty. The Style step UI still works (user can pick palette +
 * typography); the choice is carried through to the import job and
 * the theme adapter's `after_phase('applying_options')` still applies
 * it to theme_mods. Only the LIVE preview is inert.
 *
 * Themes that want live preview should ship a builder via
 * `window.fdiRegisterStyleBuilder( <slug>, ( palette, font ) => '…' )`
 * — the importer wizard picks it up automatically.
 */
export default function buildCss() {
	return '';
}
