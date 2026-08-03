import apiFetch from '@wordpress/api-fetch';

const NAMESPACE = '/hallie/v1';

export function readSettings() {
	return apiFetch( { path: `${ NAMESPACE }/settings` } );
}

export function saveSettings( settings ) {
	return apiFetch( {
		path: `${ NAMESPACE }/settings`,
		method: 'POST',
		data: { settings },
	} );
}

export function readFieldOptions( field ) {
	return apiFetch( { path: `${ NAMESPACE }/options/${ field }` } );
}

export function runSync() {
	return apiFetch( {
		path: `${ NAMESPACE }/sync`,
		method: 'POST',
	} );
}
