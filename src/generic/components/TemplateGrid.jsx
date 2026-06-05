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
import { Button, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { studio } from '../api';
import { TemplateCard } from './TemplateCard';

const PER_PAGE = 24;

export function TemplateGrid({ onSelect }) {
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
	useEffect(() => {
		let cancelled = false;
		studio.listCategories()
			.then((res) => {
				if (cancelled) {
					return;
				}
				const list = Array.isArray(res) ? res : (res?.items || []);
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
				setTotal(res?.total || incoming.length);
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

	return (
		<div className="fdi-grid-page">
			<header className="fdi-page-header">
				<h1 className="wp-heading-inline">
					{__('Starter Templates', 'famethemes-demo-importer')}
				</h1>

			</header>

			{error && (
				<Notice status="error" isDismissible={false}>
					{error}
				</Notice>
			)}

			<div className="fdi-topbar">
				<nav className="fdi-categories" aria-label={__('Filter by category', 'famethemes-demo-importer')}>
					<button
						type="button"
						className={'fdi-categories__pill' + (activeCat === 'all' ? ' is-active' : '')}
						onClick={() => setActiveCat('all')}
					>
						{__('All', 'famethemes-demo-importer')}
					</button>
					{categories.map((c) => {
						const slug = c.slug || c.id || c.name;
						const label = c.name || c.label || slug;
						const count = typeof c.count === 'number' ? c.count : null;
						return (
							<button
								key={slug}
								type="button"
								className={'fdi-categories__pill' + (activeCat === slug ? ' is-active' : '')}
								onClick={() => setActiveCat(slug)}
							>
								{label}
								{count !== null && (
									<span className="fdi-categories__count"> ({count})</span>
								)}
							</button>
						);
					})}
				</nav>

				<div className="fdi-search">
					<input
						type="search"
						value={search}
						placeholder={__('Search templates…', 'famethemes-demo-importer')}
						onChange={(e) => handleSearch(e.target.value)}
						aria-label={__('Search templates', 'famethemes-demo-importer')}
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
