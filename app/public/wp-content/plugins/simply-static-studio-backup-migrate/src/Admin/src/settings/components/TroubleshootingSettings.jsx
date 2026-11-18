import { useContext, useState, useEffect, useRef } from '@wordpress/element';
import { SETTINGS_LIMITS, SettingsContext } from '../context/Context';
import { Button, ToggleControl, TextControl } from '@wordpress/components';
import { getErrorMessage } from '../../utils/api';

import { __ } from '@wordpress/i18n';

function TroubleshootingSettings() {
	const { settings, updateSetting, saveSettings } =
		useContext( SettingsContext );
	const [ showDelaySettings, setShowDelaySettings ] = useState( false );
	const [ showBasicAuth, setShowBasicAuth ] = useState( false );
	const [ isSavingCredentials, setIsSavingCredentials ] = useState( false );
	const [ credentialsError, setCredentialsError ] = useState( '' );
	const isMountedRef = useRef( true );
	const saveRequestRef = useRef( false );
	const basicAuthConfigured =
		settings.http_basic_auth_configured === true ||
		settings.http_basic_auth_configured === '1';

	// Default delays to minimum values if not set
	const taskDelay = settings.task_delay || 5;
	const iterationDelay = settings.iteration_delay || 1;
	const batchSize = settings.batch_size || 250;

	const handleSettingChange = ( key, value ) => {
		const limits = SETTINGS_LIMITS[ key ] || {
			min: 0,
			max: Number.MAX_SAFE_INTEGER,
		};
		const numValue = Math.min(
			limits.max,
			Math.max( limits.min, parseInt( value, 10 ) || limits.min )
		);
		updateSetting( key, numValue );
	};

	const handleTextSettingChange = ( key, value ) => {
		updateSetting( key, value );
	};

	const saveBasicAuth = () => {
		if ( saveRequestRef.current ) {
			return;
		}

		const username = settings.http_basic_auth_username || '';
		const password = settings.http_basic_auth_password || '';
		if ( showBasicAuth && ( ! username || ! password ) ) {
			setCredentialsError(
				basicAuthConfigured
					? __(
							'Enter the username and a new password to replace the saved credentials.',
							'simply-static-studio-backup-migrate'
					  )
					: __(
							'Enter both a username and password.',
							'simply-static-studio-backup-migrate'
					  )
			);
			return;
		}

		saveRequestRef.current = true;
		setIsSavingCredentials( true );
		setCredentialsError( '' );
		saveSettings( {
			...settings,
			batch_delay_enabled: showDelaySettings ? 'on' : '',
			http_basic_auth_enabled: showBasicAuth ? 'on' : '',
			http_basic_auth_username: username,
			http_basic_auth_password: password,
		} )
			.then( () => {
				if ( isMountedRef.current ) {
					window.location.reload();
				}
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setCredentialsError(
						getErrorMessage(
							error,
							__(
								'Could not save HTTP Basic Auth credentials.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} )
			.finally( () => {
				saveRequestRef.current = false;
				if ( isMountedRef.current ) {
					setIsSavingCredentials( false );
				}
			} );
	};

	useEffect( () => {
		isMountedRef.current = true;
		return () => {
			isMountedRef.current = false;
		};
	}, [] );

	// Sync toggle states when settings load from the API
	useEffect( () => {
		setShowDelaySettings( settings.batch_delay_enabled === 'on' );
		setShowBasicAuth(
			settings.http_basic_auth_enabled === 'on' || basicAuthConfigured
		);
	}, [
		settings.batch_delay_enabled,
		settings.http_basic_auth_enabled,
		basicAuthConfigured,
	] );

	return (
		<div className="troubleshooting-settings">
			<h4 className="settings-section-title">
				{ __( 'Performance', 'simply-static-studio-backup-migrate' ) }
			</h4>
			<p className="settings-section-description">
				{ __(
					'Fine-tune backup speed and reliability for your server environment.',
					'simply-static-studio-backup-migrate'
				) }
			</p>
			<ToggleControl
				label={ __(
					'Batch & Delay',
					'simply-static-studio-backup-migrate'
				) }
				help={ __(
					'Adjust batch size and delay intervals to optimize backup performance. Lower delays and larger batches speed things up, but may cause timeouts on slower servers.',
					'simply-static-studio-backup-migrate'
				) }
				checked={ showDelaySettings }
				onChange={ ( val ) => {
					setShowDelaySettings( val );
					updateSetting( 'batch_delay_enabled', val ? 'on' : '' );
				} }
				className="delay-toggle"
			/>
			{ showDelaySettings && (
				<div className="delay-settings-panel">
					<TextControl
						type="number"
						label={ __(
							'Delay (in seconds) between each task',
							'simply-static-studio-backup-migrate'
						) }
						value={ taskDelay }
						onChange={ ( value ) =>
							handleSettingChange( 'task_delay', value )
						}
						min={ 5 }
						max={ SETTINGS_LIMITS.task_delay.max }
						step={ 1 }
					/>
					<TextControl
						type="number"
						label={ __(
							'Delay (in seconds) between each iteration',
							'simply-static-studio-backup-migrate'
						) }
						value={ iterationDelay }
						onChange={ ( value ) =>
							handleSettingChange( 'iteration_delay', value )
						}
						min={ 1 }
						max={ SETTINGS_LIMITS.iteration_delay.max }
						step={ 1 }
					/>
					<TextControl
						type="number"
						label={ __(
							'Batch size (files per iteration)',
							'simply-static-studio-backup-migrate'
						) }
						value={ batchSize }
						onChange={ ( value ) =>
							handleSettingChange( 'batch_size', value )
						}
						min={ 1 }
						max={ SETTINGS_LIMITS.batch_size.max }
						step={ 1 }
					/>
				</div>
			) }

			<h4 className="settings-section-title">
				{ __(
					'Authentication',
					'simply-static-studio-backup-migrate'
				) }
			</h4>
			<p className="settings-section-description">
				{ __(
					'Configure authentication if your site is behind access restrictions.',
					'simply-static-studio-backup-migrate'
				) }
			</p>
			<ToggleControl
				label={ __(
					'HTTP Basic Auth',
					'simply-static-studio-backup-migrate'
				) }
				help={ __(
					'Enable if your site is protected with HTTP Basic Authentication.',
					'simply-static-studio-backup-migrate'
				) }
				checked={ showBasicAuth }
				onChange={ ( val ) => {
					setShowBasicAuth( val );
					updateSetting( 'http_basic_auth_enabled', val ? 'on' : '' );
					setCredentialsError( '' );
					if ( ! val ) {
						// The backend enables Basic Auth whenever credentials
						// exist, so disabling must clear the stored secret too.
						updateSetting( 'http_basic_auth_username', '' );
						updateSetting( 'http_basic_auth_password', '' );
						updateSetting( 'http_basic_auth_configured', false );
					}
				} }
				className="delay-toggle"
				disabled={ isSavingCredentials }
			/>
			{ showBasicAuth && (
				<div className="delay-settings-panel">
					<TextControl
						label={ __(
							'Username',
							'simply-static-studio-backup-migrate'
						) }
						value={ settings.http_basic_auth_username || '' }
						onChange={ ( value ) =>
							handleTextSettingChange(
								'http_basic_auth_username',
								value
							)
						}
						autoComplete="username"
						disabled={ isSavingCredentials }
					/>
					<TextControl
						label={ __(
							'Password',
							'simply-static-studio-backup-migrate'
						) }
						type="password"
						placeholder={
							basicAuthConfigured
								? __(
										'Enter a new password to replace the saved password',
										'simply-static-studio-backup-migrate'
								  )
								: ''
						}
						value={ settings.http_basic_auth_password || '' }
						onChange={ ( value ) =>
							handleTextSettingChange(
								'http_basic_auth_password',
								value
							)
						}
						autoComplete="current-password"
						disabled={ isSavingCredentials }
					/>
					{ basicAuthConfigured && (
						<p className="settings-section-hint" role="status">
							{ __(
								'A password is already saved. Enter a new password only when you want to replace it.',
								'simply-static-studio-backup-migrate'
							) }
						</p>
					) }
					{ credentialsError && (
						<p className="settings-credentials-error" role="alert">
							{ credentialsError }
						</p>
					) }
					<Button
						type="button"
						variant="primary"
						className="sss-save-credentials-button"
						onClick={ saveBasicAuth }
						disabled={ isSavingCredentials }
					>
						{ isSavingCredentials
							? __(
									'Saving…',
									'simply-static-studio-backup-migrate'
							  )
							: __(
									'Save & Reload',
									'simply-static-studio-backup-migrate'
							  ) }
					</Button>
					<p className="settings-section-hint">
						{ __(
							'Saving credentials will reload the page to activate them.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
				</div>
			) }
		</div>
	);
}

export default TroubleshootingSettings;
