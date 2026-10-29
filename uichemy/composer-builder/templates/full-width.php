<?php
/**
 * UiChemy Full width — the theme's header and footer, the page's sections
 * edge to edge in between, and no page title.
 *
 * Classic themes print their header and footer through get_header() and
 * get_footer(). Block themes have no header.php: their header and footer are
 * template parts, so they are rendered as such inside the same document
 * scaffold a block template uses.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uich_cb_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

if ( $uich_cb_block_theme ) :
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php
	Uich_CB_Render::block_theme_head(); // A block theme's <title>.
	wp_head();
	?>
</head>
<body <?php body_class( 'uich-template-full-width' ); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php block_template_part( 'header' ); ?>
	<?php
else :
	add_filter(
		'body_class',
		static function ( $classes ) {
			$classes[] = 'uich-template-full-width';
			return $classes;
		}
	);
	get_header();
endif;
?>
<main id="content" class="uich-template-main">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>
<?php
if ( $uich_cb_block_theme ) :
	block_template_part( 'footer' );
	?>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
else :
	get_footer();
endif;
