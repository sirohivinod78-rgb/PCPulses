<?php

namespace Simply_Static\Backup\ThirdParty\servmask\database;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

/**
 * Compatibility name for the former legacy MySQL adapter.
 *
 * All supported PHP versions use WordPress's database API; the removed mysql
 * extension is never called, even when older integrations request this class.
 */
class Database_Mysql extends Database_Mysqli {
}
