// The unit tests of the screen's helpers, compiled the way the build compiles the screen.
module.exports = {
	rootDir: '..',
	roots: [ '<rootDir>/admin/src' ],
	testMatch: [ '**/*.test.js' ],
	testEnvironment: 'node',
	transform: {
		'\\.js$': [ 'babel-jest', { presets: [ '@wordpress/babel-preset-default' ] } ],
	},
};
