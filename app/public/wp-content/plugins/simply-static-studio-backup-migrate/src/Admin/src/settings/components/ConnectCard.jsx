import {
	useCallback,
	useContext,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import {
	Button,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { DEFAULT_BACKUP_INTEGRITY, SettingsContext } from '../context/Context';
import apiFetch from '@wordpress/api-fetch';
import {
	assertSuccessfulResponse,
	getErrorMessage,
	getErrorStatus,
	sanitizeHttpUrl,
} from '../../utils/api';

import { __ } from '@wordpress/i18n';

export function isValidEmail( value ) {
	return (
		typeof value === 'string' &&
		/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value.trim() )
	);
}

const MAX_STUDIO_SITES = 1000;
const MAX_STUDIO_SITE_ID_LENGTH = 200;
const MAX_STUDIO_SITE_NAME_LENGTH = 500;
const MAX_STUDIO_SITE_URL_LENGTH = 2048;
const STUDIO_DOCKER_HOSTING_PROVIDER = 'static-studio-docker';
const DEFAULT_STUDIO_PHP_VERSION = '8.3';
const STUDIO_AUTH_BRIDGE_ORIGIN = 'https://static.studio';
const STUDIO_AUTH_BRIDGE_PATH = '/plugin-auth.html';
const STUDIO_AUTH_MESSAGE = 'static-studio-plugin-auth';
const OTP_REQUEST_TIMEOUT_MS = 40000;
const SECURITY_CHECK_TIMEOUT_MS = 120000;
const DEFAULT_STUDIO_PHP_VERSIONS = Object.freeze( [
	'7.4',
	'8.1',
	'8.2',
	'8.3',
	'8.4',
] );

export function normalizeStudioPhpVersions( value ) {
	const versions = Array.isArray( value )
		? value.filter(
				( version, index ) =>
					typeof version === 'string' &&
					/^\d{1,2}\.\d{1,2}$/.test( version ) &&
					value.indexOf( version ) === index
		  )
		: [];

	return versions.length ? versions : [ ...DEFAULT_STUDIO_PHP_VERSIONS ];
}

export function getClosestStudioPhpVersion( sourceVersion, supportedVersions ) {
	const versions = normalizeStudioPhpVersions( supportedVersions );
	const fallback = versions.includes( DEFAULT_STUDIO_PHP_VERSION )
		? DEFAULT_STUDIO_PHP_VERSION
		: versions[ versions.length - 1 ];
	const match =
		typeof sourceVersion === 'string' &&
		sourceVersion.match( /^(\d{1,2})\.(\d{1,2})/ );

	if ( ! match ) {
		return fallback;
	}

	const sourceId = Number( match[ 1 ] ) * 100 + Number( match[ 2 ] );
	return versions.reduce(
		( closest, version ) => {
			const [ major, minor ] = version.split( '.' ).map( Number );
			const distance = Math.abs( sourceId - ( major * 100 + minor ) );
			return distance < closest.distance
				? { version, distance }
				: closest;
		},
		{ version: fallback, distance: Number.POSITIVE_INFINITY }
	).version;
}

export function normalizeStudioSites( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	return value.slice( 0, MAX_STUDIO_SITES ).reduce( ( sites, site ) => {
		if ( ! site || typeof site !== 'object' || Array.isArray( site ) ) {
			return sites;
		}

		const rawId = site.id;
		const id = typeof rawId === 'string' ? rawId.trim() : rawId;
		if (
			! (
				( typeof id === 'string' &&
					id &&
					id.length <= MAX_STUDIO_SITE_ID_LENGTH ) ||
				( Number.isSafeInteger( id ) && id > 0 )
			)
		) {
			return sites;
		}
		if ( site.hosting_provider !== STUDIO_DOCKER_HOSTING_PROVIDER ) {
			return sites;
		}

		sites.push( {
			id,
			name:
				typeof site.name === 'string'
					? site.name.trim().slice( 0, MAX_STUDIO_SITE_NAME_LENGTH )
					: '',
			url:
				typeof site.url === 'string'
					? site.url.trim().slice( 0, MAX_STUDIO_SITE_URL_LENGTH )
					: '',
			hosting_provider: STUDIO_DOCKER_HOSTING_PROVIDER,
		} );
		return sites;
	}, [] );
}

export function normalizeStudioAuthBridgeUrl( value ) {
	try {
		const url = new URL( value );
		if (
			url.origin !== STUDIO_AUTH_BRIDGE_ORIGIN ||
			url.pathname !== STUDIO_AUTH_BRIDGE_PATH ||
			url.username ||
			url.password
		) {
			return '';
		}
		return url.href;
	} catch {
		return '';
	}
}

function ConnectCard() {
	const localizedOptions = window.sssbm_backup_migrate_options || {};
	const [ studioPhpVersions ] = useState( () =>
		normalizeStudioPhpVersions( localizedOptions.studio_php_versions )
	);
	const [ selectedPhpVersion, setSelectedPhpVersion ] = useState( () =>
		getClosestStudioPhpVersion(
			localizedOptions.current_php_version,
			localizedOptions.studio_php_versions
		)
	);
	const {
		studioConnected,
		studioEmail,
		setStudioConnected,
		setStudioEmail,
		hardyPressRecognized,
		setHardyPressRecognized,
		connectionError,
		setConnectionError,
		backup,
		setBackup,
		setBackupLink,
		setBackupConsumed,
		backupIntegrity,
		setBackupIntegrity,
		checkIfRunning,
		isRunning,
		lastRunOutcome,
	} = useContext( SettingsContext );

	const [ email, setEmail ] = useState( '' );
	const [ otpCode, setOtpCode ] = useState( '' );
	const [ otpSent, setOtpSent ] = useState( false );
	const [ isSendingOtp, setIsSendingOtp ] = useState( false );
	const [ isVerifying, setIsVerifying ] = useState( false );
	const [ isDisconnecting, setIsDisconnecting ] = useState( false );
	const [ isPushing, setIsPushing ] = useState( false );
	const [ studioSites, setStudioSites ] = useState( [] );
	const [ selectedSiteId, setSelectedSiteId ] = useState( '' );
	const [ isLoadingSites, setIsLoadingSites ] = useState( false );
	const [ sitesLoaded, setSitesLoaded ] = useState( false );
	const [ sitesError, setSitesError ] = useState( '' );
	const [ sitesRequestNonce, setSitesRequestNonce ] = useState( 0 );
	const [ error, setError ] = useState( '' );
	const [ pushResult, setPushResult ] = useState( null );
	const [ captchaVerified, setCaptchaVerified ] = useState( false );
	const [ captchaBridgeReady, setCaptchaBridgeReady ] = useState( false );
	const [ studioConnectionEnabled, setStudioConnectionEnabled ] =
		useState( false );

	const wasRunningRef = useRef( isRunning );
	const isMountedRef = useRef( true );
	const captchaFrameRef = useRef( null );
	const captchaRequestRef = useRef( null );
	const pendingOtpEmailRef = useRef( '' );
	const captchaTimeoutRef = useRef( null );
	const otpRequestRef = useRef( false );
	const verifyRequestRef = useRef( false );
	const disconnectRequestRef = useRef( false );
	const pushRequestRef = useRef( false );
	const sitesRequestIdRef = useRef( 0 );

	const backupCanPushToStudio =
		backupIntegrity?.can_push_to_studio !== false &&
		backupIntegrity?.is_pushable !== false;
	const backupPushWarning = backupIntegrity?.warning || '';
	const selectedSite = studioSites.find(
		( site ) => String( site.id ) === String( selectedSiteId )
	);
	const safeResultUrl = sanitizeHttpUrl( pushResult?.siteUrl );
	const displayedError = error || connectionError;
	const studioAuthBridgeUrl = normalizeStudioAuthBridgeUrl(
		localizedOptions.studio_auth_bridge_url
	);
	const studioAuthBridgeOrigin = studioAuthBridgeUrl
		? new URL( studioAuthBridgeUrl ).origin
		: '';

	useEffect( () => {
		isMountedRef.current = true;
		return () => {
			isMountedRef.current = false;
			pendingOtpEmailRef.current = '';
			captchaRequestRef.current = null;
			otpRequestRef.current = false;
			sitesRequestIdRef.current += 1;
			if ( captchaTimeoutRef.current ) {
				clearTimeout( captchaTimeoutRef.current );
			}
		};
	}, [] );

	// A success message belongs to one completed archive generation. Clear it as
	// soon as a new backup starts so the next generation can auto-push and the
	// manual action is not hidden behind stale UI state.
	useEffect( () => {
		if ( isRunning ) {
			setPushResult( null );
			setError( '' );
		}
	}, [ isRunning ] );

	const finishOtpRequest = useCallback( () => {
		if ( captchaTimeoutRef.current ) {
			clearTimeout( captchaTimeoutRef.current );
			captchaTimeoutRef.current = null;
		}
		pendingOtpEmailRef.current = '';
		captchaRequestRef.current = null;
		otpRequestRef.current = false;
		if ( isMountedRef.current ) {
			setIsSendingOtp( false );
		}
	}, [] );

	const failOtpRequest = useCallback(
		( message ) => {
			finishOtpRequest();
			if ( isMountedRef.current ) {
				setCaptchaBridgeReady( false );
				setCaptchaVerified( false );
				setStudioConnectionEnabled( false );
				setError( message );
			}
		},
		[ finishOtpRequest ]
	);

	const startOtpTimeout = useCallback(
		( timeoutMs ) => {
			if ( captchaTimeoutRef.current ) {
				clearTimeout( captchaTimeoutRef.current );
			}
			captchaTimeoutRef.current = setTimeout( () => {
				failOtpRequest(
					__(
						'The verification request timed out. Please try again.',
						'simply-static-studio-backup-migrate'
					)
				);
			}, timeoutMs );
		},
		[ failOtpRequest ]
	);

	useEffect( () => {
		if ( ! studioConnectionEnabled || ! studioAuthBridgeOrigin ) {
			return undefined;
		}

		const postToBridge = ( message ) => {
			captchaFrameRef.current?.contentWindow?.postMessage(
				{ channel: STUDIO_AUTH_MESSAGE, ...message },
				studioAuthBridgeOrigin
			);
		};
		const handleBridgeMessage = ( event ) => {
			if (
				! otpRequestRef.current ||
				event.origin !== studioAuthBridgeOrigin ||
				event.source !== captchaFrameRef.current?.contentWindow ||
				event.data?.channel !== STUDIO_AUTH_MESSAGE
			) {
				return;
			}

			switch ( event.data.type ) {
				case 'bridge-loaded':
					postToBridge( { type: 'initialize' } );
					break;
				case 'ready':
					setCaptchaBridgeReady( true );
					break;
				case 'captcha-verified':
					setCaptchaVerified( true );
					setError( '' );
					break;
				case 'captcha-expired':
					setCaptchaVerified( false );
					break;
				case 'captcha-error':
					failOtpRequest(
						__(
							'Could not load the security check. Please try again.',
							'simply-static-studio-backup-migrate'
						)
					);
					break;
				case 'otp-sent':
					if (
						captchaRequestRef.current &&
						event.data.requestId === captchaRequestRef.current
					) {
						finishOtpRequest();
						setOtpSent( true );
					}
					break;
				case 'otp-error':
					if (
						captchaRequestRef.current &&
						event.data.requestId === captchaRequestRef.current
					) {
						failOtpRequest(
							typeof event.data.message === 'string' &&
								event.data.message
								? event.data.message
								: __(
										'Failed to send verification code.',
										'simply-static-studio-backup-migrate'
								  )
						);
					}
					break;
				default:
					break;
			}
		};

		window.addEventListener( 'message', handleBridgeMessage );
		return () =>
			window.removeEventListener( 'message', handleBridgeMessage );
	}, [
		studioConnectionEnabled,
		studioAuthBridgeOrigin,
		finishOtpRequest,
		failOtpRequest,
	] );

	useEffect( () => {
		if ( ! studioConnected ) {
			sitesRequestIdRef.current += 1;
			setStudioSites( [] );
			setSelectedSiteId( '' );
			setIsLoadingSites( false );
			setSitesLoaded( false );
			setSitesError( '' );
			return undefined;
		}

		const requestId = ++sitesRequestIdRef.current;
		setIsLoadingSites( true );
		setSitesLoaded( false );
		setSitesError( '' );

		apiFetch( {
			path: '/sss-backup-migrate/v1/studio-sites',
			method: 'GET',
		} )
			.then( ( response ) => {
				if (
					! isMountedRef.current ||
					requestId !== sitesRequestIdRef.current
				) {
					return;
				}
				const json = assertSuccessfulResponse(
					response,
					__(
						'Could not load your Studio sites.',
						'simply-static-studio-backup-migrate'
					)
				);

				const sites = normalizeStudioSites( json.sites );
				if ( json.hardypress_recognized === true ) {
					setHardyPressRecognized( true );
				}
				const linkedSiteId =
					typeof json.linked_site_id === 'string' ||
					Number.isSafeInteger( json.linked_site_id )
						? String( json.linked_site_id ).trim()
						: '';
				const availableLinkedSiteId = sites.some(
					( site ) => String( site.id ) === linkedSiteId
				)
					? linkedSiteId
					: '';
				setStudioSites( sites );
				setSitesLoaded( true );
				setSelectedSiteId( ( current ) =>
					current &&
					sites.some(
						( site ) => String( site.id ) === String( current )
					)
						? current
						: availableLinkedSiteId
				);
			} )
			.catch( ( requestError ) => {
				if (
					! isMountedRef.current ||
					requestId !== sitesRequestIdRef.current
				) {
					return;
				}

				const message = getErrorMessage(
					requestError,
					__(
						'Could not load your Studio sites.',
						'simply-static-studio-backup-migrate'
					)
				);
				setSitesError( message );

				// An expired/invalid Studio token should return the UI to the login
				// flow instead of leaving the user trapped in a connected state.
				if ( getErrorStatus( requestError ) === 401 ) {
					setStudioConnected( false );
					setStudioEmail( '' );
					setHardyPressRecognized( false );
					setError( message );
				}
			} )
			.finally( () => {
				if (
					isMountedRef.current &&
					requestId === sitesRequestIdRef.current
				) {
					setIsLoadingSites( false );
				}
			} );

		return () => {
			if ( requestId === sitesRequestIdRef.current ) {
				sitesRequestIdRef.current += 1;
			}
		};
	}, [
		setStudioConnected,
		setStudioEmail,
		setHardyPressRecognized,
		sitesRequestNonce,
		studioConnected,
	] );

	const retryLoadingSites = () => {
		if ( isLoadingSites ) {
			return;
		}
		setIsLoadingSites( true );
		setSitesLoaded( false );
		setSitesError( '' );
		setSitesRequestNonce( ( current ) => current + 1 );
	};

	const handleSendOtp = ( event ) => {
		event?.preventDefault();
		if ( otpRequestRef.current ) {
			return;
		}

		const normalizedEmail = email.trim();
		if ( ! isValidEmail( normalizedEmail ) ) {
			setError(
				__(
					'Please enter a valid email address.',
					'simply-static-studio-backup-migrate'
				)
			);
			return;
		}

		otpRequestRef.current = true;
		setEmail( normalizedEmail );
		setIsSendingOtp( true );
		setError( '' );
		setConnectionError( '' );

		if ( ! studioAuthBridgeUrl ) {
			failOtpRequest(
				__(
					'Could not load the security check. Please try again.',
					'simply-static-studio-backup-migrate'
				)
			);
			return;
		}

		pendingOtpEmailRef.current = normalizedEmail;
		setStudioConnectionEnabled( true );
		// Give the user time to complete an interactive challenge, while still
		// recovering if the hosted check never responds.
		startOtpTimeout( SECURITY_CHECK_TIMEOUT_MS );
	};

	useEffect( () => {
		if (
			! isSendingOtp ||
			! captchaBridgeReady ||
			! captchaVerified ||
			! pendingOtpEmailRef.current
		) {
			return;
		}

		const requestedEmail = pendingOtpEmailRef.current;
		pendingOtpEmailRef.current = '';
		const requestId = `${ Date.now() }-${ Math.random()
			.toString( 36 )
			.slice( 2 ) }`;
		captchaRequestRef.current = requestId;
		setCaptchaVerified( false );
		startOtpTimeout( OTP_REQUEST_TIMEOUT_MS );
		try {
			captchaFrameRef.current?.contentWindow?.postMessage(
				{
					channel: STUDIO_AUTH_MESSAGE,
					type: 'request-otp',
					requestId,
					email: requestedEmail,
				},
				studioAuthBridgeOrigin
			);
		} catch {
			failOtpRequest(
				__(
					'Failed to send verification code.',
					'simply-static-studio-backup-migrate'
				)
			);
		}
	}, [
		isSendingOtp,
		captchaBridgeReady,
		captchaVerified,
		studioAuthBridgeOrigin,
		failOtpRequest,
		startOtpTimeout,
	] );

	const handleVerifyOtp = ( event ) => {
		event?.preventDefault();
		if ( verifyRequestRef.current ) {
			return;
		}

		const normalizedCode = otpCode.trim();
		if ( ! normalizedCode ) {
			setError(
				__(
					'Please enter the verification code.',
					'simply-static-studio-backup-migrate'
				)
			);
			return;
		}

		verifyRequestRef.current = true;
		setOtpCode( normalizedCode );
		setIsVerifying( true );
		setError( '' );

		apiFetch( {
			path: '/sss-backup-migrate/v1/verify-otp',
			method: 'POST',
			data: { email, token: normalizedCode },
		} )
			.then( ( response ) => {
				const json = assertSuccessfulResponse(
					response,
					__(
						'Verification failed.',
						'simply-static-studio-backup-migrate'
					)
				);
				if ( ! json.connected ) {
					throw new Error(
						json.error ||
							__(
								'Verification failed.',
								'simply-static-studio-backup-migrate'
							)
					);
				}
				if ( ! isMountedRef.current ) {
					return;
				}

				setStudioConnected( true );
				setStudioEmail(
					typeof json.email === 'string' ? json.email : email
				);
				setHardyPressRecognized( json.hardypress_recognized === true );
				setEmail( '' );
				setOtpCode( '' );
				setOtpSent( false );
				setConnectionError( '' );
			} )
			.catch( ( requestError ) => {
				if ( isMountedRef.current ) {
					setError(
						getErrorMessage(
							requestError,
							__(
								'Verification failed.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} )
			.finally( () => {
				verifyRequestRef.current = false;
				if ( isMountedRef.current ) {
					setIsVerifying( false );
				}
			} );
	};

	const handleDisconnect = () => {
		if ( disconnectRequestRef.current || pushRequestRef.current ) {
			return;
		}

		disconnectRequestRef.current = true;
		setIsDisconnecting( true );
		setError( '' );

		apiFetch( {
			path: '/sss-backup-migrate/v1/disconnect',
			method: 'POST',
		} )
			.then( ( response ) => {
				assertSuccessfulResponse(
					response,
					__(
						'Could not disconnect from Studio.',
						'simply-static-studio-backup-migrate'
					)
				);
				if ( ! isMountedRef.current ) {
					return;
				}

				setStudioConnected( false );
				setStudioEmail( '' );
				setHardyPressRecognized( false );
				setPushResult( null );
				setConnectionError( '' );
			} )
			.catch( ( requestError ) => {
				if ( isMountedRef.current ) {
					setError(
						getErrorMessage(
							requestError,
							__(
								'Could not disconnect from Studio.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
				}
			} )
			.finally( () => {
				disconnectRequestRef.current = false;
				if ( isMountedRef.current ) {
					setIsDisconnecting( false );
				}
			} );
	};

	const handleSelectedSiteChange = ( siteId ) => {
		setSelectedSiteId( siteId );
		setPushResult( null );
		setError( '' );
	};

	const handlePhpVersionChange = ( phpVersion ) => {
		if ( ! studioPhpVersions.includes( phpVersion ) ) {
			return;
		}
		setSelectedPhpVersion( phpVersion );
		setPushResult( null );
		setError( '' );
	};

	const handlePush = useCallback( () => {
		if ( pushRequestRef.current || disconnectRequestRef.current ) {
			return;
		}
		if ( ! sitesLoaded || isLoadingSites || sitesError ) {
			setError(
				sitesError ||
					__(
						'Wait for your Studio sites to finish loading.',
						'simply-static-studio-backup-migrate'
					)
			);
			return;
		}
		if ( ! backupCanPushToStudio ) {
			setError(
				backupPushWarning ||
					__(
						'This backup cannot be pushed to Studio.',
						'simply-static-studio-backup-migrate'
					)
			);
			return;
		}
		if (
			selectedSiteId &&
			! studioSites.some(
				( site ) => String( site.id ) === String( selectedSiteId )
			)
		) {
			setError(
				__(
					'The selected Studio site is no longer available. Please choose another target.',
					'simply-static-studio-backup-migrate'
				)
			);
			return;
		}

		pushRequestRef.current = true;
		setIsPushing( true );
		setError( '' );
		setPushResult( null );

		apiFetch( {
			path: '/sss-backup-migrate/v1/push-to-studio',
			method: 'POST',
			data: selectedSiteId
				? { site_id: selectedSiteId }
				: { php_version: selectedPhpVersion },
		} )
			.then( ( response ) => {
				const json = assertSuccessfulResponse(
					response,
					__( 'Push failed.', 'simply-static-studio-backup-migrate' )
				);
				if ( isMountedRef.current ) {
					if ( json.backupConsumed === true ) {
						setBackup( '' );
						setBackupLink( '' );
						setBackupConsumed( true );
						setBackupIntegrity( { ...DEFAULT_BACKUP_INTEGRITY } );
					}
					setPushResult( {
						success: true,
						message:
							typeof json.message === 'string'
								? json.message
								: __(
										'Backup pushed to Studio.',
										'simply-static-studio-backup-migrate'
								  ),
						siteUrl:
							typeof json.siteUrl === 'string'
								? json.siteUrl
								: '',
					} );
					checkIfRunning();
				}
			} )
			.catch( ( requestError ) => {
				if ( isMountedRef.current ) {
					setError(
						getErrorMessage(
							requestError,
							__(
								'Push failed.',
								'simply-static-studio-backup-migrate'
							)
						)
					);
					// Init creates the Studio site before the archive transfer starts.
					// Reload targets after any failure so a partially failed first push
					// immediately selects that site for the user's next attempt.
					setSitesLoaded( false );
					setSitesRequestNonce( ( current ) => current + 1 );
				}
			} )
			.finally( () => {
				pushRequestRef.current = false;
				if ( isMountedRef.current ) {
					setIsPushing( false );
				}
			} );
	}, [
		backupCanPushToStudio,
		backupPushWarning,
		checkIfRunning,
		isLoadingSites,
		selectedPhpVersion,
		selectedSiteId,
		setBackup,
		setBackupConsumed,
		setBackupIntegrity,
		setBackupLink,
		sitesError,
		sitesLoaded,
		studioSites,
	] );

	const handleBackToEmail = () => {
		if ( isVerifying ) {
			return;
		}
		setOtpSent( false );
		setOtpCode( '' );
		setError( '' );
		setCaptchaVerified( false );
		setCaptchaBridgeReady( false );
		setStudioConnectionEnabled( false );
	};

	// Auto-push once when a running backup transitions to complete. If Studio
	// sites are still loading, retain the transition until the target is known.
	useEffect( () => {
		if ( isRunning ) {
			wasRunningRef.current = true;
			return;
		}

		if ( ! wasRunningRef.current ) {
			return;
		}

		if ( isLoadingSites ) {
			return;
		}
		if ( sitesError ) {
			// A failed target lookup makes an automatic new-site deployment
			// ambiguous. Consume this transition and require an explicit push after
			// the user retries successfully.
			wasRunningRef.current = false;
			return;
		}
		if ( ! sitesLoaded ) {
			return;
		}

		wasRunningRef.current = false;
		if (
			lastRunOutcome === 'completed' &&
			backup &&
			studioConnected &&
			backupCanPushToStudio &&
			! pushResult?.success
		) {
			handlePush();
		}
	}, [
		backup,
		backupCanPushToStudio,
		handlePush,
		isLoadingSites,
		isRunning,
		lastRunOutcome,
		pushResult?.success,
		sitesError,
		sitesLoaded,
		studioConnected,
	] );

	if ( ! studioConnected ) {
		return (
			<div className="connect-card-content">
				<div className="connect-hero">
					<div className="connect-hero-icon" aria-hidden="true">
						<svg
							width="40"
							height="40"
							viewBox="0 0 250 250"
							xmlns="http://www.w3.org/2000/svg"
							focusable="false"
						>
							<g transform="matrix(2.76885,0,0,2.76885,9.35845,66.8542)">
								<path
									d="M49.845,26.088C48.122,28.565 45.136,30.18 41.765,30.18C37.951,30.18 34.652,28.099 33.065,25.072L25.878,35.398C29.883,39.35 35.52,41.811 41.765,41.811C49.474,41.811 56.255,38.061 60.185,32.385L82.6,0.189L67.877,0.189L49.845,26.088ZM33.685,15.912C35.408,13.434 38.395,11.82 41.765,11.82C45.58,11.82 48.878,13.901 50.465,16.927L57.652,6.602C53.648,2.65 48.011,0.189 41.765,0.189C34.056,0.189 27.274,3.939 23.345,9.614L0.93,41.811L15.653,41.811L33.685,15.912ZM41.765,16.367C44.471,16.367 46.664,18.441 46.664,21C46.664,23.559 44.471,25.633 41.765,25.633C39.06,25.633 36.867,23.559 36.867,21C36.867,18.441 39.06,16.367 41.765,16.367Z"
									style={ {
										fill: 'url(#connect-logo-grad)',
										fillRule: 'nonzero',
									} }
								/>
							</g>
							<defs>
								<linearGradient
									id="connect-logo-grad"
									x1="0"
									y1="0"
									x2="1"
									y2="0"
									gradientUnits="userSpaceOnUse"
									gradientTransform="matrix(-36.8384,49.5016,-49.5016,-36.8384,51.2888,-5.30671)"
								>
									<stop
										offset="0"
										style={ {
											stopColor: 'rgb(123,77,255)',
											stopOpacity: 1,
										} }
									/>
									<stop
										offset="1"
										style={ {
											stopColor: 'rgb(90,32,255)',
											stopOpacity: 1,
										} }
									/>
								</linearGradient>
							</defs>
						</svg>
					</div>
					<h4 className="connect-hero-title">
						{ __(
							'Push to Studio',
							'simply-static-studio-backup-migrate'
						) }
					</h4>
					<p className="connect-hero-subtitle">
						{ __(
							'Connect your account to send a backup.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
				</div>
				{ displayedError && (
					<p className="connect-error" role="alert">
						{ displayedError }
					</p>
				) }
				<div className="connect-form">
					{ ! otpSent ? (
						<form onSubmit={ handleSendOtp }>
							<TextControl
								type="email"
								label={ __(
									'Email address',
									'simply-static-studio-backup-migrate'
								) }
								placeholder={ __(
									'you@example.com',
									'simply-static-studio-backup-migrate'
								) }
								value={ email }
								onChange={ setEmail }
								className="connect-input"
								autoComplete="email"
								disabled={ isSendingOtp }
							/>
							{ studioConnectionEnabled &&
								studioAuthBridgeUrl && (
									<iframe
										ref={ captchaFrameRef }
										src={ studioAuthBridgeUrl }
										title={ __(
											'Static Studio security check',
											'simply-static-studio-backup-migrate'
										) }
										className="connect-captcha-frame"
										referrerPolicy="no-referrer"
										onErrorCapture={ () =>
											failOtpRequest(
												__(
													'Could not load the security check. Please try again.',
													'simply-static-studio-backup-migrate'
												)
											)
										}
										onLoad={ () => {
											captchaFrameRef.current?.contentWindow?.postMessage(
												{
													channel:
														STUDIO_AUTH_MESSAGE,
													type: 'initialize',
												},
												studioAuthBridgeOrigin
											);
										} }
									/>
								) }
							<Button
								type="submit"
								variant="primary"
								className="connect-button"
								disabled={ isSendingOtp }
							>
								{ isSendingOtp ? (
									<>
										<Spinner />
										{ __(
											'Sending…',
											'simply-static-studio-backup-migrate'
										) }
									</>
								) : (
									__(
										'Send Verification Code',
										'simply-static-studio-backup-migrate'
									)
								) }
							</Button>
						</form>
					) : (
						<form onSubmit={ handleVerifyOtp }>
							<p className="connect-otp-hint">
								{ __(
									'We sent a verification code to',
									'simply-static-studio-backup-migrate'
								) }{ ' ' }
								<strong>{ email }</strong>
							</p>
							<TextControl
								type="text"
								label={ __(
									'Verification code',
									'simply-static-studio-backup-migrate'
								) }
								placeholder={ __(
									'Enter verification code',
									'simply-static-studio-backup-migrate'
								) }
								value={ otpCode }
								onChange={ setOtpCode }
								className="connect-input"
								inputMode="numeric"
								autoComplete="one-time-code"
								disabled={ isVerifying }
							/>
							<div className="connect-otp-actions">
								<Button
									type="submit"
									variant="primary"
									className="connect-button"
									disabled={ isVerifying }
								>
									{ isVerifying ? (
										<>
											<Spinner />
											{ __(
												'Verifying…',
												'simply-static-studio-backup-migrate'
											) }
										</>
									) : (
										__(
											'Verify & Connect',
											'simply-static-studio-backup-migrate'
										)
									) }
								</Button>
								<Button
									type="button"
									onClick={ handleBackToEmail }
									variant="tertiary"
									className="connect-back-button"
									disabled={ isVerifying }
								>
									{ __(
										'← Back',
										'simply-static-studio-backup-migrate'
									) }
								</Button>
							</div>
						</form>
					) }
				</div>
				<p className="connect-service-disclosure">
					{ __(
						'New backups upload automatically while connected.',
						'simply-static-studio-backup-migrate'
					) }{ ' ' }
					<a
						href="https://simplystatic.com/terms-of-service/"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Terms', 'simply-static-studio-backup-migrate' ) }
					</a>
					{ ' · ' }
					<a
						href="https://simplystatic.com/data-privacy/"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'Privacy',
							'simply-static-studio-backup-migrate'
						) }
					</a>
				</p>
				<p className="connect-signup-hint">
					{ __(
						'Need an account?',
						'simply-static-studio-backup-migrate'
					) }{ ' ' }
					<a
						href="https://static.studio/"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'Sign up',
							'simply-static-studio-backup-migrate'
						) }
					</a>
				</p>
			</div>
		);
	}

	let connectedInfo = __(
		'Your backups will be automatically pushed to Simply Static Studio when completed.',
		'simply-static-studio-backup-migrate'
	);
	if ( backup && ! isRunning && ! backupCanPushToStudio ) {
		connectedInfo = __(
			'Auto-push is disabled for this backup.',
			'simply-static-studio-backup-migrate'
		);
	} else if ( selectedSite ) {
		connectedInfo = __(
			'Future pushes will overwrite the selected Studio site instead of creating another site.',
			'simply-static-studio-backup-migrate'
		);
	}

	return (
		<div className="connect-card-content connect-card-content--connected">
			<div className="connect-connected-banner" role="status">
				<span className="connect-status-dot" aria-hidden="true" />
				<span className="connect-status-text">
					{ __(
						'Connected as',
						'simply-static-studio-backup-migrate'
					) }{ ' ' }
					<strong>{ studioEmail }</strong>
				</span>
			</div>

			{ hardyPressRecognized && (
				<div className="connect-partner-notice" role="status">
					<strong>
						{ __(
							'HardyPress customer recognized',
							'simply-static-studio-backup-migrate'
						) }
					</strong>
					<p>
						{ __(
							'Your existing HardyPress pricing is reserved for you in Simply Static Studio.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
				</div>
			) }

			<div className="connect-site-selector">
				<SelectControl
					label={ __(
						'Deployment target',
						'simply-static-studio-backup-migrate'
					) }
					value={ selectedSiteId }
					options={ [
						{
							label: __(
								'Create a new site',
								'simply-static-studio-backup-migrate'
							),
							value: '',
						},
						...studioSites.map( ( site ) => ( {
							label: site.url
								? `${ site.name || site.url } — ${ site.url }`
								: site.name ||
								  `${ __(
										'Site',
										'simply-static-studio-backup-migrate'
								  ) } ${ site.id }`,
							value: String( site.id ),
						} ) ),
					] }
					onChange={ handleSelectedSiteChange }
					disabled={
						isLoadingSites ||
						! sitesLoaded ||
						Boolean( sitesError ) ||
						isDisconnecting ||
						isPushing ||
						isRunning
					}
					help={
						selectedSite
							? __(
									'The selected site will be overwritten with this backup. This does not use another site slot.',
									'simply-static-studio-backup-migrate'
							  )
							: __(
									'Creating a new site uses an additional site slot. After it is created, future pushes will overwrite it automatically.',
									'simply-static-studio-backup-migrate'
							  )
					}
				/>
				{ ! selectedSiteId && (
					<div className="connect-php-version-selector">
						<SelectControl
							label={ __(
								'PHP version',
								'simply-static-studio-backup-migrate'
							) }
							value={ selectedPhpVersion }
							options={ studioPhpVersions.map( ( version ) => ( {
								label: `PHP ${ version }`,
								value: version,
							} ) ) }
							onChange={ handlePhpVersionChange }
							disabled={
								isDisconnecting || isPushing || isRunning
							}
							help={ __(
								"Studio will use this PHP version for the imported WordPress site. The closest match to this site's current PHP version is selected automatically.",
								'simply-static-studio-backup-migrate'
							) }
						/>
					</div>
				) }
				{ isLoadingSites && (
					<div className="connect-sites-loading" role="status">
						<Spinner />
						<span>
							{ __(
								'Loading Studio sites…',
								'simply-static-studio-backup-migrate'
							) }
						</span>
					</div>
				) }
				{ sitesError && (
					<div className="connect-sites-error">
						<p role="alert">{ sitesError }</p>
						<Button
							type="button"
							variant="tertiary"
							size="small"
							onClick={ retryLoadingSites }
							disabled={ isLoadingSites }
						>
							{ __(
								'Retry loading sites',
								'simply-static-studio-backup-migrate'
							) }
						</Button>
					</div>
				) }
			</div>

			{ displayedError && (
				<p className="connect-error" role="alert">
					{ displayedError }
				</p>
			) }

			{ backup && ! isRunning && backupPushWarning && (
				<p className="connect-warning" role="status">
					{ backupPushWarning }
				</p>
			) }

			{ pushResult?.success && (
				<div className="connect-push-success" role="status">
					<p>✓ { pushResult.message }</p>
					{ safeResultUrl && (
						<a
							href={ safeResultUrl }
							target="_blank"
							rel="noopener noreferrer"
							className="connect-site-link"
						>
							{ safeResultUrl }
						</a>
					) }
				</div>
			) }

			<div className="connect-connected-info">
				<p>{ connectedInfo }</p>
			</div>

			<div className="connect-actions">
				{ backup &&
					! isRunning &&
					! isPushing &&
					! pushResult?.success && (
						<Button
							onClick={ handlePush }
							variant="primary"
							className="connect-push-button"
							disabled={
								! backupCanPushToStudio ||
								Boolean( sitesError ) ||
								isDisconnecting ||
								isLoadingSites ||
								! sitesLoaded
							}
						>
							{ selectedSite
								? __(
										'Redeploy Site',
										'simply-static-studio-backup-migrate'
								  )
								: __(
										'Push to Studio',
										'simply-static-studio-backup-migrate'
								  ) }
						</Button>
					) }

				{ isPushing && (
					<Button
						disabled
						variant="primary"
						className="connect-push-button connect-push-loading"
					>
						<Spinner />
						{ selectedSite
							? __(
									'Redeploying…',
									'simply-static-studio-backup-migrate'
							  )
							: __(
									'Pushing…',
									'simply-static-studio-backup-migrate'
							  ) }
					</Button>
				) }

				<Button
					onClick={ handleDisconnect }
					variant="secondary"
					className="connect-disconnect-button"
					disabled={ isDisconnecting || isPushing }
				>
					{ isDisconnecting
						? __(
								'Disconnecting…',
								'simply-static-studio-backup-migrate'
						  )
						: __(
								'Disconnect',
								'simply-static-studio-backup-migrate'
						  ) }
				</Button>
			</div>
		</div>
	);
}

export default ConnectCard;
