<?php
/**
 * Archive creation and validation.
 *
 * @package SafeSpot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, lists and validates zip archives using ZipArchive with a PclZip fallback.
 */
class SafeSpot_Zip {

	/**
	 * Maximum number of entries accepted in a SafeSpot archive.
	 */
	const MAX_ENTRIES = 200000;

	/**
	 * Engine in use.
	 *
	 * @return string ziparchive|pclzip
	 */
	public static function engine() {
		/**
		 * Filters whether ZipArchive may be used (return false to force PclZip).
		 *
		 * @param bool $use Whether to use ZipArchive.
		 */
		if ( class_exists( 'ZipArchive', false ) && apply_filters( 'safespot_use_ziparchive', true ) ) {
			return 'ziparchive';
		}
		return 'pclzip';
	}

	/**
	 * Archives a plugin/theme folder (or single file) so its top-level entry is the folder name.
	 *
	 * @param string $source  Absolute folder or file path.
	 * @param string $dest    Destination zip path.
	 * @param array  $exclude Relative paths (from the folder root) to skip.
	 * @return array|WP_Error { file_count, uncompressed_size, skipped }.
	 */
	public static function create( $source, $dest, array $exclude = array() ) {
		$source = untrailingslashit( wp_normalize_path( $source ) );

		if ( ! file_exists( $source ) ) {
			return new WP_Error( 'safespot_source_missing', __( 'The plugin or theme files could not be found.', 'tbsh-safespot-restore-points' ) );
		}

		$files = self::collect( $source, $exclude );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$result = 'ziparchive' === self::engine()
			? self::create_ziparchive( $source, $dest, $files )
			: self::create_pclzip( $source, $dest, $files );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $dest );
			return $result;
		}

		return array(
			'file_count'        => $files['count'],
			'uncompressed_size' => $files['size'],
			'skipped'           => $files['skipped'],
		);
	}

	/**
	 * Builds the list of entries to archive.
	 *
	 * @param string $source  Normalized source path.
	 * @param array  $exclude Excluded relative paths.
	 * @return array|WP_Error
	 */
	private static function collect( $source, array $exclude ) {
		$base    = basename( $source );
		$entries = array();
		$count   = 0;
		$size    = 0;
		$skipped = 0;

		if ( is_file( $source ) ) {
			$entries[] = array(
				'path'  => $source,
				'local' => $base,
				'dir'   => false,
			);
			return array(
				'entries' => $entries,
				'count'   => 1,
				'size'    => (int) filesize( $source ),
				'skipped' => 0,
			);
		}

		$entries[] = array(
			'path'  => $source,
			'local' => $base . '/',
			'dir'   => true,
		);

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				/**
				 * Directory entry.
				 *
				 * @var SplFileInfo $file
				 */
				if ( $file->isLink() ) {
					++$skipped;
					continue;
				}

				$path     = wp_normalize_path( $file->getPathname() );
				$relative = ltrim( substr( $path, strlen( $source ) ), '/' );

				if ( self::is_excluded( $relative, $exclude ) ) {
					continue;
				}

				if ( $file->isDir() ) {
					$entries[] = array(
						'path'  => $path,
						'local' => $base . '/' . $relative . '/',
						'dir'   => true,
					);
					continue;
				}

				if ( ! $file->isReadable() ) {
					/* translators: %s: file path. */
					return new WP_Error( 'safespot_unreadable', sprintf( __( 'This file is not readable: %s', 'tbsh-safespot-restore-points' ), $relative ) );
				}

				$entries[] = array(
					'path'  => $path,
					'local' => $base . '/' . $relative,
					'dir'   => false,
				);
				++$count;
				$size += (int) $file->getSize();
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'safespot_scan_failed', $e->getMessage() );
		}

		return array(
			'entries' => $entries,
			'count'   => $count,
			'size'    => $size,
			'skipped' => $skipped,
		);
	}

	/**
	 * Whether a relative path matches an exclusion.
	 *
	 * @param string $relative Relative path.
	 * @param array  $exclude  Exclusions.
	 * @return bool
	 */
	private static function is_excluded( $relative, array $exclude ) {
		foreach ( $exclude as $pattern ) {
			$pattern = trim( wp_normalize_path( (string) $pattern ), '/' );
			if ( '' === $pattern ) {
				continue;
			}
			if ( $relative === $pattern || 0 === strpos( $relative, $pattern . '/' ) || fnmatch( $pattern, $relative ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * ZipArchive implementation.
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination.
	 * @param array  $files  Collected entries.
	 * @return true|WP_Error
	 */
	private static function create_ziparchive( $source, $dest, array $files ) {
		unset( $source );
		$zip    = new ZipArchive();
		$opened = $zip->open( $dest, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			/* translators: %s: error code. */
			return new WP_Error( 'safespot_zip_open', sprintf( __( 'Could not create the archive (ZipArchive error %s).', 'tbsh-safespot-restore-points' ), (string) $opened ) );
		}

		$added = 0;
		foreach ( $files['entries'] as $entry ) {
			if ( $entry['dir'] ) {
				$zip->addEmptyDir( rtrim( $entry['local'], '/' ) );
				continue;
			}
			if ( ! $zip->addFile( $entry['path'], $entry['local'] ) ) {
				$zip->close();
				/* translators: %s: file path. */
				return new WP_Error( 'safespot_zip_add', sprintf( __( 'Could not add a file to the archive: %s', 'tbsh-safespot-restore-points' ), $entry['local'] ) );
			}
			++$added;

			// Flush periodically so very large folders do not exhaust open file handles.
			if ( 0 === $added % 500 ) {
				if ( ! $zip->close() ) {
					return new WP_Error( 'safespot_zip_write', __( 'Could not write the archive. The disk may be full.', 'tbsh-safespot-restore-points' ) );
				}
				$zip = new ZipArchive();
				if ( true !== $zip->open( $dest ) ) {
					return new WP_Error( 'safespot_zip_reopen', __( 'Could not reopen the archive while writing.', 'tbsh-safespot-restore-points' ) );
				}
			}
		}

		if ( ! $zip->close() ) {
			return new WP_Error( 'safespot_zip_write', __( 'Could not write the archive. The disk may be full.', 'tbsh-safespot-restore-points' ) );
		}
		return true;
	}

	/**
	 * PclZip implementation.
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination.
	 * @param array  $files  Collected entries.
	 * @return true|WP_Error
	 */
	private static function create_pclzip( $source, $dest, array $files ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		// Pass files only: PclZip recurses into any directory it is given, which would
		// re-add excluded files and symlinks.
		$paths = array();
		foreach ( $files['entries'] as $entry ) {
			if ( ! $entry['dir'] ) {
				$paths[] = $entry['path'];
			}
		}
		if ( empty( $paths ) ) {
			return new WP_Error( 'safespot_empty_source', __( 'There are no files to archive.', 'tbsh-safespot-restore-points' ) );
		}

		$archive = new PclZip( $dest );
		$result  = $archive->create( $paths, PCLZIP_OPT_REMOVE_PATH, wp_normalize_path( dirname( $source ) ) );

		if ( 0 === $result ) {
			/* translators: %s: PclZip error. */
			return new WP_Error( 'safespot_pclzip', sprintf( __( 'Could not create the archive: %s', 'tbsh-safespot-restore-points' ), $archive->errorInfo( true ) ) );
		}
		return true;
	}

	/**
	 * Lists archive entries.
	 *
	 * @param string $file Zip path.
	 * @return array|WP_Error List of { name, size, crc, is_dir, is_symlink }.
	 */
	public static function entries( $file ) {
		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'safespot_zip_missing', __( 'The SafeSpot archive is missing or unreadable.', 'tbsh-safespot-restore-points' ) );
		}

		$entries = array();

		if ( 'ziparchive' === self::engine() ) {
			$zip    = new ZipArchive();
			$opened = $zip->open( $file, defined( 'ZipArchive::RDONLY' ) ? ZipArchive::RDONLY : 0 );
			if ( true !== $opened ) {
				return new WP_Error( 'safespot_zip_corrupt', __( 'The SafeSpot archive is corrupt and cannot be opened.', 'tbsh-safespot-restore-points' ) );
			}
			$count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
			if ( $count > self::MAX_ENTRIES ) {
				$zip->close();
				return new WP_Error( 'safespot_zip_too_large', __( 'The archive contains too many files.', 'tbsh-safespot-restore-points' ) );
			}
			for ( $i = 0; $i < $count; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( false === $stat ) {
					$zip->close();
					return new WP_Error( 'safespot_zip_corrupt', __( 'The SafeSpot archive is corrupt and cannot be read.', 'tbsh-safespot-restore-points' ) );
				}
				$symlink = false;
				$opsys   = 0;
				$attr    = 0;
				if ( method_exists( $zip, 'getExternalAttributesIndex' ) && $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && defined( 'ZipArchive::OPSYS_UNIX' ) && ZipArchive::OPSYS_UNIX === $opsys ) {
					$symlink = 0xA000 === ( ( $attr >> 16 ) & 0xF000 );
				}
				$entries[] = array(
					'name'       => (string) $stat['name'],
					'size'       => (int) $stat['size'],
					'crc'        => sprintf( '%08x', $stat['crc'] & 0xFFFFFFFF ),
					'is_dir'     => '/' === substr( $stat['name'], -1 ),
					'is_symlink' => $symlink,
				);
			}
			$zip->close();
			return $entries;
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$archive = new PclZip( $file );
		$list    = $archive->listContent();
		if ( ! is_array( $list ) ) {
			return new WP_Error( 'safespot_zip_corrupt', __( 'The SafeSpot archive is corrupt and cannot be opened.', 'tbsh-safespot-restore-points' ) );
		}
		if ( count( $list ) > self::MAX_ENTRIES ) {
			return new WP_Error( 'safespot_zip_too_large', __( 'The archive contains too many files.', 'tbsh-safespot-restore-points' ) );
		}
		foreach ( $list as $info ) {
			$name      = (string) $info['stored_filename'];
			$is_dir    = ! empty( $info['folder'] );
			$entries[] = array(
				'name'       => $is_dir ? rtrim( $name, '/' ) . '/' : $name,
				'size'       => (int) $info['size'],
				'crc'        => sprintf( '%08x', (int) $info['crc'] & 0xFFFFFFFF ),
				'is_dir'     => $is_dir,
				'is_symlink' => false,
			);
		}
		return $entries;
	}

	/**
	 * Validates that an archive only contains files inside the expected root.
	 *
	 * @param string $file           Zip path.
	 * @param string $root           Expected top-level folder (or single file name).
	 * @param bool   $single_file    Whether the archive holds a single-file plugin.
	 * @param string $required_entry Entry that must exist (plugin main file or style.css).
	 * @return array|WP_Error Entries on success.
	 */
	public static function validate( $file, $root, $single_file, $required_entry = '' ) {
		$entries = self::entries( $file );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		if ( empty( $entries ) ) {
			return new WP_Error( 'safespot_zip_empty', __( 'The SafeSpot archive is empty.', 'tbsh-safespot-restore-points' ) );
		}

		$found = '' === $required_entry;

		foreach ( $entries as $entry ) {
			$name = $entry['name'];

			if ( $entry['is_symlink'] ) {
				/* translators: %s: archive entry. */
				return new WP_Error( 'safespot_zip_symlink', sprintf( __( 'The archive contains a symbolic link, which is not allowed: %s', 'tbsh-safespot-restore-points' ), $name ) );
			}

			if ( ! self::is_safe_path( $name ) ) {
				/* translators: %s: archive entry. */
				return new WP_Error( 'safespot_zip_traversal', sprintf( __( 'The archive contains an unsafe path: %s', 'tbsh-safespot-restore-points' ), $name ) );
			}

			$inside = $single_file ? ( $name === $root ) : ( $name === $root . '/' || 0 === strpos( $name, $root . '/' ) );
			if ( ! $inside ) {
				/* translators: 1: archive entry, 2: expected folder. */
				return new WP_Error( 'safespot_zip_outside', sprintf( __( 'The archive contains "%1$s", which is outside the expected folder "%2$s".', 'tbsh-safespot-restore-points' ), $name, $root ) );
			}

			if ( $name === $required_entry ) {
				$found = true;
			}
		}

		if ( ! $found ) {
			/* translators: %s: file name. */
			return new WP_Error( 'safespot_zip_incomplete', sprintf( __( 'The archive does not contain the expected main file (%s).', 'tbsh-safespot-restore-points' ), $required_entry ) );
		}

		return $entries;
	}

	/**
	 * Rejects absolute paths, drive letters, backslashes, null bytes and ".." segments.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	public static function is_safe_path( $name ) {
		if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) ) {
			return false;
		}
		if ( '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment || '.' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Compares archive entries with the files currently on disk.
	 *
	 * @param array  $entries Validated entries.
	 * @param string $root    Archive root folder (or single file name).
	 * @param string $current Absolute path of the current folder (or file); empty if not installed.
	 * @param int    $limit   Maximum paths listed per group.
	 * @return array { added, removed, modified, unchanged, counts, truncated }
	 */
	public static function diff( array $entries, $root, $current, $limit = 100 ) {
		$result = array(
			'restored'  => array(),
			'removed'   => array(),
			'modified'  => array(),
			'counts'    => array(
				'restored'  => 0,
				'removed'   => 0,
				'modified'  => 0,
				'unchanged' => 0,
			),
			'truncated' => false,
		);

		$current = untrailingslashit( wp_normalize_path( (string) $current ) );
		$is_file = '' !== $current && is_file( $current );
		$in_zip  = array();

		foreach ( $entries as $entry ) {
			if ( $entry['is_dir'] ) {
				continue;
			}
			$relative            = $is_file || $entry['name'] === $root ? basename( $entry['name'] ) : substr( $entry['name'], strlen( $root ) + 1 );
			$in_zip[ $relative ] = true;
			$disk                = $is_file ? $current : $current . '/' . $relative;

			if ( '' === $current || ! is_file( $disk ) ) {
				self::diff_add( $result, 'restored', $relative, $limit );
				continue;
			}

			if ( filesize( $disk ) !== $entry['size'] || hash_file( 'crc32b', $disk ) !== $entry['crc'] ) {
				self::diff_add( $result, 'modified', $relative, $limit );
			} else {
				++$result['counts']['unchanged'];
			}
		}

		if ( '' !== $current && is_dir( $current ) ) {
			try {
				$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $current, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $iterator as $file ) {
					if ( $file->isDir() ) {
						continue;
					}
					$relative = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $current ) ), '/' );
					if ( ! isset( $in_zip[ $relative ] ) ) {
						self::diff_add( $result, 'removed', $relative, $limit );
					}
				}
			} catch ( Exception $e ) {
				$result['truncated'] = true;
			}
		}

		return $result;
	}

	/**
	 * Adds a path to a diff group.
	 *
	 * @param array  $result Diff result (by reference).
	 * @param string $group  Group key.
	 * @param string $path   Relative path.
	 * @param int    $limit  Max listed.
	 */
	private static function diff_add( array &$result, $group, $path, $limit ) {
		++$result['counts'][ $group ];
		if ( count( $result[ $group ] ) < $limit ) {
			$result[ $group ][] = $path;
		} else {
			$result['truncated'] = true;
		}
	}
}
