import { useContext } from '@wordpress/element';
import { includeSettingIsEnabled, SettingsContext } from '../context/Context';
import { CheckboxControl } from '@wordpress/components';

import { __ } from '@wordpress/i18n';

export function settingIsEnabled( value ) {
	return includeSettingIsEnabled( value );
}

function AdvancedSettings() {
	const { settings, updateSetting } = useContext( SettingsContext );

	// WordPress sanitizes a submitted false value to an empty string. Mirror
	// the backend's false-like values so excluded content stays unchecked after
	// a reload; an absent key keeps the backwards-compatible default of true.
	const includePlugins = settingIsEnabled( settings.include_plugins );
	const includeThemes = settingIsEnabled( settings.include_themes );
	const includeMedia = settingIsEnabled( settings.include_media );
	const includeDatabase = settingIsEnabled( settings.include_database );

	return (
		<div className="advanced-settings">
			<h4 className="settings-section-title">
				{ __(
					'Backup Contents',
					'simply-static-studio-backup-migrate'
				) }
			</h4>
			<p className="settings-section-description">
				{ __(
					'Select which parts of your WordPress site to include in the backup.',
					'simply-static-studio-backup-migrate'
				) }
			</p>
			<div className="settings-checkbox-group">
				<CheckboxControl
					label={ __(
						'Include Plugins',
						'simply-static-studio-backup-migrate'
					) }
					checked={ includePlugins }
					onChange={ ( value ) =>
						updateSetting( 'include_plugins', value )
					}
				/>
				<CheckboxControl
					label={ __(
						'Include Themes',
						'simply-static-studio-backup-migrate'
					) }
					checked={ includeThemes }
					onChange={ ( value ) =>
						updateSetting( 'include_themes', value )
					}
				/>
				<CheckboxControl
					label={ __(
						'Include Media Files',
						'simply-static-studio-backup-migrate'
					) }
					checked={ includeMedia }
					onChange={ ( value ) =>
						updateSetting( 'include_media', value )
					}
				/>
				<CheckboxControl
					label={ __(
						'Include Database',
						'simply-static-studio-backup-migrate'
					) }
					checked={ includeDatabase }
					onChange={ ( value ) =>
						updateSetting( 'include_database', value )
					}
				/>
			</div>
		</div>
	);
}

export default AdvancedSettings;
