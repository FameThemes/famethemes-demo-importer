/**
 * Polling hook for an in-flight import job.
 *
 * Returns the latest job state, kicks off polling on mount, stops on
 * unmount or when the job enters a terminal status (completed / failed
 * / cancelled). Cleanup is automatic — no need for caller to clear the
 * interval.
 *
 * Polling cadence comes from `ftDemoImporter.pollIntervalMs` (set by
 * Generic_Dashboard::enqueue_assets); defaults to 2000 ms.
 */

import { useEffect, useState, useRef } from '@wordpress/element';

import { jobs } from '../api';

const TERMINAL = [ 'completed', 'failed', 'cancelled' ];

const INTERVAL_MS = ( window.ftDemoImporter && window.ftDemoImporter.pollIntervalMs ) || 2000;

export function useJob( jobId ) {
	const [ job, setJob ]     = useState( null );
	const [ error, setError ] = useState( null );
	const timerRef            = useRef( null );

	useEffect( () => {
		if ( ! jobId ) {
			return undefined;
		}
		let cancelled = false;

		const tick = () => {
			jobs.get( jobId )
				.then( ( res ) => {
					if ( cancelled ) {
						return;
					}
					setJob( res );
					if ( ! TERMINAL.includes( res.status ) ) {
						timerRef.current = setTimeout( tick, INTERVAL_MS );
					}
				} )
				.catch( ( e ) => {
					if ( cancelled ) {
						return;
					}
					setError( e.message || 'Failed to poll job.' );
				} );
		};
		tick();

		return () => {
			cancelled = true;
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
				timerRef.current = null;
			}
		};
	}, [ jobId ] );

	return { job, error };
}
