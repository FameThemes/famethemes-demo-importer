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

import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
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

const PER_PAGE = 24;

export function TemplateGrid({ onSelect, loadingId = null }) {
	const [items, setItems] = useState([]);
	const [total, setTotal] = useState(0);
	const [page, setPage] = useState(1);
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

	// Templates — refetch when page or search changes. Category filter
	// is applied client-side (see filtered below) so toggling pills
	// doesn't refire the network call.
	useEffect(() => {
		let cancelled = false;
		setLoading(true);
		studio.listTemplates({ page, per_page: PER_PAGE, search })
			.then((res) => {
				if (cancelled) {
					return;
				}
				const incoming = Array.isArray(res?.items) ? res.items : [];
				setItems((prev) => (page === 1 ? incoming : [...prev, ...incoming]));
				// Studio shape: `{items, meta:{page, per_page, total, total_pages}}`.
				// Fall back to root `total` and finally to the page's own
				// length so a missing envelope doesn't make `hasMore` lie.
				setTotal(res?.meta?.total ?? res?.total ?? incoming.length);
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
	}, [page, search]);

	const filtered = useMemo(() => {
		if (activeCat === 'all') {
			return items;
		}
		return items.filter((t) => {
			const slugs = Array.isArray(t.category_slugs) ? t.category_slugs
				: Array.isArray(t.categories) ? t.categories.map((c) => c.slug || c)
					: [];
			return slugs.includes(activeCat);
		});
	}, [items, activeCat]);

	const hasMore = items.length < total;

	const handleSearch = (value) => {
		setSearch(value);
		setPage(1);
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

			{hasMore && !loading && (
				<p className="fdi-load-more">
					<Button
						variant="secondary"
						onClick={() => setPage((p) => p + 1)}
						isBusy={loading}
						disabled={loading}
					>
						{__('Load more', 'famethemes-demo-importer')}
					</Button>
				</p>
			)}

		</div>
	);
}
