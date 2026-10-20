/**
 * Drop-marker controls in the block editor (spec §4.3).
 *
 * Registers the inline format that wraps a selection in a marked span and a
 * toggle in every block's Advanced panel that adds or removes the same class
 * in the block's `className`. Both store nothing but the class, so a block
 * marked by typing the class by hand behaves the same. Plain ES2022 against
 * the wp.* globals, no build.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

( ( { blockEditor, blocks, components, compose, element, hooks, i18n, richText } ) => {

	const { createElement: el, Fragment } = element;
	const { __ } = i18n;

	/**
	 * The class that marks text or a block as dropped with its links.
	 *
	 * @type {string}
	 */
	const DROP_MARKER = 'kntnt-unlink-unpublished-drop';

	/**
	 * The name of the inline format.
	 *
	 * @type {string}
	 */
	const FORMAT = 'kntnt-unlink-unpublished/drop';

	// The inline format: a span with the marker class, toggled from the
	// rich-text toolbar.
	richText.registerFormatType( FORMAT, {
		title: __( 'Hide if link target is unpublished', 'kntnt-unlink-unpublished' ),
		tagName: 'span',
		className: DROP_MARKER,
		edit: ( { isActive, value, onChange } ) => el( blockEditor.RichTextToolbarButton, {
			icon: 'hidden',
			title: __( 'Hide if link target is unpublished', 'kntnt-unlink-unpublished' ),
			isActive,
			onClick: () => onChange( richText.toggleFormat( value, { type: FORMAT } ) ),
		} ),
	} );

	/**
	 * Adds the drop-marker toggle to the Advanced panel of every block that
	 * supports custom class names.
	 *
	 * @param {Function} BlockEdit The block's edit component.
	 * @return {Function} The wrapped edit component.
	 */
	const withDropToggle = compose.createHigherOrderComponent( ( BlockEdit ) => ( props ) => {

		// Leave blocks without custom class names alone.
		if ( ! blocks.hasBlockSupport( props.name, 'customClassName', true ) ) {
			return el( BlockEdit, props );
		}

		// Read the marker from the class name and write it back on change.
		const classes = ( props.attributes.className ?? '' ).split( /\s+/ ).filter( Boolean );
		const toggle = ( marked ) => {
			const next = marked ? [ ...classes.filter( ( name ) => name !== DROP_MARKER ), DROP_MARKER ] : classes.filter( ( name ) => name !== DROP_MARKER );
			props.setAttributes( { className: next.length > 0 ? next.join( ' ' ) : undefined } );
		};

		return el(
			Fragment,
			null,
			el( BlockEdit, props ),
			props.isSelected && el(
				blockEditor.InspectorControls,
				{ group: 'advanced' },
				el( components.ToggleControl, {
					label: __( 'Hide block if link target is unpublished', 'kntnt-unlink-unpublished' ),
					help: __( 'The whole block is hidden when a link inside it points at a page that is not published.', 'kntnt-unlink-unpublished' ),
					checked: classes.includes( DROP_MARKER ),
					onChange: toggle,
					__nextHasNoMarginBottom: true,
				} ),
			),
		);

	}, 'withKntntUnlinkUnpublishedDropToggle' );

	hooks.addFilter( 'editor.BlockEdit', 'kntnt-unlink-unpublished/drop-toggle', withDropToggle );

} )( window.wp );
