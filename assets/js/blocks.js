/**
 * Editor registration for the two blocks.
 *
 * WHY THIS FILE HAS TO EXIST.
 *
 * `register_block_type()` in PHP makes a block renderable and exposes its
 * metadata over `/wp/v2/block-types`. It does NOT put it in the inserter:
 * WordPress has no PHP-only registration path, so the editor's block registry
 * only knows about blocks something called `registerBlockType()` for. Without
 * this file neither block could be inserted, and a post that already contained
 * one showed "this block contains unexpected or invalid content".
 *
 * The previous docblock in class-shortcodes.php reasoned that no `editorScript`
 * was needed "because there is nothing to configure". That conflates having no
 * attributes with needing no client registration; the second is required
 * whatever the attributes are.
 *
 * NO BUILD STEP. This plugin deliberately has none, so this uses the `wp.*`
 * globals rather than imports, and the script is registered from PHP with its
 * dependencies named explicitly — there is no `index.asset.php` to read them
 * from.
 *
 * NO TRANSLATABLE STRINGS HERE, on purpose. `title` and `description` come from
 * each block.json, which WordPress already translates through the textdomain it
 * declares. Adding `wp.i18n` calls would put JS strings in the POT and make the
 * catalogue depend on a build this repo does not run.
 */
( function ( blocks, element, blockEditor, serverSideRender ) {
	'use strict';

	if ( ! blocks || ! element || ! blockEditor ) {
		return;
	}

	var createElement = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;

	/*
	 * Both blocks are server-rendered by the SAME renderer the shortcode uses,
	 * so the preview is the real markup rather than a mock of it. That is the
	 * point of ServerSideRender here: a placeholder would let the editor preview
	 * and the published page drift, which is exactly the class of bug this
	 * plugin keeps finding.
	 */
	function edit( name ) {
		return function Edit() {
			var props = useBlockProps();

			if ( ! serverSideRender ) {
				return createElement( 'div', props, name );
			}

			return createElement(
				'div',
				props,
				createElement( serverSideRender, { block: name } )
			);
		};
	}

	[ 'spotship/war-hull', 'spotship/war-cargo' ].forEach( function ( name ) {
		// The metadata — title, description, icon, category — is read from the
		// registered block type, which PHP populated from block.json. Repeating
		// it here would be a second definition to drift.
		blocks.registerBlockType( name, {
			edit: edit( name ),

			// Server-rendered: nothing is stored in post content but the
			// comment delimiter itself.
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.serverSideRender );
