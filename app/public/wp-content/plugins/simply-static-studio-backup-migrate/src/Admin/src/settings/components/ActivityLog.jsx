import {
	useCallback,
	useContext,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { SettingsContext } from '../context/Context';
import apiFetch from '@wordpress/api-fetch';
import useInterval from '../../hooks/useInterval';
import {
	assertSuccessfulResponse,
	getErrorMessage,
	sanitizeHttpUrl,
} from '../../utils/api';

import { __ } from '@wordpress/i18n';

export function clampProgress( value ) {
	const number = Number( value );
	if ( ! Number.isFinite( number ) ) {
		return 0;
	}
	return Math.min( 100, Math.max( 0, Math.round( number ) ) );
}

function ActivityLog( { onDebugLogUrl } ) {
	const { isRunning, blogId, backup, backupConsumed, backupDownloadStarted } =
		useContext( SettingsContext );
	const [ progress, setProgress ] = useState(
		backup && ! backupDownloadStarted ? 100 : 0
	);
	const [ taskProgress, setTaskProgress ] = useState( null );
	const [ currentAction, setCurrentAction ] = useState( '' );
	const [ activityError, setActivityError ] = useState( '' );
	const requestIdRef = useRef( 0 );
	const inFlightRequestRef = useRef( null );
	const callbackRef = useRef( onDebugLogUrl );

	useEffect( () => {
		callbackRef.current = onDebugLogUrl;
	}, [ onDebugLogUrl ] );

	useEffect(
		() => () => {
			requestIdRef.current += 1;
			inFlightRequestRef.current = null;
		},
		[]
	);

	const refreshActivityLog = useCallback( () => {
		if ( inFlightRequestRef.current ) {
			return inFlightRequestRef.current;
		}

		const requestId = ++requestIdRef.current;
		const request = apiFetch( {
			path: `/sss-backup-migrate/v1/activity-log?blog_id=${ encodeURIComponent(
				blogId
			) }`,
			method: 'GET',
		} )
			.then( ( response ) => {
				if ( requestId !== requestIdRef.current ) {
					return;
				}
				const json = assertSuccessfulResponse(
					response,
					__(
						'Could not refresh backup progress.',
						'simply-static-studio-backup-migrate'
					)
				);

				setProgress( clampProgress( json.progress ) );
				setTaskProgress(
					json.task_progress === null ||
						json.task_progress === undefined
						? null
						: clampProgress( json.task_progress )
				);
				setCurrentAction(
					typeof json.current_action === 'string'
						? json.current_action
						: ''
				);
				setActivityError( '' );

				const debugLogUrl = sanitizeHttpUrl( json.debug_log_url );
				if ( debugLogUrl && callbackRef.current ) {
					callbackRef.current( debugLogUrl );
				}
			} )
			.catch( ( error ) => {
				if ( requestId === requestIdRef.current ) {
					setActivityError(
						getErrorMessage(
							error,
							__(
								'Could not refresh backup progress.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} )
			.finally( () => {
				if ( inFlightRequestRef.current === request ) {
					inFlightRequestRef.current = null;
				}
			} );

		inFlightRequestRef.current = request;
		return request;
	}, [ blogId ] );

	useInterval( refreshActivityLog, isRunning ? 2500 : null );

	useEffect( () => {
		if ( isRunning ) {
			setProgress( 0 );
			setTaskProgress( null );
			setCurrentAction(
				__( 'Starting backup…', 'simply-static-studio-backup-migrate' )
			);
			refreshActivityLog();
		} else {
			// Ignore a request that started for the previous running state.
			requestIdRef.current += 1;
			inFlightRequestRef.current = null;
			setActivityError( '' );
		}
	}, [ isRunning, refreshActivityLog ] );

	// A confirmed transfer removes its URL, while a static-server handoff remains
	// retryable. Both clear the old 100% generation progress so the page does not
	// imply that later WordPress changes were included in the same snapshot.
	useEffect( () => {
		if ( ! isRunning ) {
			setProgress( backup && ! backupDownloadStarted ? 100 : 0 );
			if ( ! backup || backupDownloadStarted ) {
				setTaskProgress( null );
				setCurrentAction( '' );
			}
		}
	}, [ isRunning, backup, backupDownloadStarted ] );

	const isComplete = Boolean(
		! isRunning && progress === 100 && backup && ! backupDownloadStarted
	);

	// Remove duplicate task percentages from the source action before adding the
	// current-task progress once in the description below the overall bar.
	const cleanAction = currentAction
		? currentAction
				.replace( /<br\s*\/?>/gi, ' ' )
				.replace( /\d+%\s*complete/gi, '' )
				.replace( /\s*\(\d+%\)\s*/g, ' ' )
				.replace( /\d+\s*records?\s*saved/gi, '' )
				.replace( /\.{2,}/g, '…' )
				.replace( /\s{2,}/g, ' ' )
				.replace( /\s+([.…,;:!?])/g, '$1' )
				.trim()
		: '';

	const baseActionText = isComplete
		? `✓ ${ __(
				'Backup complete — ready to download.',
				'simply-static-studio-backup-migrate'
		  ) }`
		: cleanAction;
	const taskProgressText =
		isRunning && taskProgress !== null
			? `${ taskProgress }% ${ __(
					'of this step',
					'simply-static-studio-backup-migrate'
			  ) }`
			: '';
	const actionText =
		baseActionText && taskProgressText
			? `${ baseActionText } — ${ taskProgressText }`
			: baseActionText || taskProgressText;
	const accessibleProgress = actionText
		? `${ progress }%. ${ actionText }`
		: `${ progress }%.`;

	const labelContent = (
		<span className="backup-progress-pct">{ progress }%</span>
	);

	return (
		<div className="backup-progress-wrapper">
			<div
				className="backup-progress-bar-container"
				role="progressbar"
				aria-label={ __(
					'Backup progress',
					'simply-static-studio-backup-migrate'
				) }
				aria-valuemin="0"
				aria-valuemax="100"
				aria-valuenow={ progress }
				aria-valuetext={ accessibleProgress }
			>
				<div
					className="backup-progress-bar-fill"
					style={ { width: `${ progress }%` } }
					aria-hidden="true"
				/>
				<span
					className="backup-progress-bar-label backup-progress-bar-label--dark"
					aria-hidden="true"
				>
					{ labelContent }
				</span>
				<span
					className="backup-progress-bar-label backup-progress-bar-label--light"
					style={ { clipPath: `inset(0 ${ 100 - progress }% 0 0)` } }
					aria-hidden="true"
				>
					{ labelContent }
				</span>
			</div>
			{ actionText && (
				<p className="backup-progress-description">{ actionText }</p>
			) }
			{ ! isRunning && ! backup && backupConsumed && (
				<p className="backup-progress-description" role="status">
					{ __(
						'The previous backup was transferred. Create a new backup to include any changes made since then.',
						'simply-static-studio-backup-migrate'
					) }
				</p>
			) }
			{ activityError && (
				<p className="backup-progress-error" role="status">
					{ activityError }
				</p>
			) }
		</div>
	);
}

export default ActivityLog;
