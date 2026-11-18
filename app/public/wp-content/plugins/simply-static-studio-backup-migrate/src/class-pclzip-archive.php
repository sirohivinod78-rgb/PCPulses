<?php

/**
 * Backup scratch I/O needs bounded local streams, exclusive creation,
 * owner-only permissions and atomic replacement. WP_Filesystem cannot provide
 * these local handle and creation guarantees. Exact-path cleanup must bypass
 * wp_delete_file's path filter so created scratch files cannot be redirected
 * or left behind by another plugin's deletion policy.
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
 * phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 */

namespace Simply_Static\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress PclZip with private, instance-scoped scratch files.
 *
 * Adapts the archive append and streamed compression routines from PclZip
 * 2.8.2, Copyright Vincent Blavet, licensed under GNU LGPL (August 2009).
 * https://github.com/WordPress/WordPress/blob/master/wp-admin/includes/class-pclzip.php
 * All parsing, callbacks and ZIP header generation remain in WordPress's copy.
 * The inherited write routines use a process-wide temporary-directory constant;
 * these two overrides keep scratch paths local to this backup instead.
 */
class Pclzip_Archive extends \PclZip {
	/** @var string Resolved plugin storage directory. */
	private $temporary_root;

	public function __construct( $path, $temporary_root ) {
		parent::__construct( $path );
		$this->temporary_root = $temporary_root;
	}

	/**
	 * Append through a private replacement archive, retaining the old archive
	 * until every new entry and central-directory header has been written.
	 */
	public function privAdd( $descriptors, &$results, &$options ) {
		if ( ! is_file( $this->zipname ) || 0 === filesize( $this->zipname ) ) {
			return $this->privCreate( $descriptors, $results, $options );
		}

		$temporary = false;
		$original  = false;
		$this->privDisableMagicQuotes();
		try {
			if ( 1 !== $this->privOpenFd( 'rb' ) ) {
				return $this->errorCode();
			}
			$original = $this->zip_fd;
			$central  = array();
			if ( 1 !== $this->privReadEndCentralDir( $central ) ) {
				return $this->errorCode();
			}

			$temporary = $this->open_temporary_file();
			if ( false === $temporary ) {
				return $this->fail( PCLZIP_ERR_WRITE_OPEN_FAIL, 'Unable to create private archive scratch file.' );
			}
			$this->zip_fd = $temporary['handle'];
			if ( ! rewind( $original ) || ! $this->copy_bytes( $original, $this->zip_fd, $central['offset'] ) ) {
				return $this->fail( PCLZIP_ERR_READ_OPEN_FAIL, 'Unable to copy the original archive data.' );
			}

			$headers = array();
			$result  = $this->privAddFileList( $descriptors, $headers, $options );
			if ( 1 !== $result ) {
				return $result;
			}
			$offset = ftell( $this->zip_fd );
			if ( false === $offset || ! $this->copy_bytes( $original, $this->zip_fd, $central['size'] ) ) {
				return $this->fail( PCLZIP_ERR_READ_OPEN_FAIL, 'Unable to copy the original central directory.' );
			}

			$count = 0;
			foreach ( $headers as $index => $header ) {
				if ( 'ok' === $header['status'] ) {
					$result = $this->privWriteCentralFileHeader( $header );
					if ( 1 !== $result ) {
						return $result;
					}
					$count++;
				}
				$this->privConvertHeader2FileInfo( $header, $results[ $index ] );
			}

			$comment = isset( $options[ PCLZIP_OPT_COMMENT ] ) ? $options[ PCLZIP_OPT_COMMENT ] : $central['comment'];
			if ( isset( $options[ PCLZIP_OPT_ADD_COMMENT ] ) ) {
				$comment .= $options[ PCLZIP_OPT_ADD_COMMENT ];
			}
			if ( isset( $options[ PCLZIP_OPT_PREPEND_COMMENT ] ) ) {
				$comment = $options[ PCLZIP_OPT_PREPEND_COMMENT ] . $comment;
			}
			$result = $this->privWriteCentralHeader( $count + $central['entries'], ftell( $this->zip_fd ) - $offset, $offset, $comment );
			if ( 1 !== $result ) {
				return $result;
			}
			if ( ! fflush( $this->zip_fd ) ) {
				return $this->fail( PCLZIP_ERR_WRITE_OPEN_FAIL, 'Unable to flush the replacement archive.' );
			}

			fclose( $original );
			$original = false;
			fclose( $this->zip_fd );
			$this->zip_fd = 0;
			if ( ! @rename( $temporary['path'], $this->zipname ) ) {
				return $this->fail( PCLZIP_ERR_RENAME_FILE_FAIL, 'Unable to replace the archive with its completed append.' );
			}
			return 1;
		} finally {
			if ( is_resource( $original ) ) {
				fclose( $original );
			}
			if ( is_resource( $this->zip_fd ) ) {
				fclose( $this->zip_fd );
			}
			$this->zip_fd = 0;
			$this->remove_temporary_file( $temporary );
			$this->privSwapBackMagicQuotes();
		}
	}

	/** Compress a large source with bounded memory and a private scratch file. */
	public function privAddFileUsingTempFile( $descriptor, &$header, &$options ) {
		$source    = @fopen( $descriptor['filename'], 'rb' );
		$temporary = false;
		if ( ! is_resource( $source ) ) {
			return $this->fail( PCLZIP_ERR_READ_OPEN_FAIL, 'Unable to open the compression source.' );
		}
		try {
			$temporary = $this->open_temporary_file();
			if ( false === $temporary ) {
				return $this->fail( PCLZIP_ERR_WRITE_OPEN_FAIL, 'Unable to create private compression scratch file.' );
			}
			$compressor = deflate_init( ZLIB_ENCODING_RAW );
			$crc        = hash_init( 'crc32b' );
			$size       = 0;
			if ( false === $compressor ) {
				return $this->fail( PCLZIP_ERR_BAD_FORMAT, 'Unable to initialize archive compression.' );
			}
			while ( ! feof( $source ) ) {
				$chunk = fread( $source, 1048576 );
				if ( false === $chunk || ( '' === $chunk && ! feof( $source ) ) ) {
					return $this->fail( PCLZIP_ERR_READ_OPEN_FAIL, 'Unable to read the compression source.' );
				}
				$size += strlen( $chunk );
				hash_update( $crc, $chunk );
				$compressed = deflate_add( $compressor, $chunk, feof( $source ) ? ZLIB_FINISH : ZLIB_NO_FLUSH );
				if ( false === $compressed || ! $this->write_bytes( $temporary['handle'], $compressed ) ) {
					return $this->fail( PCLZIP_ERR_WRITE_OPEN_FAIL, 'Unable to write compressed archive data.' );
				}
			}
			if ( $size !== $header['size'] ) {
				return $this->fail( PCLZIP_ERR_BAD_EXTRACTED_FILE, 'The compression source changed size while being read.' );
			}
			$crc_data                  = unpack( 'Ncrc', hash_final( $crc, true ) );
			$header['crc']             = $crc_data['crc'];
			$header['compression']     = 8;
			$header['compressed_size'] = ftell( $temporary['handle'] );
			$result = $this->privWriteFileHeader( $header );
			if ( 1 !== $result ) {
				return $result;
			}
			if ( ! rewind( $temporary['handle'] ) || ! $this->copy_bytes( $temporary['handle'], $this->zip_fd, $header['compressed_size'] ) ) {
				return $this->fail( PCLZIP_ERR_WRITE_OPEN_FAIL, 'Unable to copy compressed data into the archive.' );
			}
			return 1;
		} finally {
			fclose( $source );
			$this->remove_temporary_file( $temporary );
		}
	}

	/** Claim a private directory atomically, then create its owner-only file. */
	private function open_temporary_file() {
		if ( ! is_string( $this->temporary_root ) || is_link( $this->temporary_root ) || realpath( $this->temporary_root ) !== $this->temporary_root || ! wp_is_writable( $this->temporary_root ) ) {
			return false;
		}
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$directory = $this->temporary_root . DIRECTORY_SEPARATOR . 'pclzip-work-' . bin2hex( random_bytes( 16 ) );
			if ( ! @mkdir( $directory, 0700 ) ) {
				continue;
			}
			$path   = $directory . DIRECTORY_SEPARATOR . 'scratch';
			$handle = false;
			if ( @chmod( $directory, 0700 ) ) {
				$handle = @fopen( $path, 'x+b' );
			}
			if ( ! is_resource( $handle ) || ! @chmod( $path, 0600 ) ) {
				$this->remove_temporary_file( array( 'directory' => $directory, 'path' => $path, 'handle' => $handle ) );
				return false;
			}
			return array( 'directory' => $directory, 'path' => $path, 'handle' => $handle );
		}
		return false;
	}

	private function remove_temporary_file( $temporary ) {
		if ( ! is_array( $temporary ) ) {
			return;
		}
		if ( is_resource( $temporary['handle'] ) ) {
			fclose( $temporary['handle'] );
		}
		if ( is_file( $temporary['path'] ) ) {
			@unlink( $temporary['path'] );
		}
		@rmdir( $temporary['directory'] );
	}

	private function copy_bytes( $source, $destination, $length ) {
		return 0 === (int) $length || (int) $length === stream_copy_to_stream( $source, $destination, (int) $length );
	}

	private function write_bytes( $handle, $bytes ) {
		$offset = 0;
		while ( $offset < strlen( $bytes ) ) {
			$written = fwrite( $handle, substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	private function fail( $code, $message ) {
		$this->privErrorLog( $code, $message );
		return $code;
	}
}
