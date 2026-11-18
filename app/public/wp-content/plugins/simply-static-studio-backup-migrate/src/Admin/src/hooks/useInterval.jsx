import { useEffect, useRef } from '@wordpress/element';

export default function useInterval( callback, delay ) {
	const savedCallback = useRef();

	// Remember the latest callback.
	useEffect( () => {
		savedCallback.current = callback;
	}, [ callback ] );

	// Use a recursive timeout so a slow async poll cannot overlap the next one
	// and let an older response overwrite newer state.
	useEffect( () => {
		if ( delay === null || ! Number.isFinite( delay ) || delay < 0 ) {
			return undefined;
		}

		let active = true;
		let timeoutId;

		const scheduleNext = () => {
			if ( active ) {
				timeoutId = setTimeout( tick, delay );
			}
		};

		const tick = () => {
			Promise.resolve()
				.then( () => savedCallback.current() )
				// Polling callbacks surface their own user-facing errors. Keep
				// a transient failure from stopping all future refreshes.
				.catch( () => undefined )
				.finally( scheduleNext );
		};

		timeoutId = setTimeout( tick, delay );

		return () => {
			active = false;
			clearTimeout( timeoutId );
		};
	}, [ delay ] );
}
