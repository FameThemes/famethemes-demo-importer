<?php
/**
 * Step 3 — extract uploads.zip into wp-content/uploads/.
 *
 * Ported from `blocksify-design-importer/includes/Theme/UploadsExtractor.php`.
 *
 * Guards:
 *   - Path traversal (`..` or absolute paths) → rejected
 *   - Executable extensions (php/phtml/phar/html/htm/js) → rejected
 *   - Zip-bomb: per-entry > 100 MB OR total uncompressed > 1 GB → abort
 *   - Blocksify per-item folders (`patterns/`, `templates/`) → skipped
 *     so a coexisting blocksify-design-studio install doesn't get its
 *     own per-item attachment folders clobbered.
 */

namespace FT_Demo_Importer\Steps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Uploads_Extractor {

	public const MAX_TOTAL_BYTES = 1024 * 1024 * 1024; // 1 GB
	public const MAX_ENTRY_BYTES = 100 * 1024 * 1024;  // 100 MB

	public const FORBIDDEN_EXT = [ 'php', 'phtml', 'phar', 'html', 'htm', 'js' ];

	/**
	 * @return array{written:int, skipped:int, warnings:string[]}
	 *
	 * @throws \RuntimeException On zip-bomb / corrupt zip / target inaccessible.
	 */
	public function extract( string $zip_path, bool $overwrite_existing = false ): array {
		if ( '' === $zip_path || ! file_exists( $zip_path ) ) {
			throw new \RuntimeException( 'uploads.zip not found: ' . $zip_path );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( 'PHP ZipArchive extension required.' );
		}

		$upload  = wp_get_upload_dir();
		$basedir = (string) ( $upload['basedir'] ?? '' );
		if ( '' === $basedir || ! is_writable( $basedir ) ) {
			throw new \RuntimeException( 'wp-content/uploads is not writable.' );
		}
		$basedir_norm = wp_normalize_path( trailingslashit( $basedir ) );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new \RuntimeException( 'Could not open uploads.zip.' );
		}

		$total    = 0;
		$written  = 0;
		$skipped  = 0;
		$warnings = [];

		try {
			$num = $zip->numFiles;
			for ( $i = 0; $i < $num; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) ) {
					continue;
				}
				$name = (string) $stat['name'];
				$size = (int) $stat['size'];

				if ( '/' === substr( $name, -1 ) ) {
					continue; // directory entry — created lazily below
				}

				if ( $size > self::MAX_ENTRY_BYTES ) {
					$warnings[] = "Skipped (entry too large): $name";
					$skipped++;
					continue;
				}
				$total += $size;
				if ( $total > self::MAX_TOTAL_BYTES ) {
					throw new \RuntimeException( 'uploads.zip exceeds 1 GB uncompressed cap.' );
				}

				$relative = $this->safe_relative_path( $name );
				if ( null === $relative ) {
					$warnings[] = "Rejected (path traversal): $name";
					$skipped++;
					continue;
				}
				if ( $this->is_blocksify_path( $relative ) ) {
					$warnings[] = "Skipped (Blocksify-owned path): $relative";
					$skipped++;
					continue;
				}
				$ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, self::FORBIDDEN_EXT, true ) ) {
					$warnings[] = "Rejected (forbidden extension): $relative";
					$skipped++;
					continue;
				}

				$target = $basedir_norm . $relative;
				if ( file_exists( $target ) && ! $overwrite_existing ) {
					$skipped++;
					continue;
				}

				$dir = dirname( $target );
				if ( ! wp_mkdir_p( $dir ) ) {
					$warnings[] = "Could not create dir: $dir";
					$skipped++;
					continue;
				}

				$stream = $zip->getStream( $name );
				if ( ! is_resource( $stream ) ) {
					$warnings[] = "Could not read entry: $name";
					$skipped++;
					continue;
				}
				$out = @fopen( $target, 'wb' );
				if ( ! is_resource( $out ) ) {
					fclose( $stream );
					$warnings[] = "Could not write target: $target";
					$skipped++;
					continue;
				}
				stream_copy_to_stream( $stream, $out );
				fclose( $stream );
				fclose( $out );
				$written++;
			}
		} finally {
			$zip->close();
		}

		return [ 'written' => $written, 'skipped' => $skipped, 'warnings' => $warnings ];
	}

	private function safe_relative_path( string $name ): ?string {
		$name = str_replace( '\\', '/', $name );
		$name = ltrim( $name, '/' );
		if ( '' === $name ) {
			return null;
		}
		if ( false !== strpos( $name, '..' ) ) {
			return null;
		}
		if ( preg_match( '#^[A-Za-z]:/#', $name ) ) {
			return null;
		}
		return $name;
	}

	private function is_blocksify_path( string $relative ): bool {
		return 0 === strpos( $relative, 'patterns/' )
			|| 0 === strpos( $relative, 'templates/' );
	}
}
