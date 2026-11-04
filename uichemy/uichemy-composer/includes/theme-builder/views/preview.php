<?php
/**
 * Isolated canvas preview of a single UiChemy template.
 *
 * Rendered by UiChemy_Template_Render::maybe_preview() (already capability- and
 * nonce-checked). Outputs a minimal HTML document — no theme header/footer — so
 * the template is seen on its own, with Elementor's frontend CSS (via wp_head)
 * and the template's own inlined CSS.
 *
 * @package UiChemy
 * @subpackage UiChemy/theme-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Template Preview', 'uichemy' ); ?></title>
	<?php wp_head(); ?>
	<style>
		html, body.uichemy-tb-preview { margin: 0; padding: 0; background: #fff; }
	</style>
</head>
<body <?php body_class( 'uichemy-tb-preview' ); ?>>
<?php
if ( class_exists( 'UiChemy_Template_Render' ) ) {
	// Trusted Elementor builder output (CSS inlined), echoed as-is.
	echo UiChemy_Template_Render::get_render_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
wp_footer();

// Drawn inside the block editor (UiChemy_Locations::editor_locations asks for
// it with this flag): report the template's height to the frame around it, so
// the editor can size the frame to the template. A message rather than the
// editor reading this document, which it cannot do when the site and wp-admin
// are not the same origin. Only a number is sent.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only; the preview itself is nonce-checked.
if ( isset( $_GET['uichemy_tb_embed'] ) && '1' === (string) $_GET['uichemy_tb_embed'] ) :
	?>
<script>
( function () {
	if ( window.parent === window ) {
		return;
	}
	var last = 0;
	// The bottom of the lowest rendered element: the document's own
	// scrollHeight is at least the frame's height, so it could only ever grow.
	function report() {
		var bottom = 0;
		Array.prototype.forEach.call( document.body.children, function ( child ) {
			if ( /^(SCRIPT|STYLE|LINK|TEMPLATE)$/.test( child.tagName ) ) {
				return;
			}
			var r = child.getBoundingClientRect();
			if ( r.width || r.height ) {
				bottom = Math.max( bottom, r.bottom + window.scrollY );
			}
		} );
		bottom = Math.ceil( bottom );
		if ( bottom && bottom !== last ) {
			last = bottom;
			window.parent.postMessage( { type: 'uichemy-tb-preview-size', height: bottom }, '*' );
		}
	}
	window.addEventListener( 'load', report );
	// The editor asks again once it sees the frame load: a report sent before
	// it was listening would otherwise leave the frame collapsed. The ask comes
	// from the editor's own window, which is the TOP window when this frame
	// sits in the block editor's canvas iframe, so either is accepted; the
	// answer always goes to the parent, where the frame's listener is.
	window.addEventListener( 'message', function ( e ) {
		if ( ( e.source === window.parent || e.source === window.top ) && e.data && 'uichemy-tb-preview-measure' === e.data.type ) {
			last = 0;
			report();
		}
	} );
	if ( window.ResizeObserver ) {
		new ResizeObserver( report ).observe( document.body );
	}
	report();
}() );
</script>
	<?php
endif;
?>
</body>
</html>
