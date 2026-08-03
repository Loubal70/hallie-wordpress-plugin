const defaults = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaults,
	entry: {
		settings: path.resolve( __dirname, 'assets/src/settings/index.js' ),
	},
	output: {
		...defaults.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
