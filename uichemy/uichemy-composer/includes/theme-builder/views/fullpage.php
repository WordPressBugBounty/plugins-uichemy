<?php
/**
 * Full-page view for a UiChemy native template rendered via template_include
 * (the 404 location and the single post/page location).
 *
 * Wraps the resolved template's Elementor content in the active theme's
 * header/footer so it integrates like any WordPress template. Any HTTP status
 * WordPress set for this request (e.g. 404) is left untouched.
 *
 * Two shells, by theme kind:
 *  - Classic theme: get_header() / get_footer(). The theme's header.php fires
 *    the location hooks UiChemy_Locations swaps a header/footer template into.
 *  - Block theme: there is no header.php / footer.php — get_header() falls back
 *    to WordPress's deprecated theme-compat header, which prints no header at
 *    all, so every full-page template lost the site header and footer. A block
 *    theme's header and footer are `core/template-part` blocks, so this view
 *    renders those, the same blocks the theme's own templates use. That is
 *    also where UiChemy_Locations::filter_template_part_block() swaps in an
 *    active UiChemy header/footer — and where the theme's own part shows when
 *    there is none.
 *
 * @package UiChemy
 * @subpackage UiChemy/theme-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	// Everything renders BEFORE <head>, as core's template-canvas.php does, so
	// the styles and scripts the blocks and the template enqueue while
	// rendering still print in wp_head(). Header, content, footer: page order,
	// which is also the order their enqueued assets print in.
	$uichemy_tb_header = do_blocks( '<!-- wp:template-part {"slug":"header"} /-->' );
	$uichemy_tb_main   = class_exists( 'UiChemy_Template_Render' ) ? UiChemy_Template_Render::get_render_html() : '';
	$uichemy_tb_footer = do_blocks( '<!-- wp:template-part {"slug":"footer"} /-->' );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<?php
	// A block theme's <title> and viewport, as core's template canvas prints them.
	if ( class_exists( 'Uich_CB_Render' ) ) {
		Uich_CB_Render::block_theme_head( true );
	}
	wp_head();
	?>
	<style>
		/* The template is the whole page between header and footer; drop the
		 * block gap core puts between .wp-site-blocks children. */
		.wp-site-blocks > .uichemy-tb-main,
		.wp-site-blocks > .uichemy-tb-main + * { margin-block-start: 0; }
	</style>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered block and builder output, echoed as-is like core's template canvas.
	echo $uichemy_tb_header;
	echo '<main class="uichemy-tb-main">' . $uichemy_tb_main . '</main>';
	echo $uichemy_tb_footer;
	// phpcs:enable
	?>
</div>
<?php wp_footer(); ?>
</body>
</html>
	<?php
	return;
}

get_header();

if ( class_exists( 'UiChemy_Template_Render' ) ) {
	// get_render_html() returns Elementor-rendered markup with the document CSS
	// inlined; it is trusted builder output, echoed as-is like Elementor's own
	// template loaders do.
	//
	// In a <main>, as on block themes: the page's main landmark (J1-03), and a
	// full-width box, because classic themes put it straight into their own
	// flex container (Astra .ast-container, GeneratePress .site-content), where
	// it shrank to its content (J1-02).
	echo '<main class="uichemy-tb-main" style="display:block;width:100%;flex:1 1 100%;min-width:0;">';
	echo UiChemy_Template_Render::get_render_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</main>';
}

get_footer();
