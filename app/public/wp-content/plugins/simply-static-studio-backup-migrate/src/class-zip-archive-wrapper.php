<?php

/**
 * Local backup I/O requires bounded streams, exclusive locks, atomic same-volume
 * renames, verified deletion results, and owner-only permissions. WP_Filesystem
 * remote transports cannot preserve these guarantees for sensitive backup files.
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
 * phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 *
 * Exceptions carry plain-text diagnostics to CLI, logs, or JSON; HTML consumers
 * escape at their rendering boundary, never while constructing these messages.
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 */

namespace Simply_Static\Backup;

use Simply_Static\Backup\Logs\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

/**
 * Map a PclZip source file to its exact archive path.
 *
 * WordPress' bundled PclZip validates callbacks with function_exists(), so it
 * cannot accept a Closure here. Keep this as a namespaced function and route
 * the actual mapping through the active wrapper batch.
 *
 * @param int   $event  PclZip callback event.
 * @param array $header PclZip entry header.
 * @return int One to add the entry, zero to skip it.
 */
function pclzip_map_archive_path( $event, &$header ) {
	return Zip_Archive_Wrapper::map_pclzip_archive_path( $event, $header );
}

/**
 * Wrapper class for ZIP archive operations.
 * Uses ZipArchive when available, falls back to PclZip (WordPress built-in).
 */
class Zip_Archive_Wrapper {

	/**
	 * Largest archive and entry count representable by the classic ZIP format.
	 *
	 * PclZip does not write ZIP64 metadata, so crossing either boundary can
	 * produce an archive without a readable central directory.
	 */
	const PCLZIP_MAX_ARCHIVE_BYTES   = 4294967295.0;
	const PCLZIP_MAX_ARCHIVE_ENTRIES = 65535;

	/**
	 * Reserve space for deflate expansion and per-entry ZIP metadata when
	 * estimating a PclZip append before compression has happened.
	 */
	const PCLZIP_EXPANSION_RESERVE_RATIO = 0.01;
	const PCLZIP_ENTRY_RESERVE_BYTES     = 1024;

	/**
	 * File types whose payload is already compressed.
	 *
	 * Deflating these formats consumes a full CPU core on large media libraries
	 * while commonly making the ZIP slightly larger. Native ZipArchive can store
	 * them verbatim without changing the portable archive contract.
	 *
	 * @var string[]
	 */
	const PRECOMPRESSED_EXTENSIONS = array(
		'7z',
		'aac',
		'avi',
		'avif',
		'bz2',
		'docx',
		'flac',
		'gif',
		'gz',
		'heic',
		'heif',
		'jpeg',
		'jpg',
		'm4a',
		'm4v',
		'mkv',
		'mov',
		'mp3',
		'mp4',
		'mpeg',
		'mpg',
		'oga',
		'ogg',
		'pdf',
		'png',
		'pptx',
		'rar',
		'tgz',
		'wav',
		'webm',
		'webp',
		'woff',
		'woff2',
		'xlsx',
		'zip',
	);

	/**
	 * Exact source-to-archive paths for the active PclZip add() call.
	 *
	 * PclZip's callback API has no user-data parameter, so the synchronous
	 * add() call exposes its batch mapping here and clears it immediately after
	 * the call completes.
	 *
	 * @var array<string,string>
	 */
	private static $pclzip_callback_archive_paths = [];

	/**
	 * Slash-normalized source-to-archive paths for the active PclZip add().
	 *
	 * @var array<string,string>
	 */
	private static $pclzip_callback_normalized_paths = [];

	/**
	 * The underlying archive handler (ZipArchive or PclZip).
	 *
	 * @var \ZipArchive|\PclZip|null
	 */
	private $archive = null;

	/**
	 * Whether we're using ZipArchive (true) or PclZip (false).
	 *
	 * @var bool
	 */
	private $use_zip_archive = false;

	/**
	 * The path to the archive file.
	 *
	 * @var string
	 */
	private $archive_path = '';

	/**
	 * Whether the archive is currently open.
	 *
	 * @var bool
	 */
	private $is_open = false;

	/**
	 * Number of files added since the last flush (ZipArchive only).
	 *
	 * @var int
	 */
	private $unflushed_count = 0;

	/**
	 * Number of successful write commits in the current open session.
	 *
	 * Export tasks use this to checkpoint the matching CSV offset immediately
	 * after a PclZip batch reaches disk.
	 *
	 * @var int
	 */
	private $flush_count = 0;

	/**
	 * Whether a write/flush failed during this open session.
	 *
	 * A failed periodic flush can discard several deferred entries. Keeping a
	 * sticky failure flag prevents a later successful no-op flush from causing
	 * callers to persist progress for files that never reached the archive.
	 *
	 * @var bool
	 */
	private $write_failed = false;

	/**
	 * Most recent native archive error captured before the ZipArchive instance
	 * is replaced during recovery.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Archive file size threshold (in bytes) above which repeated CHECKCONS
	 * scans are skipped while a generation is still being assembled.
	 *
	 * Default: 500 MB.
	 *
	 * @var int
	 */
	private $large_archive_threshold = 524288000;

	/**
	 * How many native ZipArchive entries to queue before forcing a flush.
	 * Outer backup tasks persist progress in batches capped at this same size,
	 * so more frequent closes add quadratic central-directory I/O without
	 * improving recoverability.
	 *
	 * @var int
	 */
	private $flush_interval = 1000;

	/**
	 * PclZip buffer limit. Its in-memory descriptors stay deliberately smaller
	 * than the native buffer, while still avoiding one whole-archive rewrite per
	 * file.
	 *
	 * @var int
	 */
	private $pclzip_flush_interval = 50;

	/**
	 * Instance limits are properties so boundary behavior can be exercised
	 * without creating multi-gigabyte fixtures.
	 *
	 * @var float
	 */
	private $pclzip_max_archive_size = self::PCLZIP_MAX_ARCHIVE_BYTES;

	/**
	 * @var int
	 */
	private $pclzip_max_entries = self::PCLZIP_MAX_ARCHIVE_ENTRIES;

	/**
	 * Buffer of files waiting to be added to PclZip in a single batch.
	 *
	 * PclZip re-reads and re-writes the entire archive on every add() call,
	 * making per-file additions O(n²) on archive size. Buffering and exact-path
	 * callback mapping let us call add() only once per flush.
	 *
	 * Each entry: [ 'file_path' => string, 'archive_path' => string ]
	 *
	 * @var array
	 */
	private $pclzip_buffer = [];

	/**
	 * Private local copies of stream-wrapper sources awaiting a ZIP flush.
	 *
	 * ZipArchive and PclZip both require ordinary filesystem paths. Remote
	 * uploads are therefore copied one at a time into the protected backup
	 * directory and removed as soon as the archive no longer needs them.
	 *
	 * @var array<string,bool>
	 */
	private $staged_stream_sources = [];

	/**
	 * Randomized private directory holding stream-wrapper source copies.
	 *
	 * @var string
	 */
	private $stream_stage_directory = '';

	/**
	 * Normalized entry names already committed by PclZip.
	 *
	 * This journal lets a retry skip entries that PclZip confirmed before a
	 * partial batch failure, preventing duplicate archive entries.
	 *
	 * @var array<string,bool>
	 */
	private $pclzip_entries = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->use_zip_archive = class_exists( '\ZipArchive' );
	}

	/**
	 * Check if ZipArchive is available.
	 *
	 * @return bool
	 */
	public static function is_zip_archive_available() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Determine whether an estimated append would exceed PclZip's classic ZIP
	 * size or entry-count limits.
	 *
	 * The source byte estimate deliberately reserves one percent for deflate
	 * expansion plus one KiB per entry for local headers, data descriptors, and
	 * central-directory metadata. False positives are preferable to spending
	 * hours producing a backup that cannot be restored.
	 *
	 * @param int|float $current_archive_bytes Current archive size.
	 * @param int|float $additional_uncompressed_bytes Source bytes still to add.
	 * @param int       $current_entries Existing archive entry count.
	 * @param int       $additional_entries Entries still to add.
	 * @return bool
	 */
	public static function would_exceed_pclzip_limits(
		$current_archive_bytes,
		$additional_uncompressed_bytes,
		$current_entries = 0,
		$additional_entries = 0
	) {
		return self::would_exceed_classic_zip_limits(
			$current_archive_bytes,
			$additional_uncompressed_bytes,
			$current_entries,
			$additional_entries,
			self::PCLZIP_MAX_ARCHIVE_BYTES,
			self::PCLZIP_MAX_ARCHIVE_ENTRIES
		);
	}

	/**
	 * Actionable message shared by the preflight and runtime PclZip guards.
	 *
	 * @return string
	 */
	public static function get_pclzip_limit_error() {
		return __(
			'This backup is too large for the PclZip fallback (maximum 4 GB and 65,535 files). Enable the PHP ZipArchive extension and start a new backup.',
			'simply-static-studio-backup-migrate'
		);
	}

	/**
	 * Get the library being used.
	 *
	 * @return string 'ZipArchive' or 'PclZip'
	 */
	public function get_library_name() {
		return $this->use_zip_archive ? 'ZipArchive' : 'PclZip';
	}

	/**
	 * Create a new empty archive file.
	 *
	 * @param string $path Path to the archive file.
	 * @return bool True on success, false on failure.
	 */
	public function create( $path ) {
		$this->archive_path = $path;
		$this->write_failed = false;
		$this->is_open      = false;
		$this->last_error    = '';

		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			Logger::log( 'Archive create failed: invalid archive path' );
			return false;
		}

		// Write a minimal valid empty ZIP directly. ZipArchive removes a newly
		// created archive when it is closed without entries, while PclZip's
		// create/delete approach can leave an invalid central directory.
		// A valid empty ZIP is just the 22-byte End of Central Directory record.
		$empty_zip = pack( 'VvvvvVVv',
			0x06054b50, // End of central directory signature
			0,          // Number of this disk
			0,          // Disk where central directory starts
			0,          // Number of central directory records on this disk
			0,          // Total number of central directory records
			0,          // Size of central directory (bytes)
			0,          // Offset of start of central directory
			0           // Comment length
		);

		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			Logger::log( 'Archive create failed: archive leaf already exists: ' . $path );
			return false;
		}

		// Some PHP stream implementations follow a dangling final symlink even for
		// `xb`. Write to an unpredictable, exclusively-created sibling first. A hard
		// link provides no-replace publication where supported; the same-directory
		// rename fallback still replaces the leaf itself rather than following it.
		$handle    = false;
		$temp_path = '';
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			try {
				$suffix = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable $exception ) {
				$suffix = md5( wp_generate_password( 64, true, true ) . microtime( true ) . $attempt );
			}

			$candidate = dirname( $path ) . DIRECTORY_SEPARATOR . '.sssbm-archive-' . $suffix . '.tmp';
			clearstatcache( true, $candidate );
			if ( file_exists( $candidate ) || is_link( $candidate ) ) {
				continue;
			}

			$previous_umask = umask( 0177 );
			try {
				$handle = @fopen( $candidate, 'xb' );
			} finally {
				umask( $previous_umask );
			}
			if ( is_resource( $handle ) ) {
				$temp_path = $candidate;
				break;
			}
		}

		if ( ! is_resource( $handle ) ) {
			Logger::log( 'Archive create failed: could not claim a private staging leaf for ' . $path );
			return false;
		}

		$created_stat = @fstat( $handle );
		$write_ok     = true;
		$offset       = 0;
		$length       = strlen( $empty_zip );
		while ( $offset < $length ) {
			$written = @fwrite( $handle, substr( $empty_zip, $offset ) );
			if ( false === $written || 0 === $written ) {
				$write_ok = false;
				break;
			}
			$offset += $written;
		}

		if ( $write_ok ) {
			$write_ok = @fflush( $handle );
		}

		$leaf_matches = is_array( $created_stat ) && $this->archive_leaf_matches_stat( $temp_path, $created_stat );
		@fclose( $handle );

		if ( ! $write_ok || ! $leaf_matches ) {
			if ( is_array( $created_stat ) && $this->archive_leaf_matches_stat( $temp_path, $created_stat ) ) {
				@unlink( $temp_path );
			}
			Logger::log( 'Archive create failed: could not write the exclusively claimed ZIP staging file for ' . $path );
			return false;
		}

		clearstatcache( true, $path );
		$destination_absent = ! file_exists( $path ) && ! is_link( $path );
		$published = $destination_absent && function_exists( 'link' ) && @link( $temp_path, $path );
		if ( ! $published ) {
			// link() can be disabled or unsupported on Windows, FAT, and some network
			// filesystems. Recheck immediately before renaming the same-filesystem
			// staging inode. If a link wins the race, rename replaces that directory
			// entry and never writes through its target; hosts that prohibit replacing
			// an occupied destination simply fail closed.
			clearstatcache( true, $path );
			$published = ! file_exists( $path ) && ! is_link( $path ) && @rename( $temp_path, $path );
		}
		if ( ! $published || ! $this->archive_leaf_matches_stat( $path, $created_stat ) ) {
			if ( $this->archive_leaf_matches_stat( $temp_path, $created_stat ) ) {
				@unlink( $temp_path );
			}
			if ( $this->archive_leaf_matches_stat( $path, $created_stat ) ) {
				@unlink( $path );
			}
			Logger::log( 'Archive create failed: archive leaf could not be published exclusively: ' . $path );
			return false;
		}

		if ( $this->archive_leaf_matches_stat( $temp_path, $created_stat ) ) {
			@unlink( $temp_path );
		}

		return true;
	}

	/**
	 * Open an existing archive file for appending.
	 *
	 * @param string $path Path to the archive file.
	 * @param bool   $create_if_not_exists Whether to create the archive if it doesn't exist.
	 * @return bool True on success, false on failure.
	 */
	public function open( $path, $create_if_not_exists = true ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			Logger::log( 'Archive open failed: invalid archive path' );
			return false;
		}

		$this->archive_path    = $path;
		$this->write_failed    = false;
		$this->is_open         = false;
		$this->unflushed_count = 0;
		$this->flush_count     = 0;
		$this->pclzip_entries  = [];
		$this->last_error      = '';

		clearstatcache( true, $path );
		if ( is_link( $path ) ) {
			Logger::log( 'Archive open failed: refusing to follow archive symlink: ' . $path );
			return false;
		}

		if ( ! file_exists( $path ) ) {
			if ( ! $create_if_not_exists ) {
				Logger::log( 'Archive open failed: file does not exist and create_if_not_exists is false' );
				return false;
			}

			// Claim and initialize the leaf ourselves instead of allowing ZipArchive
			// or PclZip to create by pathname and potentially follow a raced link.
			if ( ! $this->create( $path ) ) {
				return false;
			}
		}

		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) ) {
			Logger::log( 'Archive open failed: archive leaf is not a regular file: ' . $path );
			return false;
		}

		if ( $this->use_zip_archive ) {
			$this->archive = new \ZipArchive();

			if ( file_exists( $path ) ) {
				// Skip CHECKCONS for large archives to avoid scanning the
				// entire file on every open.  CHECKCONS verifies the central
				// directory against local headers which is O(n) on archive
				// size — for a multi-GB archive this alone can exceed the
				// PHP time limit and stall the migration.
				if ( ! $this->archive_is_large() ) {
					$result = $this->archive->open( $path, \ZipArchive::CHECKCONS );
					if ( $result !== true ) {
						// Do not retry a consistency failure without CHECKCONS. A ZIP can
						// have a readable central directory while its local headers are
						// corrupt; appending to it would turn a detectable bad backup into
						// an apparently successful one.
						Logger::log( 'ZipArchive CHECKCONS failed (error code: ' . $result . '); refusing to append to a corrupt archive: ' . $path );
						return false;
					}
				} else {
					// Open directly without CHECKCONS for large files.
					$result = $this->archive->open( $path );
				}

				if ( $result === true ) {
					$this->is_open = true;
					return true;
				}

				Logger::log( 'ZipArchive open failed for existing archive (error code: ' . $result . '): ' . $path );
				return false;
			}

			Logger::log( 'ZipArchive open failed: exclusively created archive leaf disappeared before open' );
			return false;
		}

		// PclZip fallback
		$this->load_pclzip();

		// Validate that the archive is a readable ZIP before marking as open.
		// A corrupt file (e.g. from a previous failed write) would cause every
		// subsequent add() to fail with "End of Central Dir Record" errors.
		$temporary_root = $this->get_private_pclzip_root();
		if ( false === $temporary_root ) {
			$this->last_error = __( 'Unable to create private PclZip scratch files.', 'simply-static-studio-backup-migrate' );
			return false;
		}
		$this->archive = new Pclzip_Archive( $path, $temporary_root );

		$properties = $this->archive->properties();
		$entries    = $this->archive->listContent();
		if ( $properties === 0 || ! is_array( $entries ) ) {
			// Never replace an existing corrupt archive with an empty one. Resumable
			// task offsets would otherwise skip files that vanished with the archive
			// and could incorrectly mark the truncated backup as complete.
			$error = $this->capture_pclzip_error( __( 'PclZip archive is corrupt or unreadable.', 'simply-static-studio-backup-migrate' ) );
			Logger::log( 'PclZip open: archive is corrupt or unreadable: ' . $path . ' — ' . $error );
			$this->archive = null;
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( isset( $entry['filename'] ) ) {
				$entry_name = $this->normalize_archive_path( $entry['filename'] );
				if ( false !== $entry_name ) {
					$this->pclzip_entries[ $entry_name ] = true;
				}
			}
		}

		$this->is_open = true;
		return true;
	}

	/**
	 * Verify that a pathname still names the exact regular file claimed by fopen.
	 *
	 * @param string $path          Claimed archive pathname.
	 * @param array  $expected_stat fstat() result for the exclusive handle.
	 * @return bool
	 */
	private function archive_leaf_matches_stat( $path, $expected_stat ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) ) {
			return false;
		}

		$current_stat = @lstat( $path );
		if ( ! is_array( $current_stat ) || 0100000 !== ( $current_stat['mode'] & 0170000 ) ) {
			return false;
		}

		// Device/inode comparison detects an unlink-and-replace race while the
		// exclusive handle is still open. Some Windows filesystems report zero for
		// both fields; regular-file and symlink checks remain the fallback there.
		if (
			isset( $expected_stat['dev'], $expected_stat['ino'], $current_stat['dev'], $current_stat['ino'] ) &&
			( 0 !== (int) $expected_stat['dev'] || 0 !== (int) $expected_stat['ino'] )
		) {
			return (int) $expected_stat['dev'] === (int) $current_stat['dev']
				&& (int) $expected_stat['ino'] === (int) $current_stat['ino'];
		}

		return true;
	}

	/**
	 * Add a file to the archive.
	 *
	 * Uses ZipArchive::addFile() which streams from disk (deferred write)
	 * instead of reading the entire file into PHP memory. This prevents
	 * out-of-memory crashes on large media files (videos, PDFs, etc.).
	 *
	 * The archive is flushed (closed and reopened) at the outer task's maximum
	 * batch size. The task only persists its CSV offset after close succeeds, so
	 * more frequent native flushes do not add recoverability.
	 *
	 * @param string $file_path    Absolute path to the file to add.
	 * @param string $archive_path Path within the archive (relative path).
	 * @return bool True on success, false on failure.
	 */
	public function addFile( $file_path, $archive_path ) {
		if ( ! $this->is_open || $this->write_failed ) {
			Logger::log( 'Cannot add file: archive is not open' );
			return false;
		}

		if ( ! $this->source_is_readable( $file_path ) ) {
			Logger::log( 'Cannot add file: file does not exist or is not readable: ' . $file_path );
			return false;
		}

		$archive_path = $this->normalize_archive_path( $archive_path );
		if ( false === $archive_path ) {
			Logger::log( 'Cannot add file: unsafe archive path' );
			return false;
		}

		if ( $this->use_zip_archive ) {
			// Use native addFile() which streams from disk — the file
			// contents are NOT loaded into PHP memory, avoiding OOM on
			// large files (videos, media, database dumps, etc.).
			$result = $this->add_native_file( $file_path, $archive_path );

			if ( ! $result ) {
				Logger::log( 'ZipArchive addFile failed for: ' . $file_path );
				return false;
			}

			$this->unflushed_count++;

			// Periodically flush (close + reopen) to commit deferred
			// writes to disk and persist progress.
			if ( $this->unflushed_count >= $this->flush_interval ) {
				return $this->flush();
			}

			return true;
		}

		// PclZip fallback — buffer the file for batch addition.
		// PclZip re-reads/rewrites the entire archive on every add() call,
		// so we collect files and add them in batches to avoid O(n²) I/O.
		if ( isset( $this->pclzip_entries[ $archive_path ] ) ) {
			return true;
		}

		$this->pclzip_buffer[] = [
			'file_path'    => $file_path,
			'archive_path' => $archive_path,
		];

		$this->unflushed_count++;

		if ( $this->unflushed_count >= $this->pclzip_flush_interval ) {
			return $this->flush();
		}

		return true;
	}

	/**
	 * Add a file exposed through a PHP stream wrapper.
	 *
	 * Native ZIP libraries accept local pathnames, not arbitrary PHP streams.
	 * Copy the source into a randomized 0700 directory below protected backup
	 * storage, then use the normal validated file path. The copy is streamed in
	 * constant memory and retained only until the matching ZIP write is flushed.
	 *
	 * @param string $stream_path  Stream-wrapper source path.
	 * @param string $archive_path Path within the archive.
	 * @return bool True on success, false on failure.
	 */
	public function addStreamFile( $stream_path, $archive_path ) {
		if ( ! $this->is_open || $this->write_failed || ! Helper::is_stream_wrapped_path( $stream_path ) ) {
			Logger::log( 'Cannot add stream file: archive is not open or source path is invalid' );
			return false;
		}
		// This API serves SDK-backed media wrappers such as s3://. Built-in
		// network wrappers would bypass the WordPress HTTP API and its safeguards;
		// they cannot enumerate an uploads directory through this backup pipeline.
		if ( preg_match( '#^(?:https?|ftps?)://#i', $stream_path ) ) {
			Logger::log( 'Cannot add stream file: built-in network URL wrappers are unsupported' );
			return false;
		}

		$staged_path = $this->stage_stream_source( $stream_path );
		if ( false === $staged_path ) {
			Logger::log( 'Cannot add stream file: source could not be copied to protected local storage: ' . $stream_path );
			return false;
		}

		$this->staged_stream_sources[ $staged_path ] = true;
		$result = $this->addFile( $staged_path, $archive_path );
		if ( ! $result && isset( $this->staged_stream_sources[ $staged_path ] ) ) {
			$this->cleanup_staged_stream_source( $staged_path );
		}

		// PHP 8.3/libzip can retain the already-open source descriptor, so the
		// local copy need not consume disk until the outer batch flushes.
		if (
			$result &&
			$this->use_zip_archive &&
			defined( 'ZipArchive::FL_OPEN_FILE_NOW' ) &&
			defined( 'ZipArchive::LENGTH_TO_END' ) &&
			isset( $this->staged_stream_sources[ $staged_path ] )
		) {
			$this->cleanup_staged_stream_source( $staged_path );
		}

		return $result;
	}

	/**
	 * Stream a remote source into an exclusively-created private local file.
	 *
	 * @param string $stream_path Source stream-wrapper path.
	 * @return string|false Local pathname, or false on failure.
	 */
	private function stage_stream_source( $stream_path ) {
		$source = @fopen( $stream_path, 'rb' );
		if ( ! is_resource( $source ) ) {
			return false;
		}

		$directory = $this->get_stream_stage_directory();
		if ( false === $directory ) {
			@fclose( $source );
			return false;
		}

		$target      = false;
		$target_path = '';
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			try {
				$suffix = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable $exception ) {
				break;
			}

			$target_path = $directory . DIRECTORY_SEPARATOR . 'source-' . $suffix . '.tmp';
			$target = @fopen( $target_path, 'xb' );
			if ( is_resource( $target ) ) {
				break;
			}
		}

		if ( ! is_resource( $target ) || ! @chmod( $target_path, 0600 ) ) {
			@fclose( $source );
			if ( is_resource( $target ) ) {
				@fclose( $target );
			}
			if ( '' !== $target_path ) {
				@unlink( $target_path );
			}
			return false;
		}

		$copied = @stream_copy_to_stream( $source, $target );
		$flushed = false !== $copied && @fflush( $target );
		$target_stat = @fstat( $target );
		@fclose( $source );
		@fclose( $target );

		if (
			! $flushed ||
			! is_array( $target_stat ) ||
			! isset( $target_stat['mode'] ) ||
			0100000 !== ( $target_stat['mode'] & 0170000 ) ||
			! Helper::is_path_within( $target_path, $directory )
		) {
			@unlink( $target_path );
			return false;
		}

		return $target_path;
	}

	/**
	 * Create or return this archive session's randomized stream staging dir.
	 *
	 * @return string|false
	 */
	private function get_stream_stage_directory() {
		if ( '' !== $this->stream_stage_directory && $this->stream_stage_directory_is_private( $this->stream_stage_directory ) ) {
			return $this->stream_stage_directory;
		}

		$root = $this->get_private_pclzip_root();
		if ( false === $root ) {
			return false;
		}

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			try {
				$suffix = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable $exception ) {
				return false;
			}

			$directory = $root . DIRECTORY_SEPARATOR . 'stream-stage-' . $suffix;
			if ( @mkdir( $directory, 0700, false ) && @chmod( $directory, 0700 ) && $this->stream_stage_directory_is_private( $directory ) ) {
				$this->stream_stage_directory = $directory;
				return $directory;
			}
		}

		return false;
	}

	/**
	 * Verify a stream staging directory is a direct randomized storage child.
	 *
	 * @param mixed $directory Candidate directory.
	 * @return bool
	 */
	private function stream_stage_directory_is_private( $directory ) {
		if ( ! is_string( $directory ) || '' === $directory || is_link( $directory ) || ! is_dir( $directory ) ) {
			return false;
		}

		$root      = $this->get_private_pclzip_root();
		$real_path = realpath( $directory );
		if ( false === $root || false === $real_path ) {
			return false;
		}

		return wp_normalize_path( dirname( $real_path ) ) === wp_normalize_path( $root )
			&& 1 === preg_match( '/^stream-stage-[a-f0-9]{32}$/D', basename( $real_path ) );
	}

	/**
	 * Remove one staged source after it is committed or rejected.
	 *
	 * @param string $path Local staged source path.
	 * @return void
	 */
	private function cleanup_staged_stream_source( $path ) {
		if ( ! isset( $this->staged_stream_sources[ $path ] ) ) {
			return;
		}

		clearstatcache( true, $path );
		if ( ( is_file( $path ) || is_link( $path ) ) && ! @unlink( $path ) ) {
			Logger::log( 'Could not remove a staged stream source from protected backup storage.' );
			return;
		}

		unset( $this->staged_stream_sources[ $path ] );
	}

	/**
	 * Remove every private stream source staged for the current ZIP flush.
	 *
	 * @return void
	 */
	private function cleanup_staged_stream_sources() {
		foreach ( array_keys( $this->staged_stream_sources ) as $path ) {
			$this->cleanup_staged_stream_source( $path );
		}

		if ( ! empty( $this->staged_stream_sources ) ) {
			return;
		}

		if ( '' !== $this->stream_stage_directory && $this->stream_stage_directory_is_private( $this->stream_stage_directory ) ) {
			if ( ! @rmdir( $this->stream_stage_directory ) && is_dir( $this->stream_stage_directory ) ) {
				Logger::log( 'Could not remove the private stream staging directory.' );
				return;
			}
		}
		$this->stream_stage_directory = '';
	}

	/**
	 * Return the number of successful commits in this open session.
	 *
	 * @return int
	 */
	public function getFlushCount() {
		return $this->flush_count;
	}

	/**
	 * Add a large file to the archive without reading it entirely into memory.
	 *
	 * Uses ZipArchive::addFile() (deferred write) followed by an immediate
	 * flush (close + reopen) so the entry is persisted to disk without
	 * requiring the whole file contents in PHP memory.
	 *
	 * @param string $file_path    Absolute path to the file to add.
	 * @param string $archive_path Path within the archive (relative path).
	 * @return bool True on success, false on failure.
	 */
	public function addLargeFile( $file_path, $archive_path ) {
		if ( ! $this->is_open || $this->write_failed ) {
			Logger::log( 'Cannot add large file: archive is not open' );
			return false;
		}

		if ( ! $this->source_is_readable( $file_path ) ) {
			Logger::log( 'Cannot add large file: file does not exist or is not readable: ' . $file_path );
			return false;
		}

		$archive_path = $this->normalize_archive_path( $archive_path );
		if ( false === $archive_path ) {
			Logger::log( 'Cannot add large file: unsafe archive path' );
			return false;
		}

		if ( $this->use_zip_archive ) {
			// Flush any pending writes first so the archive on disk is up to date
			// before we attempt the large file addition.
			if ( $this->unflushed_count > 0 && ! $this->flush() ) {
				Logger::log( 'Cannot add large file: pending archive writes could not be flushed' );
				return false;
			}

			$result = $this->add_native_file( $file_path, $archive_path );

			if ( ! $result ) {
				Logger::log( 'ZipArchive addFile (large) failed for: ' . $file_path );
				return false;
			}

			$this->unflushed_count++;

			// Flush immediately so the deferred write is committed to disk.
			if ( ! $this->flush() ) {
				Logger::log( 'ZipArchive flush after addFile (large) failed for: ' . $file_path );
				// libzip leaves the on-disk archive unchanged when close fails, so
				// existing data is preserved even though this large file was lost.
				return false;
			}

			return true;
		}

		// PclZip fallback — PclZip reads from disk directly, no memory issue.
		return $this->addFile( $file_path, $archive_path );
	}

	/**
	 * Verify that PHP can actually open a source before handing its path to ZIP.
	 *
	 * is_readable() can report cached mode information for provider-managed files
	 * that still fail when opened. ZipArchive normally defers that open until
	 * close(), where one denied source rolls back the complete batch. Opening the
	 * stream now turns the same condition into a single skippable file.
	 *
	 * @param mixed $file_path Source path.
	 * @return bool
	 */
	private function source_is_readable( $file_path ) {
		if ( ! is_string( $file_path ) || '' === $file_path || false !== strpos( $file_path, "\0" ) || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		$handle = @fopen( $file_path, 'rb' );
		if ( ! is_resource( $handle ) ) {
			return false;
		}

		$stat = @fstat( $handle );
		@fclose( $handle );

		return is_array( $stat ) && isset( $stat['mode'] ) && 0100000 === ( $stat['mode'] & 0170000 );
	}

	/**
	 * Register a native ZipArchive source and open it immediately when the
	 * installed extension supports that mode.
	 *
	 * libzip normally defers opening source paths until close(). Files in live
	 * WordPress directories can be rotated or removed between addFile() and that
	 * deferred read, which rolls back the complete archive batch. PHP 8.3's
	 * FL_OPEN_FILE_NOW keeps the exact source inode available through close.
	 * Older runtimes retain the compatible two-argument call.
	 *
	 * @param string $file_path Absolute source file path.
	 * @param string $archive_path Normalized archive entry path.
	 * @return bool
	 */
	private function add_native_file( $file_path, $archive_path ) {
		$result = false;
		if (
			$this->archive instanceof \ZipArchive &&
			defined( 'ZipArchive::FL_OPEN_FILE_NOW' ) &&
			defined( 'ZipArchive::LENGTH_TO_END' )
		) {
			$flags = constant( 'ZipArchive::FL_OVERWRITE' ) | constant( 'ZipArchive::FL_OPEN_FILE_NOW' );

			$result = $this->archive->addFile(
				$file_path,
				$archive_path,
				0,
				constant( 'ZipArchive::LENGTH_TO_END' ),
				$flags
			);
		} else {
			$result = $this->archive->addFile( $file_path, $archive_path );
		}

		if ( ! $result ) {
			return false;
		}

		$this->store_precompressed_native_entry( $file_path, $archive_path );

		return true;
	}

	/**
	 * Avoid wasting CPU deflating payloads that are already compressed.
	 *
	 * Failure to apply this optional optimization is deliberately non-fatal: the
	 * entry remains registered and libzip falls back to its normal compression.
	 * The filter may opt individual files in or out, but only a strict boolean
	 * true enables STORE so malformed extension code cannot broaden the policy.
	 *
	 * @param string $file_path Source file path.
	 * @param string $archive_path Normalized archive entry path.
	 * @return void
	 */
	private function store_precompressed_native_entry( $file_path, $archive_path ) {
		if (
			! $this->archive instanceof \ZipArchive ||
			! defined( 'ZipArchive::CM_STORE' ) ||
			! method_exists( $this->archive, 'setCompressionName' )
		) {
			return;
		}

		$extension = strtolower( (string) pathinfo( $archive_path, PATHINFO_EXTENSION ) );
		$store     = in_array( $extension, self::PRECOMPRESSED_EXTENSIONS, true );
		$store     = apply_filters( 'sssbm_store_archive_entry', $store, $archive_path, $file_path );
		if ( true !== $store ) {
			return;
		}

		if ( ! $this->archive->setCompressionName( $archive_path, constant( 'ZipArchive::CM_STORE' ) ) ) {
			Logger::log( 'ZipArchive could not disable redundant compression for: ' . $archive_path );
		}
	}

	/**
	 * Add a file to the archive from a string.
	 *
	 * @param string $archive_path Path within the archive (relative path).
	 * @param string $content      Content to add.
	 * @return bool True on success, false on failure.
	 */
	public function addFromString( $archive_path, $content ) {
		if ( ! $this->is_open || $this->write_failed ) {
			Logger::log( 'Cannot add from string: archive is not open' );
			return false;
		}
		if ( ! is_string( $content ) ) {
			Logger::log( 'Cannot add from string: content must be a string' );
			return false;
		}

		$archive_path = $this->normalize_archive_path( $archive_path );
		if ( false === $archive_path ) {
			Logger::log( 'Cannot add from string: unsafe archive path' );
			return false;
		}

		if ( $this->use_zip_archive ) {
			$result = $this->archive->addFromString( $archive_path, $content );
			if ( ! $result ) {
				return false;
			}

			$this->unflushed_count++;

			return $this->unflushed_count >= $this->flush_interval ? $this->flush() : true;
		}

		if ( isset( $this->pclzip_entries[ $archive_path ] ) ) {
			return true;
		}

		if ( ! $this->pclzip_has_capacity( strlen( $content ), 1 ) ) {
			return false;
		}

		// PclZip needs a real file with the correct basename. Stage it only in
		// our private storage root; shared system temp directories allow another
		// local user to race the old unlink-then-mkdir sequence.
		$temp_dir = $this->create_pclzip_temp_directory();
		if ( false === $temp_dir || ! $this->is_private_pclzip_temp_directory( $temp_dir ) ) {
			Logger::log( 'PclZip addFromString failed: could not create a private temp directory' );
			return false;
		}

		$temp_file = $temp_dir . DIRECTORY_SEPARATOR . basename( $archive_path );
		$handle    = false;
		$result    = 0;
		$write_ok  = true;
		try {
			// Exclusive creation fails closed if a file or symlink appeared after
			// the directory was created. The 0700 parent prevents access during
			// the brief interval before the file is tightened to 0600.
			$handle = @fopen( $temp_file, 'xb' );
			if ( ! is_resource( $handle ) || ! @chmod( $temp_file, 0600 ) ) {
				Logger::log( 'PclZip addFromString failed: could not create private temp file' );
				return false;
			}

			$offset = 0;
			$length = strlen( $content );
			while ( $offset < $length ) {
				$written = @fwrite( $handle, substr( $content, $offset, 1048576 ) );
				if ( false === $written || 0 === $written ) {
					$write_ok = false;
					break;
				}
				$offset += $written;
			}

			if ( ! $write_ok || ! @fflush( $handle ) ) {
				Logger::log( 'PclZip addFromString failed: could not write temp file' );
				return false;
			}

			fclose( $handle );
			$handle = false;

			$archive_dir = dirname( $archive_path );
			$add_path    = ( $archive_dir === '.' || $archive_dir === '' ) ? '' : $archive_dir;

			$result = $this->archive->add(
				$temp_file,
				PCLZIP_OPT_REMOVE_PATH, $temp_dir,
				PCLZIP_OPT_ADD_PATH, $add_path
			);
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			$this->cleanup_pclzip_temp_directory( $temp_dir, $temp_file );
		}

		$entries   = array( array( 'archive_path' => $archive_path ) );
		$confirmed = $this->get_confirmed_pclzip_entries( $result, $entries );
		if ( 1 !== count( $confirmed ) || ! is_array( $result ) || 1 !== count( $result ) ) {
			$error = $this->capture_pclzip_error( __( 'PclZip did not persist the requested string entry.', 'simply-static-studio-backup-migrate' ) );
			Logger::log( 'PclZip addFromString failed: ' . $error );
			$this->write_failed = true;
			return false;
		}

		if ( ! $this->validate_pclzip_commit( $confirmed ) ) {
			$this->write_failed = true;
			return false;
		}

		$this->flush_count++;

		return true;
	}

	/**
	 * Atomically create a randomized private directory for PclZip staging.
	 *
	 * mkdir() without the recursive flag is the atomic claim: an existing file,
	 * directory, or symlink makes that candidate fail and a new random name is
	 * tried. No predictable path in the system temp directory is ever unlinked.
	 *
	 * @return string|false Absolute directory path, or false on failure.
	 */
	protected function create_pclzip_temp_directory() {
		$root = $this->get_private_pclzip_root();
		if ( false === $root ) {
			return false;
		}

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			try {
				$suffix = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable $exception ) {
				return false;
			}

			$temp_dir = $root . DIRECTORY_SEPARATOR . 'pclzip-stage-' . $suffix;
			clearstatcache( true, $temp_dir );
			if ( file_exists( $temp_dir ) || is_link( $temp_dir ) || ! @mkdir( $temp_dir, 0700, false ) ) {
				continue;
			}

			if ( ! @chmod( $temp_dir, 0700 ) || ! $this->is_private_pclzip_temp_directory( $temp_dir ) ) {
				@rmdir( $temp_dir );
				continue;
			}

			return $temp_dir;
		}

		return false;
	}

	/**
	 * Return the resolved private root used for all PclZip temporary files.
	 *
	 * @return string|false
	 */
	private function get_private_pclzip_root() {
		$storage_path = Backup::get_storage_path();
		clearstatcache( true, $storage_path );
		if ( ! is_dir( $storage_path ) || is_link( rtrim( $storage_path, '/\\' ) ) || ! is_writable( $storage_path ) ) {
			return false;
		}

		$real_path = realpath( $storage_path );

		return false === $real_path ? false : rtrim( $real_path, DIRECTORY_SEPARATOR );
	}

	/**
	 * Verify that a staging directory is a direct randomized child of storage.
	 *
	 * @param mixed $temp_dir Candidate directory.
	 * @return bool
	 */
	private function is_private_pclzip_temp_directory( $temp_dir ) {
		if ( ! is_string( $temp_dir ) || '' === $temp_dir || is_link( $temp_dir ) || ! is_dir( $temp_dir ) ) {
			return false;
		}

		$root      = $this->get_private_pclzip_root();
		$real_path = realpath( $temp_dir );
		if ( false === $root || false === $real_path ) {
			return false;
		}

		return wp_normalize_path( dirname( $real_path ) ) === wp_normalize_path( $root )
			&& 1 === preg_match( '/^pclzip-stage-[a-f0-9]{32}$/D', basename( $real_path ) );
	}

	/**
	 * Remove only the staging file/link and the randomized directory itself.
	 *
	 * @param string $temp_dir  Staging directory.
	 * @param string $temp_file Staging file.
	 * @return void
	 */
	private function cleanup_pclzip_temp_directory( $temp_dir, $temp_file ) {
		clearstatcache( true, $temp_file );
		if ( is_file( $temp_file ) || is_link( $temp_file ) ) {
			@unlink( $temp_file );
		}

		clearstatcache( true, $temp_dir );
		if ( is_link( $temp_dir ) ) {
			@unlink( $temp_dir );
		} elseif ( is_dir( $temp_dir ) ) {
			@rmdir( $temp_dir );
		}
	}

	/**
	 * Flush pending writes to disk.
	 *
	 * For ZipArchive: closes and reopens the archive to commit deferred
	 * writes.
	 *
	 * For PclZip: writes all buffered files in one exact-path add() call,
	 * dramatically reducing whole-archive rewrites.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function flush() {
		if ( $this->write_failed ) {
			$this->cleanup_staged_stream_sources();
			return false;
		}

		if ( ! $this->is_open ) {
			$this->cleanup_staged_stream_sources();
			return true;
		}

		if ( $this->use_zip_archive ) {
			// There is nothing to commit. Avoid a needless close/reopen cycle.
			if ( 0 === $this->unflushed_count ) {
				return true;
			}

			// libzip writes changes through its own temporary archive. Copying the
			// complete ZIP before every close made many-file backups quadratic in
			// both I/O and temporary disk usage. The prior completed generation is
			// retained separately by Backup until final validation succeeds.
			$backup_path = $this->archive_path . '.bak';
			@unlink( $backup_path );

			if ( ! $this->archive->close() ) {
				$this->last_error = $this->get_native_error( __( 'Unknown ZipArchive close error.', 'simply-static-studio-backup-migrate' ) );
				Logger::log( 'ZipArchive flush (close) failed: ' . $this->last_error . $this->get_native_storage_context() );
				$this->cleanup_staged_stream_sources();

				// Try to reopen the archive so subsequent
				// addFile() calls still work in the current session.
				$this->archive = new \ZipArchive();
				$result = $this->archive->open( $this->archive_path );
				if ( $result !== true ) {
					Logger::log( 'ZipArchive flush: reopen after failed close returned error code ' . $result );
					$this->is_open = false;
				}

				// The unflushed entries were lost, but existing data is preserved.
				$this->unflushed_count = 0;
				$this->write_failed     = true;
				return false;
			}

			// Reopen without CHECKCONS — we just wrote this file so it is
			// known-good.  CHECKCONS reads the entire archive to verify the
			// central directory, which is extremely slow on large files.
			$this->archive = new \ZipArchive();
			$result = $this->archive->open( $this->archive_path );
			if ( $result !== true ) {
				Logger::log( 'ZipArchive flush (reopen) failed with error code: ' . $result );
				$this->is_open     = false;
				$this->write_failed = true;
				$this->cleanup_staged_stream_sources();
				return false;
			}

			$this->unflushed_count = 0;
			$this->flush_count++;
			$this->cleanup_staged_stream_sources();

			return true;
		}

		// PclZip: flush all buffered files in one exact-path batch.
		$result = $this->flush_pclzip_buffer();
		$this->cleanup_staged_stream_sources();

		return $result;
	}

	/**
	 * Flush buffered PclZip files to the archive.
	 *
	 * A pre-add callback maps every source to its exact stored filename, so
	 * files from different directories still require only one PclZip add().
	 *
	 * @return bool True on success, false on failure.
	 */
	private function flush_pclzip_buffer() {
		if ( empty( $this->pclzip_buffer ) ) {
			$this->unflushed_count = 0;
			return ! $this->write_failed;
		}

		if ( ! defined( 'PCLZIP_CB_PRE_ADD' ) ) {
			Logger::log( 'PclZip batch addFile failed: the pre-add callback API is unavailable.' );
			$this->write_failed = true;

			return false;
		}

		$entries        = $this->pclzip_buffer;
		$file_list      = [];
		$archive_paths  = [];
		$normalized_map = [];
		foreach ( $entries as $entry ) {
			$source_path  = (string) $entry['file_path'];
			$archive_path = (string) $entry['archive_path'];
			$file_list[]  = $source_path;
			$archive_paths[ $source_path ] = $archive_path;
			$normalized_map[ str_replace( '\\', '/', $source_path ) ] = $archive_path;
		}

		$pending_bytes = 0.0;
		foreach ( $file_list as $source_path ) {
			clearstatcache( true, $source_path );
			$source_size = @filesize( $source_path );
			if ( false !== $source_size ) {
				$pending_bytes += max( 0.0, (float) $source_size );
			}
		}

		if ( ! $this->pclzip_has_capacity( $pending_bytes, count( $entries ) ) ) {
			$this->pclzip_buffer    = [];
			$this->unflushed_count = 0;

			return false;
		}

		// PclZip normally needs one add() call for every REMOVE_PATH/ADD_PATH
		// pair. Each call copies the complete growing archive. A pre-add callback
		// can set the exact stored filename for every source, allowing this whole
		// buffer to be committed with one archive rewrite instead. WordPress'
		// bundled PclZip accepts only named functions as callbacks.
		self::$pclzip_callback_archive_paths    = $archive_paths;
		self::$pclzip_callback_normalized_paths = $normalized_map;

		try {
			$result = $this->archive->add(
				$file_list,
				constant( 'PCLZIP_CB_PRE_ADD' ), __NAMESPACE__ . '\\pclzip_map_archive_path'
			);
		} finally {
			self::$pclzip_callback_archive_paths    = [];
			self::$pclzip_callback_normalized_paths = [];
		}

		$confirmed = $this->get_confirmed_pclzip_entries( $result, $entries );
		foreach ( $confirmed as $archive_path ) {
			$this->pclzip_entries[ $archive_path ] = true;
		}

		// A non-zero PclZip result is not sufficient: PclZip can return a
		// descriptor array for only the files it committed before a partial
		// failure. Persist progress only when every expected descriptor is
		// present exactly once and reports a successful status.
		$success = is_array( $result ) && count( $entries ) === count( $result ) && count( $entries ) === count( $confirmed );
		if ( ! $success ) {
			$error = $this->capture_pclzip_error( __( 'PclZip returned an incomplete result for the archive batch.', 'simply-static-studio-backup-migrate' ) );
			Logger::log( 'PclZip batch addFile failed or returned a partial result (' . count( $confirmed ) . ' of ' . count( $file_list ) . ' files confirmed): ' . $error );
		} elseif ( ! $this->validate_pclzip_commit( $confirmed ) ) {
			$success = false;
		}

		$this->pclzip_buffer   = [];
		$this->unflushed_count = 0;
		$this->write_failed     = ! $success;
		if ( $success ) {
			$this->flush_count++;
		}

		return $success;
	}

	/**
	 * Check a pending PclZip append against the classic ZIP boundaries.
	 *
	 * @param int|float $additional_bytes Source bytes in the pending append.
	 * @param int       $additional_entries Entries in the pending append.
	 * @return bool
	 */
	private function pclzip_has_capacity( $additional_bytes, $additional_entries ) {
		clearstatcache( true, $this->archive_path );
		$current_size = @filesize( $this->archive_path );
		$current_size = false === $current_size ? 0 : $current_size;

		$exceeds_limit = self::would_exceed_classic_zip_limits(
			$current_size,
			$additional_bytes,
			count( $this->pclzip_entries ),
			$additional_entries,
			$this->pclzip_max_archive_size,
			$this->pclzip_max_entries
		);

		if ( ! $exceeds_limit ) {
			return true;
		}

		$this->last_error   = self::get_pclzip_limit_error();
		$this->write_failed = true;
		Logger::log( 'PclZip capacity check failed: ' . $this->last_error );

		return false;
	}

	/**
	 * Validate the on-disk central directory after PclZip reports success.
	 *
	 * PclZip can return successful file descriptors even when a classic ZIP
	 * boundary leaves the final archive unreadable. Re-list the archive before
	 * callers persist their matching CSV offset.
	 *
	 * @param string[] $confirmed_paths Paths PclZip reported as committed.
	 * @return bool
	 */
	private function validate_pclzip_commit( $confirmed_paths ) {
		$properties = $this->archive->properties();
		$entries    = $this->archive->listContent();
		if ( $properties === 0 || ! is_array( $entries ) ) {
			$error = $this->capture_pclzip_error( __( 'PclZip wrote an unreadable archive.', 'simply-static-studio-backup-migrate' ) );
			Logger::log( 'PclZip post-write validation failed: ' . $error );
			return false;
		}

		$listed_paths = [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['filename'] ) ) {
				continue;
			}

			$entry_path = $this->normalize_archive_path( $entry['filename'] );
			if ( false !== $entry_path ) {
				$listed_paths[ $entry_path ] = true;
			}
		}

		foreach ( $confirmed_paths as $confirmed_path ) {
			if ( ! isset( $listed_paths[ $confirmed_path ] ) ) {
				$this->last_error = __( 'PclZip did not persist every file reported by the archive batch.', 'simply-static-studio-backup-migrate' );
				Logger::log( 'PclZip post-write validation failed: ' . $this->last_error );
				return false;
			}
		}

		$this->pclzip_entries = $listed_paths;

		return true;
	}

	/**
	 * Compare a projected append against caller-supplied classic ZIP limits.
	 *
	 * @param mixed     $current_archive_bytes Current archive size.
	 * @param mixed     $additional_uncompressed_bytes Pending source bytes.
	 * @param mixed     $current_entries Existing entries.
	 * @param mixed     $additional_entries Pending entries.
	 * @param int|float $maximum_archive_bytes Size boundary.
	 * @param int       $maximum_entries Entry-count boundary.
	 * @return bool
	 */
	private static function would_exceed_classic_zip_limits(
		$current_archive_bytes,
		$additional_uncompressed_bytes,
		$current_entries,
		$additional_entries,
		$maximum_archive_bytes,
		$maximum_entries
	) {
		$current_bytes = is_numeric( $current_archive_bytes ) ? max( 0.0, (float) $current_archive_bytes ) : 0.0;
		$additional_bytes = is_numeric( $additional_uncompressed_bytes ) ? max( 0.0, (float) $additional_uncompressed_bytes ) : 0.0;
		$current_count = is_numeric( $current_entries ) ? max( 0, (int) $current_entries ) : 0;
		$additional_count = is_numeric( $additional_entries ) ? max( 0, (int) $additional_entries ) : 0;
		$maximum_bytes = is_numeric( $maximum_archive_bytes ) ? max( 0.0, (float) $maximum_archive_bytes ) : 0.0;
		$maximum_count = is_numeric( $maximum_entries ) ? max( 0, (int) $maximum_entries ) : 0;

		$projected_bytes = $current_bytes
			+ ( $additional_bytes * ( 1 + self::PCLZIP_EXPANSION_RESERVE_RATIO ) )
			+ ( $additional_count * self::PCLZIP_ENTRY_RESERVE_BYTES );

		return $projected_bytes >= $maximum_bytes
			|| ( $current_count + $additional_count ) > $maximum_count;
	}

	/**
	 * Preserve the native PclZip error before its object is discarded.
	 *
	 * @param string $fallback Message used when PclZip exposes no useful error.
	 * @return string
	 */
	private function capture_pclzip_error( $fallback ) {
		$error = '';
		if ( $this->archive instanceof \PclZip ) {
			$error = trim( (string) $this->archive->errorInfo( true ) );
		}

		if ( '' === $error || 'No error' === $error ) {
			$error = $fallback;
		}

		$this->last_error = $error;

		return $error;
	}

	/**
	 * Apply the active PclZip batch's archive-path mapping.
	 *
	 * Public only so the namespaced callback required by PclZip can delegate to
	 * it. The mapping is populated solely around the synchronous add() call.
	 *
	 * @param int   $event  PclZip callback event.
	 * @param array $header PclZip entry header.
	 * @return int One to add the entry, zero to skip it.
	 */
	public static function map_pclzip_archive_path( $event, &$header ) {
		$source_path = isset( $header['filename'] ) ? (string) $header['filename'] : '';
		$archive_path = isset( self::$pclzip_callback_archive_paths[ $source_path ] )
			? self::$pclzip_callback_archive_paths[ $source_path ]
			: ( self::$pclzip_callback_normalized_paths[ str_replace( '\\', '/', $source_path ) ] ?? '' );

		if ( '' === $archive_path ) {
			return 0;
		}

		$header['stored_filename'] = $archive_path;

		return 1;
	}

	/**
	 * Check whether an entry is already present in the open archive.
	 *
	 * Export tasks use this before registering a source so a replayed batch can
	 * distinguish already committed entries from genuinely new ones. This keeps the
	 * entry-count integrity check strict for same-batch path collisions while
	 * allowing an already committed batch to be retried safely.
	 *
	 * @param string $archive_path Path within the archive.
	 * @return bool
	 */
	public function hasEntry( $archive_path ) {
		if ( ! $this->is_open ) {
			return false;
		}

		$archive_path = $this->normalize_archive_path( $archive_path );
		if ( false === $archive_path ) {
			return false;
		}

		if ( $this->use_zip_archive && $this->archive instanceof \ZipArchive ) {
			return false !== $this->archive->locateName( $archive_path );
		}

		if ( isset( $this->pclzip_entries[ $archive_path ] ) ) {
			return true;
		}

		foreach ( $this->pclzip_buffer as $entry ) {
			if ( isset( $entry['archive_path'] ) && $archive_path === $entry['archive_path'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the number of entries in the archive (ZipArchive only).
	 *
	 * @return int Number of entries, or -1 if not available.
	 */
	public function getEntryCount() {
		if ( $this->use_zip_archive && $this->archive instanceof \ZipArchive ) {
			return $this->archive->count();
		}

		return -1;
	}

	/**
	 * Close the archive.
	 *
	 * Flushes any remaining buffered PclZip files before closing.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function close() {
		if ( ! $this->is_open ) {
			$this->cleanup_staged_stream_sources();
			return ! $this->write_failed;
		}

		// Flush any remaining PclZip buffered files before closing.
		if ( ! $this->use_zip_archive && ! empty( $this->pclzip_buffer ) ) {
			$this->flush_pclzip_buffer();
		}

		$write_failed          = $this->write_failed;
		$this->is_open         = false;
		$this->unflushed_count = 0;

		if ( $this->use_zip_archive ) {
			$backup_path = $this->archive_path . '.bak';
			@unlink( $backup_path );

			$result = $this->archive->close();
			$this->cleanup_staged_stream_sources();

			if ( ! $result ) {
				$this->last_error = $this->get_native_error( __( 'Unknown ZipArchive close error.', 'simply-static-studio-backup-migrate' ) );
				Logger::log( 'ZipArchive close failed: ' . $this->last_error . $this->get_native_storage_context() );
			}

			if ( $result ) {
				@chmod( $this->archive_path, 0600 );
			}

			return $result && ! $write_failed;
		}

		// PclZip doesn't need explicit close
		$this->cleanup_staged_stream_sources();
		if ( ! $write_failed ) {
			@chmod( $this->archive_path, 0600 );
		}

		return ! $write_failed;
	}

	/**
	 * Capture a printable native error before replacing the failed object.
	 *
	 * @param string $fallback Error used when the extension exposes no detail.
	 * @return string
	 */
	private function get_native_error( $fallback ) {
		$error = '';
		if ( is_object( $this->archive ) && method_exists( $this->archive, 'getStatusString' ) ) {
			$error = (string) $this->archive->getStatusString();
		}

		$error = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $error ) );

		return '' === $error || 'No error' === $error ? $fallback : $error;
	}

	/**
	 * Report enough storage state to distinguish a source read failure from a
	 * full or unwritable archive filesystem on the next customer log.
	 *
	 * @return string
	 */
	private function get_native_storage_context() {
		clearstatcache( true, $this->archive_path );
		$archive_size = @filesize( $this->archive_path );
		$free_space   = $this->get_archive_free_space();

		return sprintf(
			' (archive bytes: %s; free bytes: %s; pending entries: %d)',
			false === $archive_size ? 'unknown' : (string) $archive_size,
			false === $free_space ? 'unknown' : (string) (int) $free_space,
			(int) $this->unflushed_count
		);
	}

	/**
	 * Determine whether the archive filesystem has no writable capacity left.
	 *
	 * This is intentionally checked only after an archive write fails. Some
	 * managed hosts report quota availability rather than the underlying disk's
	 * capacity, which is exactly the signal needed to avoid retrying a ZIP commit
	 * that cannot succeed.
	 *
	 * @return bool
	 */
	public function is_storage_exhausted() {
		$free_space = $this->get_archive_free_space();

		return false !== $free_space && (float) $free_space <= 0;
	}

	/**
	 * Return an actionable error for a failed ZIP commit on a full filesystem.
	 *
	 * @return string
	 */
	public function get_storage_exhausted_error() {
		clearstatcache( true, $this->archive_path );
		$archive_size = @filesize( $this->archive_path );

		if ( false !== $archive_size ) {
			return sprintf(
				/* translators: Archive file size. */
				__( 'Backup storage is out of disk space. The current ZIP is %s. Free at least that much additional space, plus room for the remaining files, then resume the backup.', 'simply-static-studio-backup-migrate' ),
				size_format( $archive_size )
			);
		}

		return __( 'Backup storage is out of disk space. Free additional space for the ZIP archive and its temporary rewrite, then resume the backup.', 'simply-static-studio-backup-migrate' );
	}

	/**
	 * Read available bytes for the filesystem containing the archive.
	 *
	 * @return float|false
	 */
	private function get_archive_free_space() {
		if ( '' === $this->archive_path ) {
			return false;
		}

		$free_space = @disk_free_space( dirname( $this->archive_path ) );
		$free_space = apply_filters( 'sssbm_archive_free_space', $free_space, $this->archive_path );

		return is_numeric( $free_space ) ? (float) $free_space : false;
	}

	/**
	 * Determine whether the current archive has crossed the large-file limit.
	 *
	 * PHP caches stat results. The archive grows after every successful native
	 * close, so a stale size can accidentally trigger another multi-gigabyte
	 * CHECKCONS scan. Clear only this path's cache and use one
	 * fresh stat result for each decision.
	 *
	 * @return bool
	 */
	private function archive_is_large() {
		if ( '' === $this->archive_path ) {
			return false;
		}

		clearstatcache( true, $this->archive_path );
		$stat = @stat( $this->archive_path );

		return is_array( $stat ) && isset( $stat['size'] ) && (float) $stat['size'] >= (float) $this->large_archive_threshold;
	}

	/**
	 * Extract exactly the successful, expected entries from a PclZip result.
	 *
	 * PclZip descriptors normally expose the archive name as stored_filename;
	 * filename is retained as a compatibility fallback for older releases and
	 * test doubles. Unknown, duplicate, malformed, and non-ok descriptors never
	 * count as committed progress.
	 *
	 * @param mixed $result  PclZip add() return value.
	 * @param array $entries Expected buffered entries.
	 * @return string[] Confirmed normalized archive paths.
	 */
	private function get_confirmed_pclzip_entries( $result, $entries ) {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$expected = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['archive_path'] ) ) {
				continue;
			}

			$archive_path = $this->normalize_archive_path( $entry['archive_path'] );
			if ( false !== $archive_path ) {
				$expected[ $archive_path ] = true;
			}
		}

		$confirmed = array();
		foreach ( $result as $descriptor ) {
			if ( ! is_array( $descriptor ) ) {
				continue;
			}

			if ( ! isset( $descriptor['status'] ) || 'ok' !== strtolower( (string) $descriptor['status'] ) ) {
				continue;
			}

			$descriptor_path = isset( $descriptor['stored_filename'] )
				? $descriptor['stored_filename']
				: ( isset( $descriptor['filename'] ) ? $descriptor['filename'] : false );
			$archive_path = $this->normalize_archive_path( $descriptor_path );
			if ( false !== $archive_path && isset( $expected[ $archive_path ] ) ) {
				$confirmed[ $archive_path ] = true;
			}
		}

		return array_keys( $confirmed );
	}

	/**
	 * Validate and normalize a relative path stored inside the ZIP.
	 *
	 * Preventing absolute and parent-directory paths here protects every
	 * caller (including extension hooks) from creating a zip-slip payload.
	 *
	 * @param mixed $path Candidate archive entry path.
	 * @return string|false Normalized path, or false when unsafe.
	 */
	private function normalize_archive_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			return false;
		}

		$path = str_replace( '\\', '/', $path );

		if ( '/' === $path[0] || preg_match( '/^[a-zA-Z]:\//', $path ) ) {
			return false;
		}

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				return false;
			}

			$segments[] = $segment;
		}

		return empty( $segments ) ? false : implode( '/', $segments );
	}

	/**
	 * Load WordPress's PclZip with its existing global configuration.
	 *
	 * Pclzip_Archive scopes backup scratch paths to its own instance.
	 *
	 * @return void
	 */
	private function load_pclzip() {
		if ( ! class_exists( '\PclZip' ) ) {
			require_once Helper::get_wordpress_path() . 'wp-admin/includes/class-pclzip.php';
		}
	}

	/**
	 * Get the last error message.
	 *
	 * @return string
	 */
	public function getError() {
		if ( '' !== $this->last_error ) {
			return $this->last_error;
		}

		if ( $this->use_zip_archive && $this->archive instanceof \ZipArchive ) {
			return $this->archive->getStatusString();
		}

		if ( ! $this->use_zip_archive && $this->archive instanceof \PclZip ) {
			return $this->archive->errorInfo( true );
		}

		return __( 'Unknown error.', 'simply-static-studio-backup-migrate' );
	}
}
