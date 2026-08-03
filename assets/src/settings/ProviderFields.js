import { ExternalLink, TextControl } from '@wordpress/components';

import RemoteSelect from './RemoteSelect';

/**
 * Renders whatever fields the active provider declared.
 *
 * The screen knows nothing about any particular provider: the shape comes from
 * `settings_fields()` on the PHP side, so a provider added through the
 * `hallie_register_providers` filter gets a working settings form for free.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.provider   Active provider descriptor.
 * @param {Object}   props.values     Current setting values.
 * @param {string}   props.secretHint Masked form of the stored secret, shown as a hint.
 * @param {Function} props.onChange   Called with ( key, value ) on every edit.
 */
export default function ProviderFields( {
	provider,
	values,
	secretHint,
	onChange,
} ) {
	if ( ! provider || ! provider.fields.length ) {
		return null;
	}

	return provider.fields.map( ( field ) => (
		<Control
			key={ field.key }
			field={ field }
			values={ values }
			secretHint={ secretHint }
			onChange={ onChange }
			ready={ provider.configured }
		/>
	) );
}

/**
 * Description of a field, with the link to wherever its value is obtained.
 *
 * Inlined into the help text rather than given its own paragraph: a one-line hint should
 * not cost three lines of vertical space.
 *
 * @param {Object} field Field descriptor.
 * @return {string|Object} Help content for the control.
 */
function helpFor( field ) {
	if ( ! field.link ) {
		return field.help;
	}

	return (
		<>
			{ field.help }{ ' ' }
			<ExternalLink href={ field.link.url }>
				{ field.link.label }
			</ExternalLink>
		</>
	);
}

/**
 * The input itself, picked from the declared field type.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.field      Field descriptor.
 * @param {Object}   props.values     Current setting values.
 * @param {string}   props.secretHint Masked form of the stored secret, shown as a hint.
 * @param {Function} props.onChange   Called with ( key, value ) on every edit.
 * @param {boolean}  props.ready      Whether the provider can reach its source.
 */
function Control( { field, values, secretHint, onChange, ready } ) {
	const shared = {
		label: field.label,
		help: helpFor( field ),
		placeholder: field.placeholder,
		value: values[ field.key ] ?? '',
		onChange: ( value ) => onChange( field.key, value ),
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	};

	switch ( field.type ) {
		case 'select-remote':
			return (
				<RemoteSelect
					field={ field }
					value={ values[ field.key ] ?? '' }
					ready={ ready }
					onChange={ shared.onChange }
					help={ shared.help }
				/>
			);

		case 'password':
			// Left empty on purpose: an admin who types replaces nothing, and submitting
			// without touching it sends no value at all.
			return (
				<TextControl
					{ ...shared }
					type="password"
					autoComplete="off"
					placeholder={ secretHint || field.placeholder }
				/>
			);

		case 'url':
			return <TextControl { ...shared } type="url" />;

		default:
			return <TextControl { ...shared } />;
	}
}
