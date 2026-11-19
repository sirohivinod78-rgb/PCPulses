import { __ } from '@wordpress/i18n';

export class ApiResponseError extends Error {
	constructor( message, response = null ) {
		super( message );
		this.name = 'ApiResponseError';
		this.response = response;
	}
}

/**
 * Current REST callbacks return native objects, while older plugin versions or
 * cached responses can still provide JSON strings. Keep that compatibility in
 * one place.
 *
 * @param {*} response Response returned by apiFetch.
 * @return {Object} Parsed response object.
 */
export function parseApiResponse( response ) {
	let parsed = response;

	if ( typeof response === 'string' ) {
		try {
			parsed = JSON.parse( response );
		} catch {
			throw new ApiResponseError(
				__(
					'The server returned an invalid response.',
					'simply-static-studio-backup-migrate'
				)
			);
		}
	}

	if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
		throw new ApiResponseError(
			__(
				'The server returned an invalid response.',
				'simply-static-studio-backup-migrate'
			)
		);
	}

	return parsed;
}

export function getErrorMessage( error, fallback ) {
	if ( error && typeof error.message === 'string' && error.message.trim() ) {
		return error.message;
	}

	return fallback;
}

/**
 * Read an HTTP status from WordPress apiFetch and local validation errors.
 *
 * WordPress REST errors expose `data.status`; ApiResponseError keeps the parsed
 * response under `response`. Proxies and custom middleware can use one of the
 * other common shapes covered here.
 *
 * @param {*} error Error returned by apiFetch or assertSuccessfulResponse.
 * @return {number|null} A valid HTTP status, or null when unavailable.
 */
export function getErrorStatus( error ) {
	const candidates = [
		error?.data?.status,
		error?.response?.status,
		error?.response?.data?.status,
		error?.status,
	];

	for ( const candidate of candidates ) {
		const status = Number( candidate );
		if ( Number.isInteger( status ) && status >= 100 && status <= 599 ) {
			return status;
		}
	}

	return null;
}

/**
 * Older REST callbacks reported failures inside an HTTP 200 response. Treat an
 * embedded status as authoritative for backward compatibility.
 *
 * @param {*}      response        Response returned by apiFetch.
 * @param {string} fallbackMessage Message used when the response has no error.
 * @return {Object} Successful parsed response.
 */
export function assertSuccessfulResponse( response, fallbackMessage ) {
	const parsed = parseApiResponse( response );

	if ( Object.prototype.hasOwnProperty.call( parsed, 'status' ) ) {
		const status = Number( parsed.status );
		if ( ! Number.isFinite( status ) || status < 200 || status >= 300 ) {
			const message = [
				parsed.error,
				parsed.message,
				fallbackMessage,
			].find( ( value ) => typeof value === 'string' && value.trim() );
			throw new ApiResponseError(
				message ||
					__(
						'The request failed.',
						'simply-static-studio-backup-migrate'
					),
				parsed
			);
		}
	}

	return parsed;
}

/**
 * React escapes text but it does not reject javascript: URLs in href values.
 * Only render links supplied by the REST API when they use HTTP(S).
 *
 * @param {*} value Candidate URL.
 * @return {string} A safe absolute URL or an empty string.
 */
export function sanitizeHttpUrl( value ) {
	if ( typeof value !== 'string' || ! value.trim() ) {
		return '';
	}

	try {
		const url = new URL( value, window.location.href );
		if (
			! [ 'http:', 'https:' ].includes( url.protocol ) ||
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
