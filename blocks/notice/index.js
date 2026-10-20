/**
 * Editor side of the unavailable-page notice block.
 *
 * A container for any blocks, shown in the editor as it is and rendered on the
 * front end only when the request state matches the `when` attribute (see
 * classes/Notice_Block.php). Plain ES2022 against the wp.* globals, no build.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

( ( { blocks, blockEditor, components, element, i18n } ) => {

	const { createElement: el } = element;
	const { __ } = i18n;

	blocks.registerBlockType( 'kntnt-unlink-unpublished/notice', {

		/**
		 * Shows the inner blocks and a control for when they render.
		 *
		 * @param {Object}   props               Block props.
		 * @param {Object}   props.attributes    Block attributes.
		 * @param {Function} props.setAttributes Attribute setter.
		 * @return {Element} The editor element.
		 */
		edit: ( { attributes, setAttributes } ) => el(
			'div',
			blockEditor.useBlockProps(),
			el(
				blockEditor.InspectorControls,
				null,
				el(
					components.PanelBody,
					{ title: __( 'Visibility', 'kntnt-unlink-unpublished' ) },
					el( components.SelectControl, {
						label: __( 'Show on the address of', 'kntnt-unlink-unpublished' ),
						value: attributes.when,
						options: [
							{ value: 'both', label: __( 'An unpublished or deleted page', 'kntnt-unlink-unpublished' ) },
							{ value: 'unpublished', label: __( 'An unpublished page', 'kntnt-unlink-unpublished' ) },
							{ value: 'deleted', label: __( 'A deleted page', 'kntnt-unlink-unpublished' ) },
						],
						onChange: ( when ) => setAttributes( { when } ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
				),
			),
			el( blockEditor.InnerBlocks ),
		),

		/**
		 * Saves the inner blocks; the server decides whether they render.
		 *
		 * @return {Element} The saved element.
		 */
		save: () => el( 'div', blockEditor.useBlockProps.save(), el( blockEditor.InnerBlocks.Content ) ),

	} );

} )( window.wp );
