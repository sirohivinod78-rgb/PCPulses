import { useContext, useEffect, useRef, useState } from '@wordpress/element';
import { SettingsContext, DEFAULT_BACKUP_INTEGRITY } from '../context/Context';
import apiFetch from '@wordpress/api-fetch';
import { Button, Spinner } from '@wordpress/components';
import {
	assertSuccessfulResponse,
	getErrorMessage,
	sanitizeHttpUrl,
} from '../../utils/api';

import { __ } from '@wordpress/i18n';

const DOWNLOAD_UI_RESET_DELAY_MS = 3000;
const DOWNLOAD_RECONCILE_INTERVAL_MS = 5000;
const DOWNLOAD_RECONCILE_MAX_ATTEMPTS = 120;

function shouldContinueDownloadReconciliation( status ) {
	if ( ! status || typeof status !== 'object' || Array.isArray( status ) ) {
		// A transient status failure must not make a completed, slow PHP stream
		// permanently miss its eventual consumed marker.
		return true;
	}

	if (
		status.backup_consumed === true ||
		status.backup_consumed === 1 ||
		status.backup_consumed === '1' ||
		status.backup_consumed === 'true'
	) {
		return false;
	}
	if (
		status.backup_download_started === true ||
		status.backup_download_started === 1 ||
		status.backup_download_started === '1' ||
		status.backup_download_started === 'true'
	) {
		return false;
	}

	// A successful status response always includes `backup`. An empty value
	// means the generation was consumed, deleted, or otherwise stopped being
	// reusable, so there is nothing left for this download poll to reconcile.
	return (
		! Object.prototype.hasOwnProperty.call( status, 'backup' ) ||
		typeof status.backup !== 'string' ||
		status.backup !== ''
	);
}

function BackupActions() {
	const {
		isRunning,
		isPaused,
		setIsRunning,
		checkIfRunning,
		blogId,
		isMultisite,
		isMainSite,
		backup,
		setBackup,
		setBackupLink,
		setBackupConsumed,
		backupDownloadStarted,
		setBackupDownloadStarted,
		backupIntegrity,
		setBackupIntegrity,
	} = useContext( SettingsContext );
	const [ isStarting, setIsStarting ] = useState( false );
	const [ isCancelling, setIsCancelling ] = useState( false );
	const [ isPausing, setIsPausing ] = useState( false );
	const [ isResuming, setIsResuming ] = useState( false );
	const [ isDownloading, setIsDownloading ] = useState( false );
	const [ actionError, setActionError ] = useState( '' );
	const isMountedRef = useRef( true );
	const actionInFlightRef = useRef( '' );
	const downloadResetTimer = useRef( null );
	const downloadReconcileTimer = useRef( null );
	const downloadReconcileAttemptsRef = useRef( 0 );
	const downloadInFlightRef = useRef( false );

	const isMultisiteMainSite = isMultisite && isMainSite;
	const safeBackupUrl = sanitizeHttpUrl( backup );
	const backupWarning = backupIntegrity?.warning || '';
	const unsafeBackupError =
		backup && ! safeBackupUrl
			? __(
					'The backup download URL returned by the server is invalid.',
					'simply-static-studio-backup-migrate'
			  )
			: '';
	const backupActionPending = isCancelling || isPausing || isResuming;
	let pauseActionLabel = isPaused
		? __( 'Resume', 'simply-static-studio-backup-migrate' )
		: __( 'Pause', 'simply-static-studio-backup-migrate' );
	if ( isPausing ) {
		pauseActionLabel = __(
			'Pausing…',
			'simply-static-studio-backup-migrate'
		);
	} else if ( isResuming ) {
		pauseActionLabel = __(
			'Resuming…',
			'simply-static-studio-backup-migrate'
		);
	}
	let downloadActionLabel = __(
		'Download Backup',
		'simply-static-studio-backup-migrate'
	);
	if ( backupDownloadStarted ) {
		downloadActionLabel = __(
			'Download Again',
			'simply-static-studio-backup-migrate'
		);
	}
	if ( isDownloading ) {
		downloadActionLabel = __(
			'Downloading…',
			'simply-static-studio-backup-migrate'
		);
	}

	useEffect( () => {
		isMountedRef.current = true;
		return () => {
			isMountedRef.current = false;
			if ( downloadResetTimer.current ) {
				clearTimeout( downloadResetTimer.current );
			}
			if ( downloadReconcileTimer.current ) {
				clearTimeout( downloadReconcileTimer.current );
			}
		};
	}, [] );

	const stopDownloadReconciliation = () => {
		if ( downloadResetTimer.current ) {
			clearTimeout( downloadResetTimer.current );
			downloadResetTimer.current = null;
		}
		if ( downloadReconcileTimer.current ) {
			clearTimeout( downloadReconcileTimer.current );
			downloadReconcileTimer.current = null;
		}
		downloadReconcileAttemptsRef.current = 0;
		downloadInFlightRef.current = false;
	};

	const reconcileDownloadStatus = () => {
		if ( ! isMountedRef.current ) {
			return;
		}

		downloadReconcileAttemptsRef.current += 1;
		Promise.resolve()
			.then( () => checkIfRunning() )
			.then( ( status ) => {
				if (
					isMountedRef.current &&
					shouldContinueDownloadReconciliation( status ) &&
					downloadReconcileAttemptsRef.current <
						DOWNLOAD_RECONCILE_MAX_ATTEMPTS
				) {
					scheduleDownloadReconciliation(
						DOWNLOAD_RECONCILE_INTERVAL_MS
					);
				}
			} )
			.catch( () => {
				// Context-level polling reports the error to the user. Keep this
				// background reconciliation resilient to custom/rejected callers.
				if (
					isMountedRef.current &&
					downloadReconcileAttemptsRef.current <
						DOWNLOAD_RECONCILE_MAX_ATTEMPTS
				) {
					scheduleDownloadReconciliation(
						DOWNLOAD_RECONCILE_INTERVAL_MS
					);
				}
			} );
	};

	const scheduleDownloadReconciliation = ( delay ) => {
		if (
			! isMountedRef.current ||
			downloadReconcileAttemptsRef.current >=
				DOWNLOAD_RECONCILE_MAX_ATTEMPTS
		) {
			return;
		}

		if ( downloadReconcileTimer.current ) {
			clearTimeout( downloadReconcileTimer.current );
		}
		downloadReconcileTimer.current = setTimeout( () => {
			downloadReconcileTimer.current = null;
			reconcileDownloadStatus();
		}, delay );
	};

	useEffect( () => {
		// The cancel endpoint only acknowledges the request. Keep the running UI
		// locked until status polling observes that the worker has actually stopped.
		if ( ! isRunning ) {
			setIsCancelling( false );
			setIsPausing( false );
			setIsResuming( false );
		} else if ( isPaused ) {
			setIsPausing( false );
		} else {
			setIsResuming( false );
		}
	}, [ isPaused, isRunning ] );

	const startBackup = () => {
		if ( actionInFlightRef.current || isRunning || isMultisiteMainSite ) {
			return;
		}

		actionInFlightRef.current = 'start';
		setIsStarting( true );
		setActionError( '' );
		setIsDownloading( false );
		stopDownloadReconciliation();

		apiFetch( {
			path: '/sss-backup-migrate/v1/start-export',
			method: 'POST',
			data: { blog_id: blogId },
		} )
			.then( ( response ) => {
				assertSuccessfulResponse(
					response,
					__(
						'Could not start the backup.',
						'simply-static-studio-backup-migrate'
					)
				);
				if ( ! isMountedRef.current ) {
					return;
				}

				setBackup( '' );
				setBackupLink( '' );
				setBackupConsumed( false );
				setBackupDownloadStarted( false );
				setBackupIntegrity( { ...DEFAULT_BACKUP_INTEGRITY } );
				setIsRunning( true );
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setActionError(
						getErrorMessage(
							error,
							__(
								'Could not start the backup.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
					// A dropped response can hide a backup that did start server-side.
					checkIfRunning();
				}
			} )
			.finally( () => {
				if ( isMountedRef.current ) {
					actionInFlightRef.current = '';
					setIsStarting( false );
				}
			} );
	};

	const cancelBackup = () => {
		if ( actionInFlightRef.current || ! isRunning ) {
			return;
		}

		actionInFlightRef.current = 'cancel';
		setIsCancelling( true );
		setActionError( '' );

		apiFetch( {
			path: '/sss-backup-migrate/v1/cancel-export',
			method: 'POST',
			data: { blog_id: blogId },
		} )
			.then( ( response ) => {
				assertSuccessfulResponse(
					response,
					__(
						'Could not cancel the backup.',
						'simply-static-studio-backup-migrate'
					)
				);
				if ( ! isMountedRef.current ) {
					return;
				}

				// Reconcile immediately, then let the existing running-state interval
				// continue until the backend has finished cancellation and restored any
				// previously completed archive.
				checkIfRunning();
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setActionError(
						getErrorMessage(
							error,
							__(
								'Could not cancel the backup.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
					setIsCancelling( false );
					checkIfRunning();
				}
			} )
			.finally( () => {
				if ( isMountedRef.current ) {
					actionInFlightRef.current = '';
				}
			} );
	};

	const changePauseState = ( shouldPause ) => {
		if (
			actionInFlightRef.current ||
			! isRunning ||
			isPaused === shouldPause
		) {
			return;
		}

		const action = shouldPause ? 'pause' : 'resume';
		const fallback = shouldPause
			? __(
					'Could not pause the backup.',
					'simply-static-studio-backup-migrate'
			  )
			: __(
					'Could not resume the backup.',
					'simply-static-studio-backup-migrate'
			  );
		actionInFlightRef.current = action;
		setActionError( '' );
		if ( shouldPause ) {
			setIsPausing( true );
		} else {
			setIsResuming( true );
		}

		apiFetch( {
			path: `/sss-backup-migrate/v1/${ action }-export`,
			method: 'POST',
			data: { blog_id: blogId },
		} )
			.then( ( response ) => {
				assertSuccessfulResponse( response, fallback );
				if ( isMountedRef.current ) {
					checkIfRunning();
				}
			} )
			.catch( ( error ) => {
				if ( isMountedRef.current ) {
					setActionError( getErrorMessage( error, fallback ) );
					setIsPausing( false );
					setIsResuming( false );
					checkIfRunning();
				}
			} )
			.finally( () => {
				if ( isMountedRef.current ) {
					actionInFlightRef.current = '';
				}
			} );
	};

	const handleDownload = ( event ) => {
		if ( downloadInFlightRef.current ) {
			event?.preventDefault?.();
			return;
		}

		stopDownloadReconciliation();
		downloadInFlightRef.current = true;
		setIsDownloading( true );
		downloadResetTimer.current = setTimeout( () => {
			if ( isMountedRef.current ) {
				setIsDownloading( false );
				// Static handoffs complete immediately, while the PHP fallback can
				// stream for minutes. Poll the durable marker in the background so a
				// later completion is not missed, but retain failed/partial downloads.
				reconcileDownloadStatus();
			}
			downloadInFlightRef.current = false;
			downloadResetTimer.current = null;
		}, DOWNLOAD_UI_RESET_DELAY_MS );
	};

	let actions;

	if ( ! backup && ! isRunning && ! isStarting ) {
		actions = (
			<div className="backup-actions">
				<Button
					onClick={ startBackup }
					variant="primary"
					className="sss-generate"
					disabled={ isMultisiteMainSite }
				>
					{ __(
						'Backup Site',
						'simply-static-studio-backup-migrate'
					) }
				</Button>
				{ isMultisiteMainSite && (
					<p className="sss-multisite-warning" role="status">
						{ __(
							'Multisite backups are not supported. Please backup subsites individually.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
				) }
			</div>
		);
	} else if ( isStarting ) {
		actions = (
			<div className="backup-actions">
				<Button
					disabled
					variant="primary"
					className="sss-generate sss-generate-loading"
				>
					<Spinner />
					{ __(
						'Starting backup…',
						'simply-static-studio-backup-migrate'
					) }
				</Button>
			</div>
		);
	} else if ( isRunning ) {
		actions = (
			<>
				<div className="backup-actions">
					<Button
						onClick={ () => changePauseState( ! isPaused ) }
						disabled={ backupActionPending }
						variant="secondary"
						className="sss-pause-button"
					>
						{ ( isPausing || isResuming ) && <Spinner /> }
						{ pauseActionLabel }
					</Button>
					<Button
						onClick={ cancelBackup }
						disabled={ backupActionPending }
						variant="secondary"
						isDestructive
						className={ `sss-cancel-button${
							isCancelling ? ' sss-cancel-loading' : ''
						}` }
					>
						{ isCancelling && <Spinner /> }
						{ isCancelling
							? __(
									'Cancelling…',
									'simply-static-studio-backup-migrate'
							  )
							: __(
									'Cancel',
									'simply-static-studio-backup-migrate'
							  ) }
					</Button>
				</div>
				{ isPaused && (
					<p className="sss-backup-paused" role="status">
						{ __(
							'Backup paused. Resume it to continue.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
				) }
			</>
		);
	} else {
		actions = (
			<>
				<div className="backup-actions backup-actions-complete">
					{ safeBackupUrl && (
						<Button
							href={ safeBackupUrl }
							variant="primary"
							className="sss-download-button"
							download
							onClick={ handleDownload }
							aria-disabled={ isDownloading }
						>
							{ downloadActionLabel }
						</Button>
					) }
					<Button
						onClick={ startBackup }
						variant="secondary"
						className="sss-new-backup-button"
						disabled={ isMultisiteMainSite }
					>
						{ __(
							'New Backup',
							'simply-static-studio-backup-migrate'
						) }
					</Button>
				</div>
				{ backupDownloadStarted && (
					<p className="sss-backup-download-started" role="status">
						{ __(
							'The backup download was started. Create a new backup to include later site changes, or download this snapshot again if the transfer did not finish.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
				) }
				{ backupWarning && (
					<p className="sss-backup-warning" role="status">
						{ backupWarning }
					</p>
				) }
				{ isMultisiteMainSite && (
					<p className="sss-multisite-warning" role="status">
						{ __(
							'Multisite backups are not supported. Please backup subsites individually.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
				) }
			</>
		);
	}

	return (
		<>
			{ actions }
			{ ( actionError || unsafeBackupError ) && (
				<p className="backup-action-error" role="alert">
					{ actionError || unsafeBackupError }
				</p>
			) }
			<span className="screen-reader-text" aria-live="polite">
				{ isDownloading
					? __(
							'Backup download started.',
							'simply-static-studio-backup-migrate'
					  )
					: '' }
			</span>
		</>
	);
}

export default BackupActions;
