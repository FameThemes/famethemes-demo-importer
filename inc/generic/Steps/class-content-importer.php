<?php
/**
 * Step 4 — apply the template's content.json (terms → posts → menus).
 *
 * Ported verbatim from `blocksify-design-importer/includes/Theme/ContentImporter.php`
 * with the meta-key prefix rebranded `_pmbd_source_ref` → `_ft_source_ref`
 * so re-imports on a site that's also run blocksify-design-importer don't
 * stomp each other's idempotency markers.
 *
 * Walks the manifest in dependency order, keeping a
 * `$ref_map[source_ref => new_local_id]` table so every subsequent
 * reference (post_parent, _thumbnail_id, block attr `{{ref:post:N}}`,
 * menu item object_ref, etc.) resolves cleanly.
 *
 * Two rewrites happen per imported post:
 *   - `{{ref:(post|term):N(:missing)?}}` → numeric ID from $ref_map.
 *   - `{{SITE_URL}}`                      → home_url().
 *
 * Plus `wp-image-{source_id}` CSS classes get rewritten to the new
 * attachment ID so Gutenberg recognises them post-import.
 */

namespace FT_Demo_Importer\Steps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Content_Importer {

	public const META_SOURCE_REF = '_ft_source_ref';

	/** Whitelisted meta keys whose value is a single attachment / post ID. */
	private const META_POST_ID_KEYS = [
		'_thumbnail_id',
		'_menu_item_object_id',
		'_menu_item_menu_item_parent',
		'_product_image_gallery',
	];

	/**
	 * @param string                              $content_json_path
	 * @param array{overwrite_existing?: bool}    $opts
	 *
	 * @return array{
	 *     ref_map: array<string,int>,
	 *     counts:  array{terms:int, posts:int, attachments:int, menus:int, menu_items:int},
	 *     warnings: string[]
	 * }
	 */
	public function import( string $content_json_path, array $opts = [] ): array {
		$raw = @file_get_contents( $content_json_path );
		if ( false === $raw ) {
			throw new \RuntimeException( 'Could not read content.json' );
		}
		$parsed = json_decode( $raw, true );
		if ( ! is_array( $parsed ) ) {
			throw new \RuntimeException( 'content.json is not valid JSON.' );
		}

		$site_url  = untrailingslashit( home_url( '/' ) );
		$overwrite = ! empty( $opts['overwrite_existing'] );

		$ref_map  = [];
		$warnings = [];
		$counts   = [
			'terms'       => 0,
			'posts'       => 0,
			'attachments' => 0,
			'menus'       => 0,
			'menu_items'  => 0,
		];

		if ( $overwrite && ! empty( $parsed['posts'] ) ) {
			$this->wipe_existing_by_refs( array_column( $parsed['posts'], 'ref' ) );
		}

		// (1) Terms
		foreach ( (array) ( $parsed['terms'] ?? [] ) as $term ) {
			$id = $this->upsert_term( $term, $ref_map );
			if ( $id > 0 ) {
				$ref_map[ (string) $term['ref'] ] = $id;
				$counts['terms']++;
			}
		}

		// (2) Posts (attachments + everything except nav_menu_item)
		foreach ( (array) ( $parsed['posts'] ?? [] ) as $post ) {
			$type = (string) ( $post['post_type'] ?? '' );

			// Skip posts whose post_type isn't registered locally.
			// Plugins were installed in step 2 — anything still missing
			// here is an unmet dependency we can't fulfill. Drop + warn.
			if ( ! post_type_exists( $type ) ) {
				$warnings[] = sprintf(
					'Skipped %s (post_type "%s" not registered).',
					(string) ( $post['ref'] ?? '?' ),
					$type
				);
				continue;
			}

			// nav_menu_item handled by the structured menus[] pass below.
			if ( 'nav_menu_item' === $type ) {
				continue;
			}

			if ( 'attachment' === $type ) {
				$id = $this->upsert_attachment( $post, $ref_map, $site_url, $warnings );
				if ( $id > 0 ) {
					$ref_map[ (string) $post['ref'] ] = $id;
					$counts['attachments']++;
				}
			} else {
				$id = $this->upsert_post( $post, $ref_map, $site_url, $warnings );
				if ( $id > 0 ) {
					$ref_map[ (string) $post['ref'] ] = $id;
					$counts['posts']++;
				}
			}
		}

		// (3) Menus
		foreach ( (array) ( $parsed['menus'] ?? [] ) as $menu ) {
			$applied = $this->apply_menu( $menu, $ref_map, $warnings );
			if ( $applied > 0 ) {
				$counts['menus']++;
				$counts['menu_items'] += $applied;
			}
		}

		return [ 'ref_map' => $ref_map, 'counts' => $counts, 'warnings' => $warnings ];
	}

	// ---------------------------------------------------------------- Terms

	private function upsert_term( array $term, array $ref_map ): int {
		$taxonomy = (string) ( $term['taxonomy'] ?? '' );
		$slug     = (string) ( $term['slug'] ?? '' );
		$name     = (string) ( $term['name'] ?? $slug );
		if ( '' === $taxonomy || '' === $slug ) {
			return 0;
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$parent_id = 0;
		if ( ! empty( $term['parent_ref'] ) ) {
			$parent_id = (int) ( $ref_map[ (string) $term['parent_ref'] ] ?? 0 );
		}

		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing && ! is_wp_error( $existing ) ) {
			wp_update_term( (int) $existing->term_id, $taxonomy, [
				'name'        => $name,
				'description' => (string) ( $term['description'] ?? $existing->description ),
				'parent'      => $parent_id,
			] );
			return (int) $existing->term_id;
		}

		$res = wp_insert_term( $name, $taxonomy, [
			'slug'        => $slug,
			'description' => (string) ( $term['description'] ?? '' ),
			'parent'      => $parent_id,
		] );
		return is_wp_error( $res ) ? 0 : (int) $res['term_id'];
	}

	// ---------------------------------------------------------- Attachments

	private function upsert_attachment( array $post, array $ref_map, string $site_url, array &$warnings ): int {
		$att  = (array) ( $post['attachment'] ?? [] );
		$file = (string) ( $att['file'] ?? '' );
		$mime = (string) ( $att['mime'] ?? '' );
		if ( '' === $file || '' === $mime ) {
			$warnings[] = sprintf( 'Attachment %s missing file/mime — skipped.', (string) ( $post['ref'] ?? '?' ) );
			return 0;
		}

		$upload    = wp_get_upload_dir();
		$full_path = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . $file;
		$full_url  = trailingslashit( (string) ( $upload['baseurl'] ?? '' ) ) . $file;

		if ( ! file_exists( $full_path ) ) {
			$warnings[] = sprintf( 'Attachment file missing on disk: %s', $file );
			// Continue — wp_posts row + metadata still useful for refs.
		}

		$existing_id = $this->find_by_source_ref( (string) $post['ref'] );

		$postarr = [
			'post_title'     => (string) ( $post['post_title'] ?? '' ),
			'post_name'      => (string) ( $post['post_name'] ?? '' ),
			'post_status'    => (string) ( $post['post_status'] ?? 'inherit' ),
			'post_type'      => 'attachment',
			'post_mime_type' => $mime,
			'guid'           => $full_url,
		];
		if ( ! empty( $post['post_parent_ref'] ) ) {
			$postarr['post_parent'] = (int) ( $ref_map[ (string) $post['post_parent_ref'] ] ?? 0 );
		}

		if ( $existing_id > 0 ) {
			$postarr['ID'] = $existing_id;
			$id = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$id = wp_insert_attachment( wp_slash( $postarr ), $full_path );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			$warnings[] = 'Failed to insert attachment: ' . ( is_wp_error( $id ) ? $id->get_error_message() : 'unknown' );
			return 0;
		}
		$id = (int) $id;

		update_post_meta( $id, '_wp_attached_file', $file );
		if ( ! empty( $att['metadata'] ) && is_array( $att['metadata'] ) ) {
			update_post_meta( $id, '_wp_attachment_metadata', $att['metadata'] );
		}
		if ( ! empty( $att['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', (string) $att['alt'] );
		}
		update_post_meta( $id, self::META_SOURCE_REF, (string) $post['ref'] );

		return $id;
	}

	// ---------------------------------------------------------- Posts

	private function upsert_post( array $post, array $ref_map, string $site_url, array &$warnings ): int {
		$type = (string) ( $post['post_type'] ?? 'post' );

		$content = $this->rewrite_refs_and_urls( (string) ( $post['post_content'] ?? '' ), $ref_map, $site_url, $warnings );
		$excerpt = $this->rewrite_refs_and_urls( (string) ( $post['post_excerpt'] ?? '' ), $ref_map, $site_url, $warnings );

		$post_parent = 0;
		if ( ! empty( $post['post_parent_ref'] ) ) {
			$post_parent = (int) ( $ref_map[ (string) $post['post_parent_ref'] ] ?? 0 );
		}

		$existing_id = $this->find_by_source_ref( (string) $post['ref'] );

		$postarr = [
			'post_type'    => $type,
			'post_title'   => (string) ( $post['post_title'] ?? '' ),
			'post_name'    => (string) ( $post['post_name'] ?? '' ),
			'post_status'  => (string) ( $post['post_status'] ?? 'publish' ),
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'menu_order'   => (int) ( $post['menu_order'] ?? 0 ),
			'post_parent'  => $post_parent,
		];
		if ( ! empty( $post['post_date_gmt'] ) ) {
			$postarr['post_date_gmt'] = (string) $post['post_date_gmt'];
			$postarr['post_date']     = get_date_from_gmt( (string) $post['post_date_gmt'] );
		}

		if ( $existing_id > 0 ) {
			$postarr['ID'] = $existing_id;
			$id = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			$warnings[] = 'Failed to insert post ' . (string) ( $post['ref'] ?? '?' ) . ': '
				. ( is_wp_error( $id ) ? $id->get_error_message() : 'unknown' );
			return 0;
		}
		$id = (int) $id;

		foreach ( (array) ( $post['meta'] ?? [] ) as $m ) {
			$key   = (string) ( $m['key']   ?? '' );
			$value = $m['value'] ?? '';
			if ( '' === $key ) {
				continue;
			}
			$value = $this->rewrite_meta_value( $key, $value, $ref_map, $site_url, $warnings );
			if ( null === $value ) {
				continue;
			}
			update_post_meta( $id, $key, $value );
		}
		update_post_meta( $id, self::META_SOURCE_REF, (string) $post['ref'] );

		foreach ( (array) ( $post['terms'] ?? [] ) as $term_ref_entry ) {
			$ref     = (string) ( $term_ref_entry['ref'] ?? '' );
			$term_id = (int) ( $ref_map[ $ref ] ?? 0 );
			if ( $term_id <= 0 ) {
				continue;
			}
			$term = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				wp_set_object_terms( $id, [ $term_id ], $term->taxonomy, true );
			}
		}

		return $id;
	}

	// ---------------------------------------------------------- Menus

	private function apply_menu( array $menu, array $ref_map, array &$warnings ): int {
		$term_ref = (string) ( $menu['term_ref'] ?? '' );
		$term_id  = (int) ( $ref_map[ $term_ref ] ?? 0 );
		if ( $term_id <= 0 ) {
			$warnings[] = "Menu skipped (term not found): $term_ref";
			return 0;
		}

		// Wipe existing items before re-creating from the manifest —
		// without this, every re-import accumulates duplicates because
		// wp_update_nav_menu_item with id=0 always inserts fresh.
		$existing = wp_get_nav_menu_items( $term_id, [ 'update_post_term_cache' => false ] );
		if ( is_array( $existing ) ) {
			foreach ( $existing as $existing_item ) {
				wp_delete_post( (int) $existing_item->ID, true );
			}
		}

		$applied = 0;
		foreach ( (array) ( $menu['items'] ?? [] ) as $item ) {
			$object_ref = (string) ( $item['object_ref'] ?? '' );
			$object_id  = '' !== $object_ref ? (int) ( $ref_map[ $object_ref ] ?? 0 ) : 0;
			if ( '' !== $object_ref && $object_id <= 0 ) {
				$warnings[] = "Menu item skipped (target missing): $object_ref";
				continue;
			}
			$parent_ref = (string) ( $item['parent_ref'] ?? '' );
			$parent_id  = '' !== $parent_ref ? (int) ( $ref_map[ $parent_ref ] ?? 0 ) : 0;

			wp_update_nav_menu_item( $term_id, 0, [
				'menu-item-title'     => (string) ( $item['title'] ?? '' ),
				'menu-item-type'      => (string) ( $item['type'] ?? 'post_type' ),
				'menu-item-object'    => (string) ( $item['object'] ?? '' ),
				'menu-item-object-id' => $object_id,
				'menu-item-parent-id' => $parent_id,
				'menu-item-position'  => (int) ( $item['menu_order'] ?? 0 ),
				'menu-item-classes'   => implode( ' ', (array) ( $item['classes'] ?? [] ) ),
				'menu-item-target'    => (string) ( $item['target'] ?? '' ),
				'menu-item-status'    => 'publish',
			] );
			$applied++;
		}
		return $applied;
	}

	// ---------------------------------------------------------- Rewrite

	/**
	 * Replace `{{ref:(post|term):N(:missing)?}}`, `{{SITE_URL}}`,
	 * `wp-image-{source_id}` CSS classes, multisite uploads path prefix,
	 * AND raw `"id":N` JSON block attrs that point at attachments.
	 */
	private function rewrite_refs_and_urls( string $value, array $ref_map, string $site_url, array &$warnings ): string {
		if ( '' === $value ) {
			return $value;
		}

		// (a) refs
		$value = (string) preg_replace_callback(
			'/\{\{ref:(post|term):(\d+)(:missing)?\}\}/',
			static function ( array $m ) use ( $ref_map, &$warnings ): string {
				$kind    = $m[1];
				$num     = (int) $m[2];
				$missing = isset( $m[3] ) && '' !== $m[3];
				$ref     = "{$kind}:{$num}";

				if ( $missing ) {
					$warnings[] = "Reference marked :missing in source: $ref";
					return $m[0];
				}
				$id = (int) ( $ref_map[ $ref ] ?? 0 );
				return (string) $id;
			},
			$value
		);

		// (b) site URL placeholder — flip {{SITE_URL}} to the live home URL.
		$value = str_replace( '{{SITE_URL}}', $site_url, $value );

		// (b.1) Multisite uploads-path strip.
		//
		// When the source site was a multisite CHILD, attachment URLs in
		// block markup carry the `wp-content/uploads/sites/N/` prefix
		// (`wp_get_attachment_url` baked it in during export). The
		// uploads.zip we unpacked is rooted at the destination's plain
		// uploads basedir — files land at `wp-content/uploads/2018/03/…`
		// WITHOUT the `sites/N/` segment. Without this strip, every
		// image in the post points at a 404.
		//
		// We only touch URLs whose host already matches our home (anchored
		// via the just-replaced `{{SITE_URL}}`), so external links never
		// get rewritten by accident.
		$home_no_slash = untrailingslashit( $site_url );
		if ( '' !== $home_no_slash ) {
			$value = (string) preg_replace(
				'#(' . preg_quote( $home_no_slash, '#' ) . '/wp-content/uploads/)sites/\d+/#',
				'$1',
				$value
			);
		}

		// Build attachment-only ID pair map for (c) + (d). Plain posts in
		// `ref_map` are excluded — block JSON `"id":N` attrs that aren't
		// attachment refs (e.g. an Image block's `"id":42` IS an
		// attachment; a navigation block's `"id":7` is the menu's post id)
		// shouldn't get rewritten by the broad regex in (d), so we gate
		// strictly on attachment status.
		$attachment_pairs = array();
		foreach ( $ref_map as $ref => $new_id ) {
			if ( 0 !== strpos( $ref, 'post:' ) ) {
				continue;
			}
			$old_id = (int) substr( $ref, 5 );
			$new_id = (int) $new_id;
			if ( $old_id <= 0 || $new_id <= 0 || $old_id === $new_id ) {
				continue;
			}
			$local = get_post( $new_id );
			if ( ! $local || 'attachment' !== $local->post_type ) {
				continue;
			}
			$attachment_pairs[ $old_id ] = $new_id;
		}

		// (c) wp-image-{source_id} → wp-image-{new_id}
		foreach ( $attachment_pairs as $old_id => $new_id ) {
			$value = (string) preg_replace(
				'/\bwp-image-' . $old_id . '\b/',
				'wp-image-' . $new_id,
				$value
			);
		}

		// (d) Block JSON `"id":N` → `"id":<local>` for attachment refs.
		//
		// Gutenberg image / cover / video / gallery (single) / file blocks
		// carry the attachment ID inside the block-comment JSON attribute
		// block — the submitter doesn't rewrite this to a `{{ref:post:N}}`
		// placeholder, so we have to walk raw integers here.
		//
		// Restricting to `$attachment_pairs` (built above) makes this
		// strict: a numeric `"id"` that doesn't belong to an attachment
		// passes through unchanged. Blocks that store `"ids":[…]` for
		// galleries are handled separately just below.
		if ( ! empty( $attachment_pairs ) ) {
			$value = (string) preg_replace_callback(
				'/"id"\s*:\s*(\d+)/',
				static function ( array $m ) use ( $attachment_pairs ): string {
					$src = (int) $m[1];
					if ( isset( $attachment_pairs[ $src ] ) ) {
						return '"id":' . $attachment_pairs[ $src ];
					}
					return $m[0];
				},
				$value
			);

			// `"ids":[1,2,3]` — gallery block list of attachment IDs.
			$value = (string) preg_replace_callback(
				'/"ids"\s*:\s*\[([^\]]*)\]/',
				static function ( array $m ) use ( $attachment_pairs ): string {
					$rewritten = preg_replace_callback(
						'/\d+/',
						static function ( array $n ) use ( $attachment_pairs ): string {
							$src = (int) $n[0];
							return isset( $attachment_pairs[ $src ] )
								? (string) $attachment_pairs[ $src ]
								: $n[0];
						},
						$m[1]
					);
					return '"ids":[' . $rewritten . ']';
				},
				$value
			);
		}

		return $value;
	}

	/**
	 * Rewrite a single meta value. Returns null to signal "drop this entry"
	 * (e.g. `:missing` `_thumbnail_id` shouldn't be persisted).
	 *
	 * @param mixed $value
	 * @return mixed|null
	 */
	private function rewrite_meta_value( string $key, $value, array $ref_map, string $site_url, array &$warnings ) {
		if ( in_array( $key, self::META_POST_ID_KEYS, true ) ) {
			$str = (string) $value;
			if ( preg_match( '/^\{\{ref:post:(\d+)(:missing)?\}\}$/', $str, $m ) ) {
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return null;
				}
				$id = (int) ( $ref_map[ "post:{$m[1]}" ] ?? 0 );
				return $id > 0 ? $id : null;
			}
			return $value;
		}

		if ( is_string( $value ) ) {
			return $this->rewrite_refs_and_urls( $value, $ref_map, $site_url, $warnings );
		}

		if ( is_array( $value ) ) {
			array_walk_recursive( $value, function ( &$v ) use ( $ref_map, $site_url, &$warnings ): void {
				if ( is_string( $v ) ) {
					$v = $this->rewrite_refs_and_urls( $v, $ref_map, $site_url, $warnings );
				}
			} );
		}
		return $value;
	}

	// ---------------------------------------------------------- Idempotency

	/**
	 * @param array<int,string> $refs
	 */
	private function wipe_existing_by_refs( array $refs ): void {
		if ( empty( $refs ) ) {
			return;
		}
		$ids = get_posts( [
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => self::META_SOURCE_REF,
					'value'   => array_values( array_unique( $refs ) ),
					'compare' => 'IN',
				],
			],
		] );
		if ( empty( $ids ) ) {
			return;
		}

		// Suppress on-disk file deletion — Uploads_Extractor just wrote
		// fresh copies and the about-to-be-inserted attachments will
		// point at them. Filter returns false → wp_delete_file no-ops.
		$keep_files = static function () { return false; };
		add_filter( 'wp_delete_file', $keep_files );
		try {
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		} finally {
			remove_filter( 'wp_delete_file', $keep_files );
		}
	}

	private function find_by_source_ref( string $ref ): int {
		$ids = get_posts( [
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => self::META_SOURCE_REF, 'value' => $ref ],
			],
		] );
		return $ids ? (int) $ids[0] : 0;
	}
}
