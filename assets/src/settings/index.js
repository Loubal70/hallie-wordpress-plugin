import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import SettingsApp from './SettingsApp';
import './style.scss';

domReady( () => {
	const root = document.getElementById( 'hallie-settings-root' );

	if ( root ) {
		createRoot( root ).render( <SettingsApp /> );
	}
} );
