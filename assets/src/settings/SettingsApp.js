import {
	Button,
	Card,
	CardBody,
	Flex,
	Notice,
	SelectControl,
	ExternalLink,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ProviderFields from './ProviderFields';
import { humanTimeDiff } from '@wordpress/date';

import { readSettings, runSync, saveSettings } from './api';

const SYNC_INTERVALS = [
	{ value: 'hallie_six_hours', label: __( 'Every six hours', 'hallie' ) },
	{ value: 'hallie_daily', label: __( 'Once a day', 'hallie' ) },
];

const SCHEMA_EMITTERS = [
	{ value: 'auto', label: __( 'Detect automatically', 'hallie' ) },
	{ value: 'yoast', label: __( 'Yoast SEO', 'hallie' ) },
	{ value: 'seo-framework', label: __( 'The SEO Framework', 'hallie' ) },
	{ value: 'standalone', label: __( 'Standalone', 'hallie' ) },
];

/**
 * The three ways to show an author's picture, named after the platform in play.
 *
 * "Serve from the provider" is jargon; "Serve from Google" says what actually happens —
 * and stays right for a provider that publishes somewhere else.
 *
 * @param {Object} provider Active provider descriptor.
 * @return {Array} Options for the select control.
 */
function avatarModes( provider ) {
	const platform = provider?.platform || __( 'the source', 'hallie' );

	return [
		{ value: 'none', label: __( 'Author initials', 'hallie' ) },
		{
			value: 'local',
			label: __( 'Download to the media library', 'hallie' ),
		},
		{
			value: 'remote',
			/* translators: %s: platform the reviews come from, e.g. Google. */
			label: sprintf( __( 'Serve from %s', 'hallie' ), platform ),
		},
	];
}

export default function SettingsApp() {
	const [ loading, setLoading ] = useState( true );
	// Which action is running, not merely that one is: a shared boolean spins both buttons
	// and suggests that saving also syncs.
	const [ running, setRunning ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ settings, setSettings ] = useState( {} );
	const [ providers, setProviders ] = useState( [] );
	const [ secretHint, setSecretHint ] = useState( '' );
	const [ tokenUnreadable, setTokenUnreadable ] = useState( false );

	useEffect( () => {
		readSettings()
			.then( applyResponse )
			.catch( reportError )
			.finally( () => setLoading( false ) );
	}, [] );

	function applyResponse( response ) {
		// The mask is a placeholder, never a value: `settings` carries only what someone
		// typed, so an untouched secret is absent from the save instead of overwriting itself.
		setSettings( response.settings );
		setSecretHint( response.token );
		setProviders( response.providers );
		setTokenUnreadable( response.tokenUnreadable );
	}

	function reportError( error ) {
		setNotice( {
			status: 'error',
			message: error.message || __( 'Something went wrong.', 'hallie' ),
		} );
	}

	function update( key, value ) {
		setSettings( ( current ) => ( { ...current, [ key ]: value } ) );
	}

	function onSave() {
		setRunning( 'save' );
		setNotice( null );

		saveSettings( settings )
			.then( ( response ) => {
				applyResponse( response );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'hallie' ),
				} );
			} )
			.catch( reportError )
			.finally( () => setRunning( null ) );
	}

	function onSync() {
		setRunning( 'sync' );
		setNotice( null );

		// Save first: syncing with settings the user just changed but never submitted runs
		// against the old values, and looks like the sync ignored them.
		saveSettings( settings )
			.then( applyResponse )
			.then( runSync )
			.then( ( response ) =>
				setNotice( { status: 'success', message: response.summary } )
			)
			.catch( reportError )
			.finally( () => {
				setRunning( null );
				// The run may have written a fresh timestamp or changed the token state.
				readSettings().then( applyResponse ).catch( reportError );
			} );
	}

	if ( loading ) {
		return <Spinner />;
	}

	const activeProvider = providers.find(
		( provider ) => provider.id === settings.provider
	);

	// Derived, not stored: a label built when the response arrived would still read
	// "2 minutes ago" an hour later.
	const lastSync =
		activeProvider?.supportsSync && settings.last_sync_at
			? humanTimeDiff( settings.last_sync_at )
			: null;

	return (
		<Flex
			direction="column"
			gap={ 4 }
			align="stretch"
			style={ { maxWidth: '40rem' } }
		>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ tokenUnreadable && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The stored API token can no longer be decrypted — this happens when the site security salts are rotated. Enter it again to resume syncing.',
						'hallie'
					) }
				</Notice>
			) }

			<Card>
				<CardBody>
					<Flex direction="column" gap={ 4 } align="stretch">
						{ providers.length > 1 && (
							<SelectControl
								label={ __( 'Review source', 'hallie' ) }
								value={ settings.provider }
								options={ providers.map( ( provider ) => ( {
									value: provider.id,
									label: provider.label,
								} ) ) }
								onChange={ ( value ) =>
									update( 'provider', value )
								}
								help={
									activeProvider?.documentation ? (
										<ExternalLink
											href={
												activeProvider.documentation
											}
										>
											{ __(
												'Read the API documentation',
												'hallie'
											) }
										</ExternalLink>
									) : undefined
								}
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						) }

						<ProviderFields
							provider={ activeProvider }
							values={ settings }
							secretHint={ secretHint }
							onChange={ update }
						/>
					</Flex>
				</CardBody>
			</Card>

			<Card>
				<CardBody>
					<Flex direction="column" gap={ 4 } align="stretch">
						<TextControl
							label={ __( 'Reviews to keep', 'hallie' ) }
							type="number"
							min="0"
							value={ settings.sync_limit }
							onChange={ ( value ) =>
								update( 'sync_limit', value )
							}
							help={ __(
								'The most recent reviews are kept; 0 keeps them all. There is no point storing thousands to display a handful. The rating shown always comes from your listing, never from how many are stored.',
								'hallie'
							) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>

						<SelectControl
							label={ __( 'Check for new reviews', 'hallie' ) }
							value={ settings.sync_interval }
							options={ SYNC_INTERVALS }
							onChange={ ( value ) =>
								update( 'sync_interval', value )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>

						<SelectControl
							label={ __( 'Author pictures', 'hallie' ) }
							value={ settings.avatars }
							options={ avatarModes( activeProvider ) }
							onChange={ ( value ) => update( 'avatars', value ) }
							help={ __(
								'Downloading keeps the pictures available and asks nothing of your visitors, at the cost of storage. Serving them stores nothing, but every visitor then requests them from the platform. Initials request nothing and store nothing. Leaving the download option deletes the pictures already stored.',
								'hallie'
							) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>

						<SelectControl
							label={ __( 'Structured data', 'hallie' ) }
							help={ __(
								'Reviews are attached to the business entity your SEO plugin already declares. Leave on automatic unless detection gets it wrong.',
								'hallie'
							) }
							value={ settings.schema_emitter }
							options={ SCHEMA_EMITTERS }
							onChange={ ( value ) =>
								update( 'schema_emitter', value )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>

						<ToggleControl
							label={ __(
								'Delete reviews when the plugin is uninstalled',
								'hallie'
							) }
							help={ __(
								'Off by default. Settings are always removed; your reviews and their customisations are not, unless you ask for it here.',
								'hallie'
							) }
							checked={ !! settings.delete_data }
							onChange={ ( value ) =>
								update( 'delete_data', value )
							}
							__nextHasNoMarginBottom
						/>
					</Flex>
				</CardBody>
			</Card>

			<div className="hallie-actions">
				<Button
					variant="primary"
					onClick={ onSave }
					isBusy={ 'save' === running }
					disabled={ null !== running }
				>
					{ __( 'Save settings', 'hallie' ) }
				</Button>

				{ activeProvider?.supportsSync && (
					<Button
						variant="secondary"
						onClick={ onSync }
						isBusy={ 'sync' === running }
						disabled={
							null !== running || ! activeProvider?.configured
						}
					>
						{ 'sync' === running
							? __( 'Syncing…', 'hallie' )
							: __( 'Sync now', 'hallie' ) }
					</Button>
				) }

				{ lastSync && (
					<span className="hallie-actions__status">
						{ __( 'Last sync:', 'hallie' ) } { lastSync }
					</span>
				) }
			</div>
		</Flex>
	);
}
