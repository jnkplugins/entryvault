/**
 * Editor registration for the entryvault/submissions-table block.
 *
 * Hand-authored (no build step) so the shipped file is the source — keeps the
 * plugin human-readable per WordPress.org guideline 4. Uses the wp.* globals
 * declared as dependencies in index.asset.php. The block is dynamic: save()
 * returns null and render.php produces the front-end output.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var __ = wp.i18n.__;

	var COLUMN_OPTIONS = [
		{ value: 'name', label: __( 'Name', 'entryvault' ) },
		{ value: 'title', label: __( 'Title', 'entryvault' ) },
		{ value: 'email', label: __( 'Email (shown publicly)', 'entryvault' ) },
		{ value: 'stage', label: __( 'Stage', 'entryvault' ) },
		{ value: 'owner', label: __( 'Owner (staff name, shown publicly)', 'entryvault' ) },
		{ value: 'tags', label: __( 'Tags (shown publicly)', 'entryvault' ) },
		{ value: 'created', label: __( 'Created', 'entryvault' ) },
		{ value: 'submitted_at', label: __( 'Submitted', 'entryvault' ) },
	];

	registerBlockType( 'entryvault/submissions-table', {
		attributes: {
			pipeline: { type: 'string', default: '' },
			stage: { type: 'string', default: '' },
			access_token: { type: 'string', default: '' },
			columns: {
				type: 'array',
				// Same defaults as the [entryvault_table] shortcode: no email address on
				// a public page unless someone ticks it on purpose.
				default: [ 'submitted_at', 'name', 'stage' ],
				items: { type: 'string' },
			},
		},

		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var pipeline = attributes.pipeline || '';
			var stage = attributes.stage || '';
			var accessToken = attributes.access_token || '';
			var columns = attributes.columns || [ 'submitted_at', 'name', 'stage' ];

			function toggleColumn( value ) {
				var next = columns.indexOf( value ) > -1
					? columns.filter( function ( c ) { return c !== value; } )
					: columns.concat( [ value ] );
				setAttributes( { columns: next } );
			}

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Submissions Table', 'entryvault' ) },
						el( TextControl, {
							label: __( 'Pipeline slug', 'entryvault' ),
							value: pipeline,
							onChange: function ( v ) { setAttributes( { pipeline: v } ); },
						} ),
						el( TextControl, {
							label: __( 'Stage slug (optional)', 'entryvault' ),
							value: stage,
							onChange: function ( v ) { setAttributes( { stage: v } ); },
						} ),
						el( TextControl, {
							label: __( 'Access token (optional)', 'entryvault' ),
							value: accessToken,
							onChange: function ( v ) { setAttributes( { access_token: v } ); },
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Columns', 'entryvault' ), initialOpen: false },
						COLUMN_OPTIONS.map( function ( opt ) {
							return el( CheckboxControl, {
								key: opt.value,
								label: opt.label,
								checked: columns.indexOf( opt.value ) > -1,
								onChange: function () { toggleColumn( opt.value ); },
							} );
						} )
					)
				),
				el(
					'div',
					{ className: 'entryvault-submissions-table-placeholder' },
					el( 'strong', null, __( 'EntryVault Submissions Table', 'entryvault' ) ),
					el(
						'div',
						null,
						pipeline
							? __( 'pipeline: ', 'entryvault' ) + pipeline
							: __( '(no pipeline set)', 'entryvault' )
					),
					el(
						'div',
						null,
						__( 'columns: ', 'entryvault' ) + columns.join( ', ' )
					)
				)
			);
		},

		// Dynamic block — front-end output comes from render.php.
		save: function () { return null; },
	} );
} )( window.wp );
