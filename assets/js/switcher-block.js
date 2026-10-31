/**
 * Language switcher block (editor). Rendered on the server.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var Disabled = wp.components.Disabled;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'gaiml/language-switcher', {
		apiVersion: 3,
		title: __( 'Language switcher', 'gustinai-multilingual' ),
		icon: 'translation',
		category: 'widgets',
		edit: function ( props ) {
			var a = props.attributes;
			var set = function ( key ) {
				return function ( value ) {
					var update = {};
					update[ key ] = value;
					props.setAttributes( update );
				};
			};
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Language switcher', 'gustinai-multilingual' ) },
						el( SelectControl, {
							label: __( 'Style', 'gustinai-multilingual' ),
							value: a.style,
							options: [
								{ label: __( 'Dropdown', 'gustinai-multilingual' ), value: 'dropdown' },
								{ label: __( 'List', 'gustinai-multilingual' ), value: 'list' },
							],
							onChange: set( 'style' ),
						} ),
						el( SelectControl, {
							label: __( 'Show', 'gustinai-multilingual' ),
							value: a.show,
							options: [
								{ label: __( 'Language name', 'gustinai-multilingual' ), value: 'name' },
								{ label: __( 'Language code', 'gustinai-multilingual' ), value: 'code' },
								{ label: __( 'Code and name', 'gustinai-multilingual' ), value: 'both' },
							],
							onChange: set( 'show' ),
						} ),
						el( ToggleControl, {
							label: __( 'Hide the current language', 'gustinai-multilingual' ),
							checked: !! a.hide_current,
							onChange: set( 'hide_current' ),
						} ),
						el( ToggleControl, {
							label: __( 'Hide languages without translation', 'gustinai-multilingual' ),
							checked: !! a.hide_missing,
							onChange: set( 'hide_missing' ),
						} )
					)
				),
				el( Disabled, null, el( ServerSideRender, { block: 'gaiml/language-switcher', attributes: a } ) )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
