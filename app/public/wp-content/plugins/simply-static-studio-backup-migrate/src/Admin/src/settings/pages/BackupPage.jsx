import {
	useCallback,
	useContext,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { Card, CardBody, CardHeader, Button } from '@wordpress/components';
import { DEFAULT_BACKUP_INTEGRITY, SettingsContext } from '../context/Context';
import ActivityLog from '../components/ActivityLog';
import BackupActions from '../components/BackupActions';
import AdvancedSettings from '../components/AdvancedSettings';
import TroubleshootingSettings from '../components/TroubleshootingSettings';
import ConnectCard from '../components/ConnectCard';
import MigrationObservationCard from '../components/MigrationObservationCard';
import apiFetch from '@wordpress/api-fetch';
import {
	assertSuccessfulResponse,
	getErrorMessage,
	sanitizeHttpUrl,
} from '../../utils/api';

import { __ } from '@wordpress/i18n';

const LogoSignet = () => (
	<svg
		aria-hidden="true"
		focusable="false"
		width="32"
		height="32"
		viewBox="0 0 250 250"
		xmlns="http://www.w3.org/2000/svg"
		style={ {
			fillRule: 'evenodd',
			clipRule: 'evenodd',
			strokeLinejoin: 'round',
			strokeMiterlimit: 2,
		} }
	>
		<g transform="matrix(2.76885,0,0,2.76885,9.35845,66.8542)">
			<path
				d="M49.845,26.088C48.122,28.565 45.136,30.18 41.765,30.18C37.951,30.18 34.652,28.099 33.065,25.072L25.878,35.398C29.883,39.35 35.52,41.811 41.765,41.811C49.474,41.811 56.255,38.061 60.185,32.385L82.6,0.189L67.877,0.189L49.845,26.088ZM33.685,15.912C35.408,13.434 38.395,11.82 41.765,11.82C45.58,11.82 48.878,13.901 50.465,16.927L57.652,6.602C53.648,2.65 48.011,0.189 41.765,0.189C34.056,0.189 27.274,3.939 23.345,9.614L0.93,41.811L15.653,41.811L33.685,15.912ZM41.765,16.367C44.471,16.367 46.664,18.441 46.664,21C46.664,23.559 44.471,25.633 41.765,25.633C39.06,25.633 36.867,23.559 36.867,21C36.867,18.441 39.06,16.367 41.765,16.367Z"
				style={ {
					fill: 'url(#sssbm-logo-gradient)',
					fillRule: 'nonzero',
				} }
			/>
		</g>
		<defs>
			<linearGradient
				id="sssbm-logo-gradient"
				x1="0"
				y1="0"
				x2="1"
				y2="0"
				gradientUnits="userSpaceOnUse"
				gradientTransform="matrix(-36.8384,49.5016,-49.5016,-36.8384,51.2888,-5.30671)"
			>
				<stop
					offset="0"
					style={ { stopColor: 'rgb(123,77,255)', stopOpacity: 1 } }
				/>
				<stop
					offset="1"
					style={ { stopColor: 'rgb(90,32,255)', stopOpacity: 1 } }
				/>
			</linearGradient>
		</defs>
	</svg>
);

function BackupPage() {
	const {
		isRunning,
		statusError,
		browserWorkerEnabled,
		browserWorkerError,
		backup,
		setBackup,
		setBackupLink,
		setBackupConsumed,
		settings,
		settingsError,
		saveSettings,
		setBackupIntegrity,
	} = useContext( SettingsContext );
	const [ debugLogUrl, setDebugLogUrl ] = useState( '' );
	const [ debugLogError, setDebugLogError ] = useState( '' );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveNotice, setSaveNotice ] = useState( null );
	const [ deleteNotice, setDeleteNotice ] = useState( null );
	const isMountedRef = useRef( true );
	const debugRequestRef = useRef( null );
	const saveInFlightRef = useRef( false );
	const deleteInFlightRef = useRef( false );
	const saveNoticeTimerRef = useRef( null );
	const settingsRef = useRef( settings );

	useEffect( () => {
		settingsRef.current = settings;
	}, [ settings ] );

	useEffect( () => {
		isMountedRef.current = true;
		return () => {
			isMountedRef.current = false;
			if ( saveNoticeTimerRef.current ) {
				clearTimeout( saveNoticeTimerRef.current );
			}
		};
	}, [] );

	const refreshDebugLogUrl = useCallback( () => {
		if ( debugRequestRef.current ) {
			return debugRequestRef.current;
		}

		const request = apiFetch( {
			path: '/sss-backup-migrate/v1/debug-log',
			method: 'GET',
		} )
			.then( ( response ) => {
				if ( ! isMountedRef.current ) {
					return;
				}
				const json = assertSuccessfulResponse(
					response,
					__(
						'Could not check the debug log.',
						'simply-static-studio-backup-migrate'
					)
				);

				const url = sanitizeHttpUrl( json.url );
				setDebugLogUrl( json.exists && url ? url : '' );
				setDebugLogError(
					json.exists && ! url
						? __(
								'The server returned an invalid debug log URL.',
								'simply-static-studio-backup-migrate'
						  )
						: ''
				);
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setDebugLogError(
						getErrorMessage(
							error,
							__(
								'Could not check the debug log.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} )
			.finally( () => {
				if ( debugRequestRef.current === request ) {
					debugRequestRef.current = null;
				}
			} );

		debugRequestRef.current = request;
		return request;
	}, [] );

	useEffect( () => {
		refreshDebugLogUrl();
	}, [ refreshDebugLogUrl ] );

	// Refresh when a backup finishes so the button appears even if the final
	// activity-log poll completed before the debug file was written.
	useEffect( () => {
		if ( ! isRunning && backup ) {
			refreshDebugLogUrl();
		}
	}, [ backup, isRunning, refreshDebugLogUrl ] );

	const handleDebugLogUrlFromLog = useCallback( ( url ) => {
		const safeUrl = sanitizeHttpUrl( url );
		if ( safeUrl ) {
			setDebugLogUrl( ( current ) => current || safeUrl );
		}
	}, [] );

	const handleDeleteAllBackups = () => {
		if ( isRunning || deleteInFlightRef.current ) {
			return;
		}
		// Native confirmation is intentional for this irreversible admin action.
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Are you sure you want to delete all backups? This action cannot be undone.',
					'simply-static-studio-backup-migrate'
				)
			)
		) {
			return;
		}

		deleteInFlightRef.current = true;
		setIsDeleting( true );
		setDeleteNotice( null );
		apiFetch( {
			path: '/sss-backup-migrate/v1/delete-backups',
			method: 'POST',
		} )
			.then( ( response ) => {
				assertSuccessfulResponse(
					response,
					__(
						'Could not delete the backups.',
						'simply-static-studio-backup-migrate'
					)
				);
				if ( ! isMountedRef.current ) {
					return;
				}

				setBackup( '' );
				setBackupLink( '' );
				setBackupConsumed( false );
				setBackupIntegrity( { ...DEFAULT_BACKUP_INTEGRITY } );
				setDebugLogUrl( '' );
				setDebugLogError( '' );
				setDeleteNotice( {
					type: 'success',
					message: __(
						'All backups were deleted.',
						'simply-static-studio-backup-migrate'
					),
				} );
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setDeleteNotice( {
						type: 'error',
						message: getErrorMessage(
							error,
							__(
								'Could not delete the backups.',
								'simply-static-studio-backup-migrate'
							)
						),
					} );
				}
			} )
			.finally( () => {
				deleteInFlightRef.current = false;
				if ( isMountedRef.current ) {
					setIsDeleting( false );
				}
			} );
	};

	const showSaveNotice = ( type, message ) => {
		if ( saveNoticeTimerRef.current ) {
			clearTimeout( saveNoticeTimerRef.current );
		}
		setSaveNotice( { type, message } );
		saveNoticeTimerRef.current = setTimeout( () => {
			if ( isMountedRef.current ) {
				setSaveNotice( null );
			}
			saveNoticeTimerRef.current = null;
		}, 5000 );
	};

	const handleSaveSettings = () => {
		if ( saveInFlightRef.current ) {
			return;
		}

		const settingsSnapshot = { ...settings };
		saveInFlightRef.current = true;
		setIsSaving( true );
		setSaveNotice( null );

		saveSettings( settingsSnapshot )
			.then( () => {
				if ( ! isMountedRef.current ) {
					return;
				}
				const changedWhileSaving =
					JSON.stringify( settingsRef.current ) !==
					JSON.stringify( settingsSnapshot );
				showSaveNotice(
					changedWhileSaving ? 'warning' : 'success',
					changedWhileSaving
						? __(
								'Settings saved, but you have newer unsaved changes.',
								'simply-static-studio-backup-migrate'
						  )
						: __(
								'Settings saved successfully.',
								'simply-static-studio-backup-migrate'
						  )
				);
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					showSaveNotice(
						'error',
						getErrorMessage(
							error,
							__(
								'Failed to save settings.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} )
			.finally( () => {
				saveInFlightRef.current = false;
				if ( isMountedRef.current ) {
					setIsSaving( false );
				}
			} );
	};

	let browserWorkerMessage = __(
		'Browser-assisted backup mode is active. Start a backup and keep this tab open until it finishes.',
		'simply-static-studio-backup-migrate'
	);
	if ( isRunning ) {
		browserWorkerMessage = __(
			'Browser-assisted backup mode is active. Keep this tab open until the backup finishes.',
			'simply-static-studio-backup-migrate'
		);
	}
	if ( browserWorkerError ) {
		browserWorkerMessage = (
			<>
				{ __(
					'Browser-assisted processing stopped:',
					'simply-static-studio-backup-migrate'
				) }{ ' ' }
				{ browserWorkerError }{ ' ' }
				{ __(
					'Reload this page to retry.',
					'simply-static-studio-backup-migrate'
				) }
			</>
		);
	}

	return (
		<div className="inner-settings">
			<div className="plugin-header-bar">
				<div className="plugin-header-left">
					<LogoSignet />
					<span className="plugin-header-title">
						{ __(
							'Backup and Migrate',
							'simply-static-studio-backup-migrate'
						) }
					</span>
				</div>
				<nav
					className="plugin-header-nav"
					aria-label={ __(
						'Plugin resources',
						'simply-static-studio-backup-migrate'
					) }
				>
					<a
						href="https://simplystatic.com/"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'Website',
							'simply-static-studio-backup-migrate'
						) }
					</a>
					<a
						href="https://docs.static.studio/"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Docs', 'simply-static-studio-backup-migrate' ) }
					</a>
				</nav>
			</div>
			<Card className="main-card">
				<CardBody>
					<h1 className="page-headline">
						{ __(
							'Backup & Migrate',
							'simply-static-studio-backup-migrate'
						) }
					</h1>
					<p className="page-description">
						{ __(
							'Create a ZIP backup of your database and site files.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
					{ browserWorkerEnabled && (
						<p
							className={ `browser-worker-notice${
								browserWorkerError
									? ' browser-worker-notice--error'
									: ''
							}` }
							role={ browserWorkerError ? 'alert' : 'status' }
						>
							{ browserWorkerMessage }
						</p>
					) }
					{ statusError && (
						<p className="page-status-error" role="alert">
							{ statusError }
						</p>
					) }
					<div className="top-action-row">
						<div className="top-action-progress">
							<ActivityLog
								onDebugLogUrl={ handleDebugLogUrlFromLog }
							/>
						</div>
						<div className="top-action-button">
							<BackupActions />
						</div>
					</div>
				</CardBody>
			</Card>
			<div className="bottom-cards-row">
				<Card>
					<CardHeader>
						<h3>
							{ __(
								'Optional migration',
								'simply-static-studio-backup-migrate'
							) }
						</h3>
					</CardHeader>
					<CardBody>
						<ConnectCard />
						<MigrationObservationCard />
					</CardBody>
				</Card>
				<Card>
					<CardHeader className="settings-card-header">
						<h3>
							{ __(
								'Settings',
								'simply-static-studio-backup-migrate'
							) }
						</h3>
						<div className="settings-card-header-actions">
							{ debugLogUrl && (
								<Button
									href={ debugLogUrl }
									target="_blank"
									rel="noopener noreferrer"
									variant="secondary"
									size="small"
									className="sss-header-button"
								>
									{ __(
										'View Debug Log',
										'simply-static-studio-backup-migrate'
									) }
								</Button>
							) }
							<Button
								onClick={ handleDeleteAllBackups }
								variant="secondary"
								size="small"
								isDestructive
								className="sss-header-button"
								disabled={ isDeleting || isRunning }
							>
								{ isDeleting
									? __(
											'Deleting…',
											'simply-static-studio-backup-migrate'
									  )
									: __(
											'Delete all backups',
											'simply-static-studio-backup-migrate'
									  ) }
							</Button>
						</div>
					</CardHeader>
					<CardBody>
						{ ( settingsError || debugLogError ) && (
							<p className="settings-load-error" role="alert">
								{ settingsError || debugLogError }
							</p>
						) }
						{ deleteNotice && (
							<p
								className={ `settings-delete-notice settings-delete-notice--${ deleteNotice.type }` }
								role={
									deleteNotice.type === 'error'
										? 'alert'
										: 'status'
								}
							>
								{ deleteNotice.message }
							</p>
						) }
						<AdvancedSettings />
						<TroubleshootingSettings />
						<div className="settings-save-area">
							{ saveNotice && (
								<div
									className={ `settings-save-notice settings-save-notice--${ saveNotice.type }` }
									role={
										saveNotice.type === 'error'
											? 'alert'
											: 'status'
									}
								>
									{ saveNotice.message }
								</div>
							) }
							<Button
								variant="primary"
								className="sss-save-settings-button"
								onClick={ handleSaveSettings }
								disabled={ isSaving }
							>
								{ isSaving
									? __(
											'Saving…',
											'simply-static-studio-backup-migrate'
									  )
									: __(
											'Save Settings',
											'simply-static-studio-backup-migrate'
									  ) }
							</Button>
						</div>
					</CardBody>
				</Card>
			</div>
		</div>
	);
}

export default BackupPage;
