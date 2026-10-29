<?php
/**
 * UiChemy Canvas — a blank document: no theme header, footer or page title.
 *
 * wp_head() and wp_footer() still run, so the theme's and plugins' styles and
 * scripts load and the builder's sections render exactly as on any other page.
 *
 * The header and footer built in UiChemy's Theme Builder still show: they are
 * the site's own, set to show where their display conditions say (Entire
 * Website, these pages…), and a Canvas page that dropped them read as a bug —
 * the header set for the whole site was missing from the page and from the
 * builder's canvas. UiChemy_Locations::do_location() prints the template that
 * applies here, or nothing. Not on a template itself: a header must not be
 * drawn around the header being edited.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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
<body <?php body_class( 'uich-template-canvas' ); ?>>
<?php wp_body_open(); ?>
<?php
$uich_cb_locations = class_exists( 'UiChemy_Locations' )
	&& ! is_singular( Uich_CB_Post_Types::TEMPLATE );
if ( $uich_cb_locations ) {
	UiChemy_Locations::do_location( 'header' );
}
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
if ( $uich_cb_locations ) {
	UiChemy_Locations::do_location( 'footer' );
}
wp_footer();
?>
</body>
</html>
