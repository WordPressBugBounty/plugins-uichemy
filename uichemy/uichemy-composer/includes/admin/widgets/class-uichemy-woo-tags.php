<?php
/**
 * The <uichemy-woo-* /> tags, shared by every builder.
 *
 * These used to live inside UiChemy_Composer_Renderer, which serves the
 * Gutenberg block and the Bricks element. The Elementor widget carries its own
 * copy of the dynamic-tag pipeline (UiChemy_Composer_Widget), and that copy
 * never had the woo branch — so every woo tag silently rendered nothing in
 * Elementor, the builder most UiChemy stores are built in. Rather than paste the
 * branch a second time and leave two copies to drift, the tags live here and
 * both pipelines delegate to handles() / render().
 *
 * Two kinds of tag:
 *
 *  - SHORTCODE tags (cart, checkout, my-account, order-tracking, notices) wrap
 *    WooCommerce's own functional pages so they can be STYLED rather than
 *    replaced. UiChemy refuses to put a theme-builder template over cart,
 *    checkout or my-account precisely because replacing them breaks purchasing.
 *  - TEMPLATE tags (add-to-cart, reviews, mini-cart, thankyou) call a Woo
 *    template function with something in scope. add-to-cart is the important
 *    one: it is the only way a custom single_product template can actually sell
 *    a variable product, because the variation form is what resolves the chosen
 *    attributes into a variation id.
 *
 * Nothing here renders live Woo output in a builder preview. Woo's cart and
 * checkout read WC()->cart and the customer session, which do not exist on an
 * admin request, and a live checkout form is not something to lay out against —
 * so the editor gets a labelled placeholder of roughly the right shape.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Woo_Tags' ) ) {

	class UiChemy_Woo_Tags {

		/**
		 * Every tag type this class answers, without the "uichemy-" prefix.
		 *
		 * @var string[]
		 */
		const TYPES = array(
			'woo-cart',
			'woo-checkout',
			'woo-my-account',
			'woo-order-tracking',
			'woo-notices',
			'woo-add-to-cart',
			'woo-reviews',
			'woo-mini-cart',
			'woo-thankyou',
		);

		/**
		 * Whether this tag type belongs to us.
		 *
		 * @param string $type Tag type, e.g. 'woo-cart'.
		 * @return bool
		 */
		public static function handles( $type ) {
			return in_array( (string) $type, self::TYPES, true );
		}

		/**
		 * Render one woo tag.
		 *
		 * @param string $type      Tag type.
		 * @param string $attrs_str Raw attribute string from the tag.
		 * @param bool   $is_editor Whether rendering inside a builder preview.
		 * @return string
		 */
		public static function render( $type, $attrs_str, $is_editor ) {
			if ( ! self::handles( $type ) ) {
				return '';
			}

			// The template-function tags need a product or the session in scope;
			// the rest are WooCommerce shortcodes.
			$template_tags = array( 'woo-add-to-cart', 'woo-reviews', 'woo-mini-cart', 'woo-thankyou' );

			return in_array( $type, $template_tags, true )
				? self::render_woo_template_tag( $type, $attrs_str, $is_editor )
				: self::render_woo_tag( $type, $attrs_str, $is_editor );
		}

		/**
		 * WooCommerce shortcode tags: <uichemy-woo-cart />, -checkout, -my-account,
		 * -order-tracking, -notices.
		 *
		 * Never renders the real thing in the editor. Woo's cart and checkout read
		 * `WC()->cart` and the customer session, neither of which exists on an admin
		 * request — calling them there is a fatal, not a blank. The editor gets a
		 * labelled placeholder of roughly the right shape instead, which is also
		 * what a designer wants: a live checkout form is not something to lay out
		 * against.
		 *
		 * @param string $type      Full tag type, e.g. 'woo-cart'.
		 * @param string $attrs_str Raw attribute string from the tag.
		 * @param bool   $is_editor Whether rendering inside the Elementor editor.
		 * @return string
		 */
		private static function render_woo_tag( $type, $attrs_str, $is_editor ) {
			$map = array(
				'woo-cart'           => array( 'woocommerce_cart', 'Cart' ),
				'woo-checkout'       => array( 'woocommerce_checkout', 'Checkout' ),
				'woo-my-account'     => array( 'woocommerce_my_account', 'My Account' ),
				'woo-order-tracking' => array( 'woocommerce_order_tracking', 'Order Tracking' ),
				'woo-notices'        => array( 'woocommerce_messages', 'Store Notices' ),
			);

			if ( ! isset( $map[ $type ] ) ) {
				return '';
			}

			list( $shortcode, $label ) = $map[ $type ];

			$attr_open = $attrs_str ? ' ' . $attrs_str : '';

			if ( ! class_exists( 'WooCommerce' ) || ! shortcode_exists( $shortcode ) ) {
				// Rendering nothing on the front end is right — an empty wrapper is
				// better than a PHP notice on a site that simply has no store. The
				// editor still says why, so the tag does not look broken.
				return $is_editor
					? self::render_woo_placeholder( $label, $attr_open, 'WooCommerce is not active on this site, so this tag renders nothing.' )
					: '';
			}

			if ( $is_editor ) {
				return self::render_woo_placeholder(
					$label,
					$attr_open,
					sprintf( 'WooCommerce renders %s here on the front end.', strtolower( $label ) )
				);
			}

			$html = do_shortcode( '[' . $shortcode . ']' );

			if ( '' === trim( (string) $html ) ) {
				return '';
			}

			return $attrs_str ? '<div' . $attr_open . '>' . $html . '</div>' : $html;
		}

		/**
		 * The editor stand-in for a WooCommerce tag.
		 *
		 * Styled inline rather than through a stylesheet: this markup only ever
		 * exists inside the editor preview, so a class would need a rule shipped to
		 * the front end that nothing there would use.
		 *
		 * @param string $label     Human label, e.g. 'Checkout'.
		 * @param string $attr_open Leading-space attribute string, or ''.
		 * @param string $note      One line explaining what happens on the front end.
		 * @return string
		 */
		private static function render_woo_placeholder( $label, $attr_open, $note ) {
			return sprintf(
				'<div%s><div style="border:1px dashed currentColor;border-radius:6px;padding:24px;text-align:center;opacity:.65;font:500 14px/1.5 system-ui,sans-serif">'
					. '<div style="font-weight:600;margin-bottom:4px">WooCommerce: %s</div><div style="font-size:12px">%s</div></div></div>',
				$attr_open,
				esc_html( $label ),
				esc_html( $note )
			);
		}

		/**
		 * WooCommerce TEMPLATE tags — the ones that need a product in scope and a
		 * template function rather than a shortcode:
		 *
		 *   <uichemy-woo-add-to-cart />            the product in scope
		 *   <uichemy-woo-add-to-cart id="42" />    a named product
		 *   <uichemy-woo-reviews />
		 *   <uichemy-woo-mini-cart />
		 *
		 * `woo-add-to-cart` is the one tag a custom single-product template cannot
		 * do without. Every other part of a product page can be rebuilt from
		 * `product.*` tokens, but the add-to-cart FORM cannot: a variable product
		 * needs Woo's variation form to resolve the chosen attributes into a
		 * variation id, apply availability, and post to the cart handler. Rendering
		 * swatches from `product.variations` and linking them somewhere is not a
		 * substitute — it produces a product page that looks complete and cannot
		 * take an order.
		 *
		 * The type dispatch is Woo's own: woocommerce_template_single_add_to_cart()
		 * fires `woocommerce_{type}_add_to_cart`, so simple, variable, grouped and
		 * external each get their correct form, and a product type added by an
		 * extension works without a change here.
		 *
		 * @param string $type      Full tag type, e.g. 'woo-add-to-cart'.
		 * @param string $attrs_str Raw attribute string from the tag.
		 * @param bool   $is_editor Whether rendering inside the Elementor editor.
		 * @return string
		 */
		private static function render_woo_template_tag( $type, $attrs_str, $is_editor ) {
			$labels = array(
				'woo-add-to-cart' => 'Add to Cart form',
				'woo-reviews'     => 'Reviews',
				'woo-mini-cart'   => 'Mini Cart',
				'woo-thankyou'    => 'Thank You / Order Confirmation',
			);

			$label     = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
			$attr_open = $attrs_str ? ' ' . $attrs_str : '';

			if ( ! class_exists( 'WooCommerce' ) ) {
				// A site with no store renders nothing on the front end rather than
				// a notice; the editor still explains the blank.
				return $is_editor
					? self::render_woo_placeholder( $label, $attr_open, 'WooCommerce is not active on this site, so this tag renders nothing.' )
					: '';
			}

			// The mini cart needs no product, and reads the session rather than the
			// query — so it is handled before any product lookup.
			if ( 'woo-mini-cart' === $type ) {
				if ( $is_editor || ! function_exists( 'woocommerce_mini_cart' ) ) {
					return $is_editor
						? self::render_woo_placeholder( $label, $attr_open, 'WooCommerce renders the mini cart here on the front end.' )
						: '';
				}
				return self::wrap_woo_output( self::capture( 'woocommerce_mini_cart' ), $attrs_str, $attr_open );
			}

			// The thank-you block reads the order from the endpoint, not a product.
			if ( 'woo-thankyou' === $type ) {
				if ( $is_editor ) {
					return self::render_woo_placeholder( $label, $attr_open, 'WooCommerce renders the order confirmation here, on the order-received page only.' );
				}
				return self::wrap_woo_output( self::capture_thankyou(), $attrs_str, $attr_open );
			}

			$product = self::resolve_tag_product( $attrs_str );

			if ( ! $product ) {
				return $is_editor
					? self::render_woo_placeholder( $label, $attr_open, 'No product in scope. Use this tag in a Single Product template, or pass id="123".' )
					: '';
			}

			if ( $is_editor ) {
				// Woo's variation form enqueues scripts and reads availability from
				// a front-end request; a live one is also not something to lay out
				// against. Same reasoning as render_woo_tag().
				return self::render_woo_placeholder(
					$label,
					$attr_open,
					sprintf( 'WooCommerce renders the %s for "%s" here on the front end.', strtolower( $label ), $product->get_name() )
				);
			}

			$html = ( 'woo-reviews' === $type )
				? self::capture_product_reviews( $product )
				: self::capture_add_to_cart( $product );

			return self::wrap_woo_output( $html, $attrs_str, $attr_open );
		}

		/**
		 * Which product a template tag refers to.
		 *
		 * `id="123"` (or `data-id="123"`) wins, so the tag works inside a loop card
		 * or a quick-view panel. Otherwise it is the product in scope — the global
		 * `$product` Woo sets up on a product page, falling back to the queried
		 * post, which is what a Single Product template renders against.
		 *
		 * @param string $attrs_str Raw attribute string from the tag.
		 * @return \WC_Product|null
		 */
		private static function resolve_tag_product( $attrs_str ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return null;
			}

			$id = 0;
			if ( $attrs_str && preg_match( '/\b(?:data-)?id\s*=\s*["\']?(\d+)/i', $attrs_str, $m ) ) {
				$id = (int) $m[1];
			}

			if ( ! $id ) {
				if ( isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof \WC_Product ) {
					return $GLOBALS['product'];
				}
				$id = (int) get_the_ID();
			}

			if ( ! $id ) {
				return null;
			}

			$product = wc_get_product( $id );
			return $product instanceof \WC_Product ? $product : null;
		}

		/**
		 * Woo's add-to-cart form for one product, with the product swapped into
		 * scope and put back afterwards.
		 *
		 * The global is restored even on a template that throws, so a broken
		 * override in a child theme cannot leave the rest of the page rendering
		 * against the wrong product.
		 *
		 * @param \WC_Product $product Product.
		 * @return string
		 */
		private static function capture_add_to_cart( $product ) {
			if ( ! function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
				return '';
			}

			$prev_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
			$prev_post    = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

			$GLOBALS['product'] = $product;
			$post               = get_post( $product->get_id() );
			if ( $post instanceof \WP_Post ) {
				$GLOBALS['post'] = $post;
			}

			try {
				$html = self::capture( 'woocommerce_template_single_add_to_cart' );
			} finally {
				$GLOBALS['product'] = $prev_product;
				$GLOBALS['post']    = $prev_post;
			}

			return $html;
		}

		/**
		 * Woo's review list + review form for one product.
		 *
		 * Goes through comments_template() rather than calling Woo's template
		 * directly, because that is the path Woo's own single-product template
		 * takes: Woo swaps in single-product-reviews.php through the
		 * `comments_template` filter, and the pagination and comment-form state
		 * come from WordPress. `$withcomments` is forced on because
		 * comments_template() otherwise refuses to run when the main query is not a
		 * singular one — which is the case inside a UiChemy template body.
		 *
		 * @param \WC_Product $product Product.
		 * @return string
		 */
		private static function capture_product_reviews( $product ) {
			$post = get_post( $product->get_id() );
			if ( ! $post instanceof \WP_Post || ! comments_open( $post->ID ) ) {
				return '';
			}

			$prev_product      = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
			$prev_post         = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
			$prev_withcomments = isset( $GLOBALS['withcomments'] ) ? $GLOBALS['withcomments'] : null;

			$GLOBALS['product']      = $product;
			$GLOBALS['post']         = $post;
			$GLOBALS['withcomments'] = true;
			setup_postdata( $post );

			try {
				$html = self::capture( 'comments_template' );
			} finally {
				$GLOBALS['product']      = $prev_product;
				$GLOBALS['post']         = $prev_post;
				$GLOBALS['withcomments'] = $prev_withcomments;
				wp_reset_postdata();
			}

			return $html;
		}

		/**
		 * Woo's thank-you block for the order this request just completed.
		 *
		 * The markup is the smaller half of why this exists. The other half is the
		 * `woocommerce_thankyou` action, which Woo's own thankyou.php fires and
		 * which carries things a store cannot lose: bank-transfer instructions for
		 * BACS, a gateway's own confirmation notice, and every conversion pixel a
		 * site has hooked there. An order_received template built only from
		 * `order.*` tokens looks complete and silently drops all of it.
		 *
		 * Renders nothing anywhere but the order-received endpoint, and nothing at
		 * all unless the request is entitled to the order — the same two grounds
		 * Uich_Order_Provider uses, enforced here by Woo's own template, which
		 * checks the order key itself.
		 *
		 * @return string
		 */
		private static function capture_thankyou() {
			if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
				return '';
			}
			if ( ! function_exists( 'wc_get_template' ) || ! function_exists( 'wc_get_order' ) ) {
				return '';
			}

			global $wp;
			$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
			if ( ! $order_id ) {
				return '';
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return '';
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a receipt link; the order key is the credential.
			$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			$own = get_current_user_id() && (int) $order->get_customer_id() === get_current_user_id();
			if ( ! $own && ! hash_equals( (string) $order->get_order_key(), $key ) ) {
				return '';
			}

			ob_start();
			try {
				wc_get_template( 'checkout/thankyou.php', array( 'order' => $order ) );
			} finally {
				$html = ob_get_clean();
			}
			return (string) $html;
		}

		/**
		 * Run a callable and return what it echoed.
		 *
		 * @param callable $fn Callable that echoes.
		 * @return string
		 */
		private static function capture( $fn ) {
			ob_start();
			try {
				call_user_func( $fn );
			} finally {
				$html = ob_get_clean();
			}
			return (string) $html;
		}

		/**
		 * Wrap captured WooCommerce output the same way render_woo_tag() does: the
		 * tag's own attributes become a wrapper div, and empty output stays empty
		 * rather than leaving a stray element on the page.
		 *
		 * @param string $html      Captured markup.
		 * @param string $attrs_str Raw attribute string.
		 * @param string $attr_open Leading-space attribute string, or ''.
		 * @return string
		 */
		private static function wrap_woo_output( $html, $attrs_str, $attr_open ) {
			if ( '' === trim( (string) $html ) ) {
				return '';
			}
			return $attrs_str ? '<div' . $attr_open . '>' . $html . '</div>' : $html;
		}
	}
}
