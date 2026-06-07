/**
 * Starter Templates page — page header + category pills + search +
 * card grid. Matches mockup3.html (UX research). All colors / buttons
 * use WP admin's `--wp-admin-theme-color` so the dashboard reflects
 * the user's admin color scheme.
 *
 * Data flow (single round-trip):
 *   - Templates  — ONE request on mount with `per_page=-1` (Studio
 *                  returns every template that matches the active
 *                  theme, capped server-side at 500). Search + category
 *                  filtering both happen client-side over the cached
 *                  `items[]` so toggling pills / typing in the box
 *                  never re-hits the network.
 *   - Categories — ONE request on mount alongside the templates fetch.
 *
 * The previous flow paged 24-at-a-time and re-fetched on every search
 * keystroke; that made each character feel laggy and forced a "Load
 * more" affordance for libraries with more than 24 items. The single
 * fetch trades one larger response (typically 50-150 KB JSON for a
 * theme's full catalog) for instant filter UX, which lines up with
 * how WP's own block-pattern picker behaves.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Spinner,
	Notice,
	SearchControl,
	DropdownMenu,
	MenuGroup,
	MenuItemsChoice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { category as categoryIcon } from '@wordpress/icons';

import { studio } from '../api';
import { TemplateCard } from './TemplateCard';

export function TemplateGrid({ onSelect, loadingId = null }) {
	const [items, setItems] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [categories, setCategories] = useState([]);
	const [activeCat, setActiveCat] = useState('all');
	const [search, setSearch] = useState('');

	// Categories — one-shot fetch on mount. Failure leaves the strip
	// empty (just the "All" pill) rather than blocking the grid.
	//
	// Studio response shape: `{ type, categories: [...], total, uncategorized }`.
	// Accept a bare array or `{items:[...]}` too for resilience against
	// future Studio versions that might normalize the envelope.
	useEffect(() => {
		let cancelled = false;
		studio.listCategories()
			.then((res) => {
				if (cancelled) {
					return;
				}
				const list = Array.isArray(res)
					? res
					: (res?.categories || res?.items || []);
				setCategories(list);
			})
			.catch(() => { /* silent — strip just shows "All" */ });
		return () => { cancelled = true; };
	}, []);

	// Templates — ONE fetch on mount, strictly scoped to the active
	// theme. `view_context=site` tells the studio to drop the universal-
	// OR fallback so the response contains only templates explicitly
	// bound to this theme's stylesheet. `per_page=-1` asks for the
	// whole list in a single response (capped at 500 server-side).
	useEffect(() => {
		let cancelled = false;
		setLoading(true);
		studio.listTemplates({ per_page: -1, view_context: 'site' })
			.then((res) => {
				if (cancelled) {
					return;
				}
				const incoming = Array.isArray(res?.items)
					? res.items
					: ( Array.isArray( res ) ? res : [] );
				setItems(incoming);
				setError(null);
			})
			.catch((e) => {
				if (!cancelled) {
					setError(e.message || __('Failed to load templates.', 'famethemes-demo-importer'));
				}
			})
			.finally(() => {
				if (!cancelled) {
					setLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, []);

	// Client-side search + category filter — both run over the same
	// in-memory `items[]`. Search matches title and `keywords[]` so the
	// behaviour matches the studio's `?search=` server-side filter
	// (which searches title + _pmbd_keywords meta). Lower-cased on both
	// sides; substring match.
	const filtered = useMemo(() => {
		const q = search.trim().toLowerCase();
		return items.filter((t) => {
			if (activeCat !== 'all') {
				const slugs = Array.isArray(t.category_slugs) ? t.category_slugs
					: Array.isArray(t.categories) ? t.categories.map((c) => c.slug || c)
						: [];
				if (!slugs.includes(activeCat)) {
					return false;
				}
			}
			if (q === '') {
				return true;
			}
			const title = String(t.title || '').toLowerCase();
			if (title.includes(q)) {
				return true;
			}
			const kws = Array.isArray(t.keywords) ? t.keywords : [];
			return kws.some((k) => String(k).toLowerCase().includes(q));
		});
	}, [items, activeCat, search]);

	const handleSearch = (value) => {
		setSearch(value);
	};

	const isEmbedded = !! ( typeof window !== 'undefined' && window.ftDemoImporter?.embedded );

	return (
		<div className={ 'fdi-grid-page' + ( isEmbedded ? ' is-embedded' : '' ) }>
			{ ! isEmbedded && (
				<header className="fdi-page-header">
					<h1 className="wp-heading-inline">
						{__('Starter Templates', 'famethemes-demo-importer')}
					</h1>
				</header>
			) }

			{error && (
				<Notice status="error" isDismissible={false}>
					{error}
				</Notice>
			)}

			<div className="fdi-topbar">
				{ /*
				 * Category filter — DropdownMenu with `MenuItemsChoice` so
				 * the active slug gets a checkmark for free. The toggle
				 * surface shows the current selection inline so the user
				 * doesn't have to open the menu to see what's active.
				 */ }
				<div className="fdi-categories">
					{ ( () => {
						const allLabel = __( 'All', 'famethemes-demo-importer' );
						const choices = [
							{ label: allLabel, value: 'all' },
							...categories.map( ( c ) => {
								const slug = c.slug || c.id || c.name;
								return { label: c.name || c.label || slug, value: String( slug ) };
							} ),
						];
						const current = choices.find( ( ch ) => ch.value === String( activeCat ) );
						const triggerText = current ? current.label : allLabel;
						return (
							<DropdownMenu
								icon={ categoryIcon }
								text={ triggerText }
								label={ __( 'Filter by category', 'famethemes-demo-importer' ) }
								toggleProps={ { className: 'fdi-categories__toggle' } }
								popoverProps={ { placement: 'bottom-start' } }
							>
								{ ( { onClose } ) => (
									<MenuGroup>
										<MenuItemsChoice
											choices={ choices }
											value={ String( activeCat ) }
											onSelect={ ( slug ) => {
												setActiveCat( slug );
												onClose();
											} }
										/>
									</MenuGroup>
								) }
							</DropdownMenu>
						);
					} )() }
				</div>

				<div className="fdi-search">
					<SearchControl
						__nextHasNoMarginBottom
						value={search}
						onChange={handleSearch}
						placeholder={__('Search templates…', 'famethemes-demo-importer')}
						label={__('Search templates', 'famethemes-demo-importer')}
						hideLabelFromVision
					/>
				</div>
			</div>

			{loading && items.length === 0 ? (
				<div className="fdi-loading">
					<Spinner />
					<p>{__('Loading templates from Studio…', 'famethemes-demo-importer')}</p>
				</div>
			) : filtered.length === 0 ? (
				<div className="fdi-empty">
					{search || activeCat !== 'all'
						? __('No templates match your filter.', 'famethemes-demo-importer')
						: __('No templates available yet.', 'famethemes-demo-importer')
					}
				</div>
			) : (
				<div className="fdi-grid">
					{filtered.map((t) => (
						<TemplateCard
							key={t.id}
							template={t}
							onSelect={onSelect}
							loading={ loadingId === t.id }
						/>
					))}
				</div>
			)}

		</div>
	);
}
