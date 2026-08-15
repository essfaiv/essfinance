module.exports = function ( grunt ) {
	const fs = require( 'fs' );

	const README = 'README.md';

	// Content that only makes sense on GitHub (badges, dev setup) and has no
	// equivalent in readme.txt — injected into the generated README.md after
	// wp_readme_to_markdown runs. Each block is wrapped in HTML comment markers
	// so re-running this task is idempotent instead of duplicating content.
	const BLOCKS = {
		'playground-badge': {
			anchor: /^## Description ##$/m,
			position: 'before',
			content:
				'[![Try in Playground](https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/essfaiv/essfinance/main/blueprint.json)',
		},
		development: {
			position: 'append',
			content:
				'## Development ##\n\n' +
				'```bash\n' +
				'git clone git@github.com:essfaiv/essfinance.git\n' +
				'```\n\n' +
				'Load the plugin in a local WordPress site. The **Try in Playground** badge above opens the latest release in [WordPress Playground](https://playground.wordpress.net).',
		},
	};

	grunt.initConfig( {
		wp_readme_to_markdown: {
			readme: {
				files: {
					'README.md': 'readme.txt',
				},
			},
		},
	} );

	grunt.loadNpmTasks( 'grunt-wp-readme-to-markdown' );

	grunt.registerTask( 'essf_inject_readme_extras', function () {
		let contents = fs.readFileSync( README, 'utf8' );

		Object.keys( BLOCKS ).forEach( function ( key ) {
			const begin = '<!-- essf:' + key + ' -->';
			const end = '<!-- /essf:' + key + ' -->';
			const block = BLOCKS[ key ];

			// Strip any block injected by a previous run before reinserting.
			const stripRe = new RegExp( '\\n*' + begin + '[\\s\\S]*?' + end + '\\n*', 'g' );
			contents = contents.replace( stripRe, '\n\n' );

			const wrapped = begin + '\n' + block.content + '\n' + end;

			if ( 'append' === block.position ) {
				contents = contents.replace( /\n+$/, '\n' ) + '\n' + wrapped + '\n';
			} else {
				contents = contents.replace( block.anchor, function ( match ) {
					return wrapped + '\n\n' + match;
				} );
			}
		} );

		fs.writeFileSync( README, contents );
		grunt.log.ok( 'Injected custom README.md content: ' + Object.keys( BLOCKS ).join( ', ' ) );
	} );

	grunt.registerTask( 'readme', [ 'wp_readme_to_markdown', 'essf_inject_readme_extras' ] );
};
