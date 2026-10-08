/**
 * The two blocks, in the editor that has to offer them.
 *
 * THE DEFECT THIS GUARDS AGAINST: both blocks were registered server-side only.
 * `register_block_type()` in PHP makes a block renderable and publishes its
 * metadata, but the inserter is built from the CLIENT-side block registry, and
 * nothing called `registerBlockType()` for either of them. Neither appeared in
 * the inserter, and a post already containing one showed "this block contains
 * unexpected or invalid content". The block surface did not work at all.
 *
 * Nothing caught it because nothing had ever opened the editor: every spec in
 * this suite drives the front end. A block you cannot insert is invisible to a
 * front-end test, which is exactly why this file drives wp-admin instead.
 *
 * These run serially — they share one post.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );
const { login } = require( '../support/wp-admin.js' );

test.describe.configure( { mode: 'serial' } );
test.setTimeout( 120000 );

const BLOCKS = [
	{ name: 'spotship/war-hull', product: 'hull', title: 'War Hull Quote', field: 'handmValue' },
	{ name: 'spotship/war-cargo', product: 'cargo', title: 'War Cargo Quote', field: 'cargoDescription' },
];

/**
 * Open a new post in the block editor, past whatever welcome modal is showing.
 *
 * @param {object} page Playwright page.
 */
async function newPost( page ) {
	await login( page );
	await page.goto( '/wp-admin/post-new.php?post_type=post' );

	// The welcome guide is shown once per user and blocks the canvas. Dismissing
	// it through the store rather than the close button, because the button's
	// label and markup have changed between WordPress versions and this suite
	// runs against 6.4 and latest.
	await page.waitForFunction( () => window.wp && window.wp.data, null, { timeout: 60000 } );
	await page.evaluate( () => {
		const prefs = window.wp.data.dispatch( 'core/preferences' );

		if ( prefs && prefs.set ) {
			prefs.set( 'core/edit-post', 'welcomeGuide', false );
			prefs.set( 'core', 'welcomeGuide', false );
		}
	} );
}

for ( const block of BLOCKS ) {
	test( `${ block.title } is registered in the editor and can be inserted`, async ( { page } ) => {
		await newPost( page );

		/*
		 * REGISTRATION FIRST, AND FROM THE EDITOR'S OWN REGISTRY.
		 *
		 * This is the precise thing that was missing: the block type existed on
		 * the server and was absent from the client registry. Asking the store
		 * distinguishes "not registered" from "registered but the inserter UI
		 * moved between WordPress versions", which matters because this suite
		 * runs against two of them.
		 */
		const registered = await page.evaluate(
			( name ) => !! window.wp.data.select( 'core/blocks' ).getBlockType( name ),
			block.name
		);

		expect( registered, `${ block.name } is not in the editor's block registry` ).toBe( true );

		// The title the inserter shows comes from block.json via the server.
		const title = await page.evaluate(
			( name ) => window.wp.data.select( 'core/blocks' ).getBlockType( name ).title,
			block.name
		);

		expect( title ).toBe( block.title );

		/*
		 * And it must actually insert. A registered type that throws on edit()
		 * lands as an error boundary in the canvas rather than a block, which
		 * the registry check above cannot see.
		 */
		await page.evaluate( ( name ) => {
			const { createBlock } = window.wp.blocks;

			window.wp.data.dispatch( 'core/block-editor' ).insertBlock( createBlock( name ) );
		}, block.name );

		const inserted = await page.evaluate(
			( name ) =>
				window.wp.data
					.select( 'core/block-editor' )
					.getBlocks()
					.filter( ( b ) => b.name === name ).length,
			block.name
		);

		expect( inserted, 'the block was not added to the post' ).toBe( 1 );

		// The editor must not have replaced it with the invalid-content
		// fallback, which is what an unregistered block becomes.
		const missing = await page.evaluate( () =>
			window.wp.data
				.select( 'core/block-editor' )
				.getBlocks()
				.some( ( b ) => b.name === 'core/missing' )
		);

		expect( missing, 'the block resolved to core/missing — it is not registered client-side' ).toBe(
			false
		);
	} );

	test( `${ block.title } previews the real form in the editor`, async ( { page } ) => {
		/*
		 * ServerSideRender is used deliberately: the preview is produced by the
		 * SAME renderer the shortcode and the front end use, so the editor
		 * cannot show something the published page will not. A placeholder
		 * would let the two drift, which is the class of bug this plugin keeps
		 * finding.
		 */
		await newPost( page );

		await page.evaluate( ( name ) => {
			const { createBlock } = window.wp.blocks;

			window.wp.data.dispatch( 'core/block-editor' ).insertBlock( createBlock( name ) );
		}, block.name );

		// apiVersion 3 renders the canvas in an iframe; fall back to the page
		// itself on versions that do not.
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const inIframe = await page.locator( 'iframe[name="editor-canvas"]' ).count();
		const scope = inIframe ? canvas : page;

		await expect( scope.locator( `[data-sswi-form="${ block.product }"]` ) ).toBeVisible( {
			timeout: 30000,
		} );

		// A field unique to this product, so a hull preview under the cargo
		// block would fail rather than pass on shared markup.
		await expect( scope.locator( `[data-sswi-field="${ block.field }"]` ) ).toBeVisible();
	} );
}
