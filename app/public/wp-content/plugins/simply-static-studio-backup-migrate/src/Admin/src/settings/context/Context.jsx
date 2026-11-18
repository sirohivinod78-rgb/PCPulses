import {
	createContext,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import useInterval from '../../hooks/useInterval';
import { assertSuccessfulResponse, getErrorMessage } from '../../utils/api';

import { __ } from '@wordpress/i18n';

export const DEFAULT_SETTINGS = Object.freeze( {
	task_delay: 5,
	iteration_delay: 1,
	batch_size: 250,
} );

export const SETTINGS_LIMITS = Object.freeze( {
	task_delay: Object.freeze( { min: 5, max: 300 } ),
	iteration_delay: Object.freeze( { min: 1, max: 60 } ),
	batch_size: Object.freeze( { min: 1, max: 1000 } ),
} );

export const RESET_SETTINGS = Object.freeze( {
	...DEFAULT_SETTINGS,
	include_plugins: true,
	include_themes: true,
	include_media: true,
	include_database: true,
	batch_delay_enabled: '',
	http_basic_auth_enabled: '',
} );

export const DEFAULT_BACKUP_INTEGRITY = Object.freeze( {
	issues: [],
	has_issues: false,
	is_pushable: false,
	can_push_to_studio: false,
	warning: '',
} );

const TRUE_LIKE_INCLUDE_SETTINGS = Object.freeze( [
	true,
	1,
	'1',
	'true',
	'on',
	'yes',
] );

export function includeSettingIsEnabled( value ) {
	return (
		typeof value === 'undefined' ||
		TRUE_LIKE_INCLUDE_SETTINGS.includes( value )
	);
}

const RUN_OUTCOMES = Object.freeze( [
	'running',
	'completed',
	'cancelled',
	'failed',
] );

function normalizePositiveInteger(
	value,
	fallback,
	minimum,
	maximum = Number.POSITIVE_INFINITY
) {
	const number = Number( value );
	if ( ! Number.isFinite( number ) || number < minimum ) {
		return fallback;
	}
	return Math.min( maximum, Math.floor( number ) );
}

export function normalizeSettings( value ) {
	const supplied =
		value && typeof value === 'object' && ! Array.isArray( value )
			? value
			: {};
	const normalized = { ...DEFAULT_SETTINGS, ...supplied };

	normalized.task_delay = normalizePositiveInteger(
		normalized.task_delay,
		DEFAULT_SETTINGS.task_delay,
		SETTINGS_LIMITS.task_delay.min,
		SETTINGS_LIMITS.task_delay.max
	);
	normalized.iteration_delay = normalizePositiveInteger(
		normalized.iteration_delay,
		DEFAULT_SETTINGS.iteration_delay,
		SETTINGS_LIMITS.iteration_delay.min,
		SETTINGS_LIMITS.iteration_delay.max
	);
	normalized.batch_size = normalizePositiveInteger(
		normalized.batch_size,
		DEFAULT_SETTINGS.batch_size,
		SETTINGS_LIMITS.batch_size.min,
		SETTINGS_LIMITS.batch_size.max
	);

	[
		'include_plugins',
		'include_themes',
		'include_media',
		'include_database',
	].forEach( ( field ) => {
		if ( Object.prototype.hasOwnProperty.call( supplied, field ) ) {
			normalized[ field ] = includeSettingIsEnabled( supplied[ field ] );
		}
	} );

	return normalized;
}

export function normalizeBackupIntegrity( value ) {
	const supplied =
		value && typeof value === 'object' && ! Array.isArray( value )
			? value
			: {};
	return {
		...DEFAULT_BACKUP_INTEGRITY,
		...supplied,
		issues: Array.isArray( supplied.issues ) ? supplied.issues : [],
		has_issues: localizedBoolean( supplied.has_issues, false ),
		is_pushable: localizedBoolean( supplied.is_pushable, false ),
		can_push_to_studio: localizedBoolean(
			supplied.can_push_to_studio,
			false
		),
		warning: typeof supplied.warning === 'string' ? supplied.warning : '',
	};
}

export function normalizeRunOutcome( value ) {
	return typeof value === 'string' && RUN_OUTCOMES.includes( value )
		? value
		: '';
}

function backupIntegrityIsEqual( current, next ) {
	return JSON.stringify( current ) === JSON.stringify( next );
}

export function prepareSettingsForSave( value ) {
	const prepared = { ...( value || {} ) };
	// This is a server-computed, read-only flag. Sending it back would risk
	// persisting stale state as an ordinary option.
	delete prepared.http_basic_auth_configured;
	return prepared;
}

function parseSettingsResponse( response ) {
	if (
		response === null ||
		response === false ||
		typeof response === 'undefined'
	) {
		return {};
	}

	if ( typeof response === 'string' ) {
		try {
			const parsed = JSON.parse( response );
			return parsed &&
				typeof parsed === 'object' &&
				! Array.isArray( parsed )
				? parsed
				: {};
		} catch {
			throw new Error(
				__(
					'The server returned invalid settings.',
					'simply-static-studio-backup-migrate'
				)
			);
		}
	}

	return response;
}

function localizedBoolean( value, fallback ) {
	if ( typeof value === 'undefined' || value === null ) {
		return fallback;
	}
	return value === true || value === 1 || value === '1' || value === 'true';
}

export const SettingsContext = createContext();

function SettingsContextProvider( { children } ) {
	const localizedOptions = window.sssbm_backup_migrate_options || {};
	const initialBlogId = normalizePositiveInteger(
		localizedOptions.blog_id,
		1,
		1
	);

	const [ isRunningState, setIsRunningState ] = useState( false );
	const [ isPaused, setIsPaused ] = useState( false );
	const [ lastRunOutcome, setLastRunOutcome ] = useState( '' );
	const [ backup, setBackup ] = useState( '' );
	const [ backupLink, setBackupLink ] = useState( '' );
	const [ backupSize, setBackupSize ] = useState( 0 );
	const [ backupConsumed, setBackupConsumed ] = useState( false );
	const [ backupDownloadStarted, setBackupDownloadStarted ] =
		useState( false );
	const [ backupIntegrity, setBackupIntegrity ] = useState( {
		...DEFAULT_BACKUP_INTEGRITY,
	} );
	const [ settingsSaved, setSettingsSaved ] = useState( false );
	const [ settings, setSettingsState ] = useState( { ...DEFAULT_SETTINGS } );
	const [ settingsError, setSettingsError ] = useState( '' );
	const [ statusError, setStatusError ] = useState( '' );
	const [ browserWorkerError, setBrowserWorkerError ] = useState( '' );
	const [ connectionError, setConnectionError ] = useState( '' );
	const [ blogId ] = useState( initialBlogId );
	const [ isMultisite ] = useState(
		localizedBoolean( localizedOptions.is_multisite, false )
	);
	const [ isMainSite ] = useState(
		localizedBoolean( localizedOptions.is_main_site, true )
	);
	const [ browserWorkerEnabled ] = useState(
		localizedBoolean( localizedOptions.browser_worker_enabled, false )
	);
	const [ studioConnected, setStudioConnected ] = useState( false );
	const [ studioEmail, setStudioEmail ] = useState( '' );
	const [ hardyPressRecognized, setHardyPressRecognized ] = useState( false );

	const isMountedRef = useRef( true );
	const statusRequestIdRef = useRef( 0 );
	const settingsRevisionRef = useRef( 0 );
	const settingsFieldRevisionRef = useRef( {} );

	useEffect( () => {
		isMountedRef.current = true;
		return () => {
			isMountedRef.current = false;
			statusRequestIdRef.current += 1;
		};
	}, [] );

	const setSettings = useCallback( ( value ) => {
		const revision = ++settingsRevisionRef.current;
		settingsFieldRevisionRef.current[ '*' ] = revision;
		setSettingsState( ( current ) =>
			typeof value === 'function' ? value( current ) : value
		);
		setSettingsSaved( false );
	}, [] );

	const updateSetting = useCallback( ( key, value ) => {
		// Functional state avoids dropping one of two updates fired in the same
		// render frame (for example, quickly toggling backup contents).
		const revision = ++settingsRevisionRef.current;
		settingsFieldRevisionRef.current[ key ] = revision;
		setSettingsState( ( current ) => ( { ...current, [ key ]: value } ) );
		setSettingsSaved( false );
	}, [] );

	const getSettings = useCallback( () => {
		const requestRevision = settingsRevisionRef.current;
		setSettingsError( '' );
		return apiFetch( { path: '/sss-backup-migrate/v1/settings' } )
			.then( ( response ) => {
				if ( ! isMountedRef.current ) {
					return;
				}
				const loadedSettings = normalizeSettings(
					parseSettingsResponse( response )
				);
				setSettingsState( ( current ) => {
					if ( requestRevision === settingsRevisionRef.current ) {
						return loadedSettings;
					}
					if (
						settingsFieldRevisionRef.current[ '*' ] >
						requestRevision
					) {
						return current;
					}

					const merged = { ...loadedSettings };
					Object.entries( settingsFieldRevisionRef.current ).forEach(
						( [ key, revision ] ) => {
							if ( revision > requestRevision && key !== '*' ) {
								merged[ key ] = current[ key ];
							}
						}
					);
					return merged;
				} );
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setSettingsError(
						getErrorMessage(
							error,
							__(
								'Could not load settings.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} );
	}, [] );

	const saveSettings = useCallback(
		( nextSettings = settings ) => {
			const requestRevision = settingsRevisionRef.current;
			setSettingsError( '' );
			return apiFetch( {
				path: '/sss-backup-migrate/v1/settings',
				method: 'POST',
				data: prepareSettingsForSave( nextSettings ),
			} )
				.then( ( response ) => {
					const parsed = assertSuccessfulResponse(
						response,
						__(
							'Could not save settings.',
							'simply-static-studio-backup-migrate'
						)
					);
					if (
						isMountedRef.current &&
						requestRevision === settingsRevisionRef.current
					) {
						setSettingsSaved( true );
					}
					return parsed;
				} )
				.catch( ( error ) => {
					if ( isMountedRef.current ) {
						setSettingsError(
							getErrorMessage(
								error,
								__(
									'Could not save settings.',
									'simply-static-studio-backup-migrate'
								)
							)
						);
					}
					throw error;
				} );
		},
		[ settings ]
	);

	const resetSettings = useCallback( () => {
		const resetPayload = { ...RESET_SETTINGS };
		const resetRevision = ++settingsRevisionRef.current;
		settingsFieldRevisionRef.current[ '*' ] = resetRevision;
		setSettingsSaved( false );
		return saveSettings( resetPayload ).then( ( response ) => {
			if (
				isMountedRef.current &&
				resetRevision === settingsRevisionRef.current
			) {
				setSettingsState( { ...DEFAULT_SETTINGS } );
			}
			return response;
		} );
	}, [ saveSettings ] );

	const setIsRunning = useCallback( ( value ) => {
		// Invalidate an older status request whenever a user action establishes
		// a newer known state.
		statusRequestIdRef.current += 1;
		setIsRunningState( value );
		setIsPaused( false );
		if ( value ) {
			setLastRunOutcome( 'running' );
			setBrowserWorkerError( '' );
		}
	}, [] );

	const checkIfRunning = useCallback( () => {
		const requestId = ++statusRequestIdRef.current;

		return apiFetch( {
			path: '/sss-backup-migrate/v1/is-running',
			method: 'GET',
		} )
			.then( ( response ) => {
				if (
					! isMountedRef.current ||
					requestId !== statusRequestIdRef.current
				) {
					return;
				}
				const json = assertSuccessfulResponse(
					response,
					__(
						'Could not refresh backup status.',
						'simply-static-studio-backup-migrate'
					)
				);

				const size = Number( json.backup_size );
				const isRunning = localizedBoolean( json.running, false );
				setIsRunningState( isRunning );
				setIsPaused(
					isRunning && localizedBoolean( json.paused, false )
				);
				setLastRunOutcome(
					normalizeRunOutcome(
						json.last_run_outcome ?? json.run_outcome
					)
				);
				setBackup( typeof json.backup === 'string' ? json.backup : '' );
				setBackupLink(
					typeof json.backup_link === 'string' ? json.backup_link : ''
				);
				setBackupSize( Number.isFinite( size ) && size > 0 ? size : 0 );
				const nextBackupConsumed = localizedBoolean(
					json.backup_consumed,
					false
				);
				setBackupConsumed( nextBackupConsumed );
				const nextBackupDownloadStarted = localizedBoolean(
					json.backup_download_started,
					false
				);
				setBackupDownloadStarted( nextBackupDownloadStarted );
				const nextIntegrity = normalizeBackupIntegrity(
					json.backup_integrity
				);
				setBackupIntegrity( ( current ) =>
					backupIntegrityIsEqual( current, nextIntegrity )
						? current
						: nextIntegrity
				);
				setStatusError( '' );

				// Consumers that need to reconcile an asynchronous operation (for
				// example a long PHP download) can make a bounded retry decision from
				// the exact status response that was accepted as current.
				return {
					...json,
					backup_consumed: nextBackupConsumed,
					backup_download_started: nextBackupDownloadStarted,
				};
			} )
			.catch( ( error ) => {
				if (
					isMountedRef.current &&
					requestId === statusRequestIdRef.current
				) {
					setStatusError(
						getErrorMessage(
							error,
							__(
								'Could not refresh backup status.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} );
	}, [] );

	const runBrowserWorker = useCallback( () => {
		return apiFetch( {
			path: '/sss-backup-migrate/v1/browser-worker',
			method: 'POST',
		} )
			.then( ( response ) => {
				assertSuccessfulResponse(
					response,
					__(
						'Browser-assisted processing could not continue.',
						'simply-static-studio-backup-migrate'
					)
				);
				if ( isMountedRef.current ) {
					setBrowserWorkerError( '' );
				}

				return checkIfRunning();
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setBrowserWorkerError(
						getErrorMessage(
							error,
							__(
								'Browser-assisted processing could not continue.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
				throw error;
			} );
	}, [ checkIfRunning ] );

	// Keep a slower idle poll so this tab can recover from an initial network
	// failure and discover backups started by WP-CLI or another browser tab.
	useInterval( checkIfRunning, isRunningState ? 5000 : 30000 );

	// In explicit fallback mode, chain one authenticated, bounded queue slice at
	// a time. The recursive interval waits for each REST request to finish, so a
	// slow archive task cannot create overlapping browser workers.
	useInterval(
		runBrowserWorker,
		browserWorkerEnabled &&
			isRunningState &&
			! isPaused &&
			! browserWorkerError
			? 1000
			: null
	);

	const checkConnectionStatus = useCallback( () => {
		return apiFetch( {
			path: '/sss-backup-migrate/v1/connection-status',
			method: 'GET',
		} )
			.then( ( response ) => {
				const json = assertSuccessfulResponse(
					response,
					__(
						'Could not check the Studio connection.',
						'simply-static-studio-backup-migrate'
					)
				);
				if ( isMountedRef.current ) {
					setStudioConnected(
						localizedBoolean( json.connected, false )
					);
					setStudioEmail(
						typeof json.email === 'string' ? json.email : ''
					);
					setHardyPressRecognized(
						localizedBoolean( json.hardypress_recognized, false )
					);
					setConnectionError( '' );
				}
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setConnectionError(
						getErrorMessage(
							error,
							__(
								'Could not check the Studio connection.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} );
	}, [] );

	useEffect( () => {
		getSettings();
		checkIfRunning();
		checkConnectionStatus();
	}, [ checkConnectionStatus, checkIfRunning, getSettings ] );

	const contextValue = useMemo(
		() => ( {
			settings,
			settingsSaved,
			setSettingsSaved,
			settingsError,
			setSettingsError,
			updateSetting,
			setSettings,
			saveSettings,
			resetSettings,
			getSettings,
			isRunning: isRunningState,
			isPaused,
			lastRunOutcome,
			setIsRunning,
			checkIfRunning,
			statusError,
			browserWorkerEnabled,
			browserWorkerError,
			blogId,
			isMultisite,
			isMainSite,
			backup,
			setBackup,
			backupLink,
			setBackupLink,
			backupSize,
			backupConsumed,
			setBackupConsumed,
			backupDownloadStarted,
			setBackupDownloadStarted,
			backupIntegrity,
			setBackupIntegrity,
			studioConnected,
			setStudioConnected,
			studioEmail,
			setStudioEmail,
			hardyPressRecognized,
			setHardyPressRecognized,
			connectionError,
			setConnectionError,
			checkConnectionStatus,
		} ),
		[
			backup,
			backupIntegrity,
			backupLink,
			backupSize,
			backupConsumed,
			backupDownloadStarted,
			blogId,
			browserWorkerEnabled,
			browserWorkerError,
			checkConnectionStatus,
			checkIfRunning,
			connectionError,
			getSettings,
			isMainSite,
			isMultisite,
			isRunningState,
			isPaused,
			lastRunOutcome,
			resetSettings,
			saveSettings,
			setIsRunning,
			setSettings,
			settings,
			settingsError,
			settingsSaved,
			statusError,
			studioConnected,
			studioEmail,
			hardyPressRecognized,
			updateSetting,
		]
	);

	return (
		<SettingsContext.Provider value={ contextValue }>
			{ children }
		</SettingsContext.Provider>
	);
}

export default SettingsContextProvider;
