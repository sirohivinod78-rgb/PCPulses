import { useContext, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { SettingsContext } from '../context/Context';
import { assertSuccessfulResponse, getErrorMessage } from '../../utils/api';

import { __ } from '@wordpress/i18n';
const ROUTE = '/sss-backup-migrate/v1/migration-observation';
const PAIRING_ID =
	/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function PairingTime( { value } ) {
	const date = new Date( value );
	if ( ! value || Number.isNaN( date.getTime() ) ) {
		return value || null;
	}
	return (
		<time dateTime={ value } title={ value }>
			{ date.toLocaleString( undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: 'numeric',
				minute: '2-digit',
				timeZoneName: 'short',
			} ) }
		</time>
	);
}

function PairingDetails( { pairing, studioEmail, connected, lastSentAt } ) {
	return (
		<dl className="migration-observation-details">
			<div className="migration-observation-detail migration-observation-detail--wide">
				<dt>
					{ __(
						'MCP connection site',
						'simply-static-studio-backup-migrate'
					) }
				</dt>
				<dd>
					<strong>{ pairing?.site_name }</strong>
					<span className="migration-observation-site-id">
						{ __(
							'Site ID',
							'simply-static-studio-backup-migrate'
						) }{ ' ' }
						{ pairing?.site_id }
					</span>
				</dd>
			</div>
			<div className="migration-observation-detail migration-observation-detail--wide">
				<dt>
					{ __(
						'Source WordPress site',
						'simply-static-studio-backup-migrate'
					) }
				</dt>
				<dd>{ pairing?.source_url }</dd>
			</div>
			<div className="migration-observation-detail">
				<dt>
					{ __(
						'Studio account',
						'simply-static-studio-backup-migrate'
					) }
				</dt>
				<dd>{ studioEmail }</dd>
			</div>
			<div className="migration-observation-detail">
				<dt>
					{ connected
						? __(
								'Sharing expires',
								'simply-static-studio-backup-migrate'
						  )
						: __(
								'Invitation expires',
								'simply-static-studio-backup-migrate'
						  ) }
				</dt>
				<dd>
					<PairingTime value={ pairing?.expires_at } />
				</dd>
			</div>
			{ lastSentAt && (
				<div className="migration-observation-detail migration-observation-detail--wide">
					<dt>
						{ __(
							'Last shared',
							'simply-static-studio-backup-migrate'
						) }
					</dt>
					<dd>
						<PairingTime value={ lastSentAt } />
					</dd>
				</div>
			) }
		</dl>
	);
}

/** An optional source-side consent step, separate from Push to Studio. */
export default function MigrationObservationCard() {
	const { studioConnected, studioEmail } = useContext( SettingsContext );
	const [ pairingId, setPairingId ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ connection, setConnection ] = useState( null );
	const [ enabled, setEnabled ] = useState( false );
	const [ checking, setChecking ] = useState( true );
	const [ checkFailed, setCheckFailed ] = useState( false );
	const [ checkRevision, setCheckRevision ] = useState( 0 );
	const [ approved, setApproved ] = useState( false );
	const [ approvedTransfer, setApprovedTransfer ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const revision = useRef( 0 );
	const mounted = useRef( true );
	const actionPending = useRef( false );

	useEffect( () => {
		mounted.current = true;
		const request = ++revision.current;
		setPreview( null );
		setConnection( null );
		setEnabled( false );
		setChecking( true );
		setCheckFailed( false );
		setPairingId( '' );
		setApproved( false );
		setApprovedTransfer( false );
		setError( '' );
		setNotice( '' );
		setBusy( false );
		actionPending.current = false;
		if ( studioConnected ) {
			apiFetch( { path: ROUTE } )
				.then( ( response ) => {
					const data = assertSuccessfulResponse( response );
					if ( mounted.current && revision.current === request ) {
						setConnection( data.connected ? data : null );
						setEnabled(
							data.connected === true || !! data.pairing
						);
						setPreview(
							data.connected ? null : data.pairing || null
						);
						setPairingId( data.pairing?.migration_id || '' );
					}
				} )
				.catch( () => {
					if ( mounted.current && revision.current === request ) {
						setCheckFailed( true );
						setError(
							__(
								'Could not check the Static Studio MCP connection. Try again to manage progress sharing.',
								'simply-static-studio-backup-migrate'
							)
						);
					}
				} )
				.finally( () => {
					if ( mounted.current && revision.current === request ) {
						setChecking( false );
					}
				} );
		}
		return () => {
			mounted.current = false;
		};
	}, [ studioConnected, studioEmail, checkRevision ] );

	useEffect( () => {
		if ( ! studioConnected || ! connection?.connected ) {
			return undefined;
		}
		let active = true;
		let inFlight = false;
		const timer = setInterval( () => {
			if ( inFlight || actionPending.current ) {
				return;
			}
			inFlight = true;
			const current = revision.current;
			apiFetch( { path: `${ ROUTE }/heartbeat`, method: 'POST' } )
				.then( ( response ) => {
					const data = assertSuccessfulResponse( response );
					if ( active && revision.current === current ) {
						setConnection( data.connected ? data : null );
					}
				} )
				.catch( () => {
					if ( active && revision.current === current ) {
						setError(
							__(
								'Static Studio MCP progress updates are temporarily unavailable. Your backup continues independently.',
								'simply-static-studio-backup-migrate'
							)
						);
					}
				} )
				.finally( () => {
					inFlight = false;
				} );
		}, 60000 );
		return () => {
			active = false;
			clearInterval( timer );
		};
	}, [ studioConnected, connection?.connected ] );

	const request = async ( action, body, apply ) => {
		const current = ++revision.current;
		actionPending.current = true;
		setBusy( true );
		setError( '' );
		setNotice( '' );
		try {
			const response = await apiFetch( {
				path: `${ ROUTE }/${ action }`,
				method: 'POST',
				data: body,
			} );
			const data = assertSuccessfulResponse( response );
			if ( mounted.current && revision.current === current ) {
				apply( data );
			}
		} catch ( failure ) {
			if ( mounted.current && revision.current === current ) {
				setError(
					getErrorMessage(
						failure,
						__(
							'Could not update Static Studio MCP progress sharing.',
							'simply-static-studio-backup-migrate'
						)
					)
				);
			}
		} finally {
			if ( mounted.current && revision.current === current ) {
				actionPending.current = false;
				setBusy( false );
			}
		}
	};

	const toggleSupport = ( nextEnabled ) => {
		if ( nextEnabled ) {
			// Revealing setup grants no access and sends no invitation or approval.
			setEnabled( true );
			setNotice( '' );
			return;
		}
		// Revoke even an unconfirmed preview: confirmation may have lost its ACK.
		request( 'revoke', {}, ( data ) => {
			setConnection( null );
			setEnabled( false );
			setPairingId( '' );
			setPreview( null );
			setApproved( false );
			setApprovedTransfer( false );
			setNotice(
				data.remote_revoked === false
					? __(
							'MCP progress sharing stopped on this WordPress site. Static Studio could not confirm remote revocation; ask ChatGPT or Claude to revoke the pairing as well.',
							'simply-static-studio-backup-migrate'
					  )
					: __(
							'MCP support disabled. Progress sharing has stopped.',
							'simply-static-studio-backup-migrate'
					  )
			);
		} );
	};

	if ( ! studioConnected ) {
		return null;
	}

	return (
		<section
			className="migration-observation"
			aria-label={ __(
				'Static Studio MCP',
				'simply-static-studio-backup-migrate'
			) }
		>
			<div className="migration-observation-header">
				<h4>
					{ __(
						'Static Studio MCP',
						'simply-static-studio-backup-migrate'
					) }
				</h4>
				{ connection?.connected && (
					<span className="migration-observation-badge">
						{ __(
							'Sharing progress',
							'simply-static-studio-backup-migrate'
						) }
					</span>
				) }
			</div>
			<p className="migration-observation-description">
				{ __(
					'Share this site’s backup progress with ChatGPT or Claude through your Static Studio MCP connection.',
					'simply-static-studio-backup-migrate'
				) }
			</p>
			<div className="migration-observation-toggle">
				<ToggleControl
					label={ __(
						'Enable MCP support',
						'simply-static-studio-backup-migrate'
					) }
					checked={ enabled }
					disabled={ busy || checking || checkFailed }
					onChange={ toggleSupport }
				/>
			</div>
			{ error && (
				<p
					className="migration-observation-message migration-observation-message--error"
					role="alert"
				>
					{ error }
				</p>
			) }
			{ checkFailed && (
				<Button
					className="migration-observation-button migration-observation-retry"
					variant="secondary"
					onClick={ () => setCheckRevision( ( value ) => value + 1 ) }
				>
					{ __( 'Try again', 'simply-static-studio-backup-migrate' ) }
				</Button>
			) }
			{ notice && (
				<p className="migration-observation-message" role="status">
					{ notice }
				</p>
			) }
			{ enabled && (
				<div className="migration-observation-content">
					<p className="migration-observation-limits">
						{ __(
							'Share backup status, stages and progress timestamps for up to 24 hours. Backups and transfers are managed separately with the controls above.',
							'simply-static-studio-backup-migrate'
						) }
					</p>
					{ connection?.connected ? (
						<>
							<div className="migration-observation-panel">
								<PairingDetails
									pairing={ connection.pairing }
									studioEmail={ studioEmail }
									connected
									lastSentAt={ connection.last_sent_at }
								/>
							</div>
							{ connection.error_code && (
								<p
									className="migration-observation-message migration-observation-message--warning"
									role="status"
								>
									{ __(
										'Static Studio MCP could not confirm the latest progress update. ChatGPT or Claude will see that older updates are out of date.',
										'simply-static-studio-backup-migrate'
									) }
								</p>
							) }
							<div className="migration-observation-footer">
								<p className="migration-observation-help">
									{ __(
										'Keep this page open if your host does not run WordPress scheduled tasks reliably.',
										'simply-static-studio-backup-migrate'
									) }
								</p>
							</div>
						</>
					) : (
						<>
							<p className="migration-observation-setup">
								{ __(
									'In ChatGPT or Claude with Static Studio connected, ask to follow this WordPress site’s backup progress. Paste the pairing ID from that chat below, then review and approve sharing.',
									'simply-static-studio-backup-migrate'
								) }
							</p>
							<div className="migration-observation-form">
								<TextControl
									label={ __(
										'Pairing ID from Static Studio MCP',
										'simply-static-studio-backup-migrate'
									) }
									value={ pairingId }
									disabled={ busy }
									onChange={ ( value ) => {
										setPairingId( value );
										setPreview( null );
										setApproved( false );
										setApprovedTransfer( false );
									} }
								/>
								<Button
									className="migration-observation-button migration-observation-review"
									variant="secondary"
									disabled={
										busy ||
										! PAIRING_ID.test( pairingId.trim() )
									}
									onClick={ () =>
										request(
											'preview',
											{
												pairing_id: pairingId
													.trim()
													.toLowerCase(),
											},
											( data ) => {
												setPreview( data.pairing );
												setApproved( false );
												setApprovedTransfer( false );
											}
										)
									}
								>
									{ __(
										'Review pairing',
										'simply-static-studio-backup-migrate'
									) }
								</Button>
							</div>
							<p className="migration-observation-privacy">
								{ __(
									'Reviewing a pairing sends this site’s URL, a random installation ID and the plugin version to Studio to verify the invitation.',
									'simply-static-studio-backup-migrate'
								) }{ ' ' }
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
							{ preview && (
								<div className="migration-observation-panel">
									<h5>
										{ __(
											'Review MCP progress sharing',
											'simply-static-studio-backup-migrate'
										) }
									</h5>
									<PairingDetails
										pairing={ preview }
										studioEmail={ studioEmail }
									/>
									<div className="migration-observation-consent">
										<CheckboxControl
											checked={ approved }
											disabled={ busy }
											onChange={ setApproved }
											label={ __(
												'I approve sharing backup status and progress timestamps from this WordPress site with this Static Studio MCP connection for up to 24 hours.',
												'simply-static-studio-backup-migrate'
											) }
										/>
										<CheckboxControl
											checked={ approvedTransfer }
											disabled={ busy }
											onChange={ setApprovedTransfer }
											label={ __(
												'Also let Static Studio MCP follow this backup’s transfer and destination.',
												'simply-static-studio-backup-migrate'
											) }
											help={ __(
												'Follows this backup’s verified upload to the destination selected above. A new site may need access approved in your MCP connection.',
												'simply-static-studio-backup-migrate'
											) }
										/>
										<div className="migration-observation-actions">
											<Button
												className="migration-observation-button"
												variant="primary"
												disabled={ busy || ! approved }
												onClick={ () =>
													request(
														'confirm',
														{
															pairing_id:
																pairingId
																	.trim()
																	.toLowerCase(),
															approved: true,
															...( approvedTransfer
																? {
																		approved_transfer: true,
																  }
																: {} ),
														},
														( data ) => {
															setConnection(
																data.connected
																	? data
																	: null
															);
															setPreview( null );
															setApproved(
																false
															);
															setApprovedTransfer(
																false
															);
														}
													)
												}
											>
												{ __(
													'Share backup progress',
													'simply-static-studio-backup-migrate'
												) }
											</Button>
										</div>
									</div>
								</div>
							) }
						</>
					) }
				</div>
			) }
		</section>
	);
}
