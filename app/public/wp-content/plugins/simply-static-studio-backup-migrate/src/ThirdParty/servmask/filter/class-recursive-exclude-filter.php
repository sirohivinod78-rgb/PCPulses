<?php

namespace Simply_Static\Backup\ThirdParty\servmask\filter;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

class Recursive_Exclude_Filter extends \RecursiveFilterIterator {

	protected $exclude = array();

	protected function replace_slash_with_separator( $path ) {
		return str_replace( '/', DIRECTORY_SEPARATOR, $path );
	}

	public function __construct( \RecursiveIterator $iterator, $exclude = array() ) {
		parent::__construct( $iterator );
		if ( is_array( $exclude ) ) {
			foreach ( $exclude as $path ) {
				$this->exclude[] = $this->replace_slash_with_separator( $path );
			}
		}
	}

	#[\ReturnTypeWillChange]
	public function accept() {
		// Do not archive symbolic links by default. Following them can escape the
		// intended WordPress directory (or recurse through a link cycle) and leak
		// arbitrary files into a migration archive. Advanced integrations can opt
		// in explicitly when they control the linked targets.
		if (
			$this->getInnerIterator()->isLink()
			&& ! apply_filters( 'sssbm_follow_symbolic_links', false, $this->getInnerIterator()->getPathname() )
		) {
			return false;
		}

		// Honor explicit excludes passed in (inactive plugins/themes, core plugins, etc.).
		// Once a plugin/theme is allowed, include its entire directory — no per-file
		// exclusions (node_modules, archives, etc.) to maximise the chance the site
		// works correctly on Studio.
		if ( in_array( $this->replace_slash_with_separator( $this->getInnerIterator()->getSubPathname() ), $this->exclude, true ) ) {
			return false;
		}

		if ( in_array( $this->replace_slash_with_separator( $this->getInnerIterator()->getPathname() ), $this->exclude, true ) ) {
			return false;
		}

		if ( in_array( $this->replace_slash_with_separator( $this->getInnerIterator()->getPath() ), $this->exclude, true ) ) {
			return false;
		}

		return true;
	}

	#[\ReturnTypeWillChange]
	public function getChildren() {
		return new self( $this->getInnerIterator()->getChildren(), $this->exclude, true );
	}
}
