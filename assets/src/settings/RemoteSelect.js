import { Notice, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { readFieldOptions } from './api';

/**
 * A choice list the provider resolves from its own source.
 *
 * Without it the field asks for an opaque identifier the site owner has no way of
 * knowing. The options are fetched only once the provider says it can reach its source,
 * so the control degrades to a plain message rather than an empty dropdown.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.field    Field descriptor.
 * @param {string}   props.value    Current value.
 * @param {boolean}  props.ready    Whether the provider can reach its source.
 * @param {Function} props.onChange Called with the picked value.
 * @param {Object}   props.help     Help content for the control.
 */
export default function RemoteSelect( {
	field,
	value,
	ready,
	onChange,
	help,
} ) {
	const [ options, setOptions ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! ready ) {
			return;
		}

		readFieldOptions( field.key )
			.then( ( response ) => setOptions( response.options ) )
			.catch( ( failure ) => setError( failure.message ) );
	}, [ ready, field.key ] );

	if ( ! ready ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Enter your API token and save to pick a business profile.',
					'hallie'
				) }
			</Notice>
		);
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( options === null ) {
		return <Spinner />;
	}

	return (
		<SelectControl
			label={ field.label }
			help={ help }
			value={ value }
			options={ [
				{ value: '', label: __( '— Select —', 'hallie' ) },
				...options,
			] }
			onChange={ onChange }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
}
