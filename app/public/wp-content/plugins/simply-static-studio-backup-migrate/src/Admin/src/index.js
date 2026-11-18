import { createRoot } from '@wordpress/element';

import Settings from './settings/Settings';

const settingsElement = document.getElementById( 'static-studio-backup' );

// Be defensive when caches or third-party optimizers load the bundle outside
// the plugin screen. createRoot throws when it receives a missing container.
if ( settingsElement ) {
	const settings = createRoot( settingsElement );
	settings.render( <Settings /> );
}
