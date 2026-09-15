<?php
/**
 * Dynamic-token catalog — GENERATED FILE, DO NOT EDIT BY HAND.
 *
 * Source:    uichemy-composer/assets/js/uich-dd-schema.js
 * Generator: new-dashboard/tools/build-dd-catalog.js  (npm run catalog)
 *
 * The picker schema is the list a human sees when building a binding by
 * clicking. This is that same list in PHP, so an MCP agent can be handed the
 * real token names rather than guessing them — a guessed binding renders empty
 * instead of failing, which is the worst way for it to be wrong.
 *
 * Editing this file directly will be overwritten on the next generate. Add the
 * field to uich-dd-schema.js and re-run the generator instead.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_DD_Catalog' ) ) {

	/**
	 * The dynamic tokens this build can resolve, by provider.
	 */
	class Uich_DD_Catalog {

		/**
		 * Every provider with its fields.
		 *
		 * `extends` means the provider also answers everything the named one does
		 * (a product is a post), so a consumer must walk the chain to list it all —
		 * tokens() does that.
		 *
		 * @return array<string,array>
		 */
		public static function providers() {
			return array(
				'post'    => array(
					'label'   => 'This Post',
					'extends' => null,
					'fields'  => array(
						array(
							'key'   => 'title',
							'label' => 'Title',
							'type'  => 'text',
						),
						array(
							'key'   => 'content',
							'label' => 'Content',
							'type'  => 'html',
						),
						array(
							'key'   => 'excerpt',
							'label' => 'Excerpt',
							'type'  => 'text',
							// Documented as plain "Excerpt", it reads as the
							// visible summary of the post. It is not: it is the
							// post_excerpt COLUMN, which is empty on every post
							// whose text lives in a Composer widget - so the
							// token renders blank on a page that plainly has
							// text on it, and that looks like a broken binding.
							// One verification build shipped a whole menu page
							// of blank descriptions this way.
							'note'  => 'Reads the post_excerpt column, NOT the visible content. It is EMPTY on a Composer-authored post unless an excerpt was set explicitly, and renders blank. Set one with uichemy-composer/post (action="update", "excerpt"), or print post.content, or hold the summary in a custom field.',
						),
						array(
							'key'   => 'link',
							'label' => 'Link',
							'type'  => 'url',
						),
						array(
							'key'   => 'date',
							'label' => 'Date',
							'type'  => 'date',
							'args'  => array( 'format' ),
						),
						array(
							'key'   => 'id',
							'label' => 'ID',
							'type'  => 'number',
						),
						array(
							'key'   => 'slug',
							'label' => 'Slug',
							'type'  => 'text',
						),
						array(
							'key'   => 'status',
							'label' => 'Status',
							'type'  => 'text',
						),
						array(
							'key'   => 'comment_count',
							'label' => 'Comment count',
							'type'  => 'number',
						),
						array(
							'key'       => 'author',
							'label'     => 'Author',
							'type'      => 'provider',
							'chains_to' => 'user',
						),
						array(
							'key'       => 'thumbnail',
							'label'     => 'Featured image',
							'type'      => 'provider',
							'chains_to' => 'image',
						),
						array(
							'key'       => 'categories',
							'label'     => 'Categories',
							'type'      => 'array',
							'chains_to' => 'term',
						),
						array(
							'key'       => 'tags',
							'label'     => 'Tags',
							'type'      => 'array',
							'chains_to' => 'term',
						),
						array(
							'key'       => 'prev',
							'label'     => 'Previous post',
							'type'      => 'provider',
							'chains_to' => 'post',
						),
						array(
							'key'       => 'next',
							'label'     => 'Next post',
							'type'      => 'provider',
							'chains_to' => 'post',
						),
						array(
							'key'   => 'meta',
							'label' => 'Custom field…',
							'type'  => 'text',
							'args'  => array( 'key' ),
						),
					),
				),
				'product' => array(
					'label'   => 'This Product',
					'extends' => 'post',
					'fields'  => array(
						array(
							'key'   => 'price',
							'label' => 'Price (raw number)',
							'type'  => 'number',
						),
						array(
							'key'   => 'price_html',
							'label' => 'Price (formatted)',
							'type'  => 'html',
						),
						array(
							'key'   => 'regular_price',
							'label' => 'Regular price',
							'type'  => 'number',
						),
						array(
							'key'   => 'sale_price',
							'label' => 'Sale price',
							'type'  => 'number',
						),
						array(
							'key'   => 'sale_percentage',
							'label' => 'Discount %',
							'type'  => 'number',
						),
						array(
							'key'   => 'is_on_sale',
							'label' => 'Is on sale?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'sale_from',
							'label' => 'Sale starts',
							'type'  => 'date',
							'args'  => array( 'format' ),
						),
						array(
							'key'   => 'sale_to',
							'label' => 'Sale ends',
							'type'  => 'date',
							'args'  => array( 'format' ),
						),
						array(
							'key'   => 'currency',
							'label' => 'Currency code',
							'type'  => 'text',
						),
						array(
							'key'   => 'currency_symbol',
							'label' => 'Currency symbol',
							'type'  => 'text',
						),
						array(
							'key'   => 'sku',
							'label' => 'SKU',
							'type'  => 'text',
						),
						array(
							'key'   => 'type',
							'label' => 'Product type (simple / variable…)',
							'type'  => 'text',
						),
						array(
							'key'   => 'is_featured',
							'label' => 'Featured?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'is_downloadable',
							'label' => 'Downloadable?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'is_virtual',
							'label' => 'Virtual?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'stock_status',
							'label' => 'Stock status',
							'type'  => 'text',
						),
						array(
							'key'   => 'stock_quantity',
							'label' => 'Stock quantity',
							'type'  => 'number',
						),
						array(
							'key'   => 'is_in_stock',
							'label' => 'In stock?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'is_on_backorder',
							'label' => 'On backorder?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'availability',
							'label' => 'Availability text',
							'type'  => 'text',
						),
						array(
							'key'   => 'short_description',
							'label' => 'Short description',
							'type'  => 'html',
						),
						array(
							'key'   => 'description',
							'label' => 'Description',
							'type'  => 'html',
						),
						array(
							'key'   => 'purchase_note',
							'label' => 'Purchase note',
							'type'  => 'html',
						),
						array(
							'key'       => 'categories',
							'label'     => 'Product categories',
							'type'      => 'array',
							'chains_to' => 'term',
						),
						array(
							'key'       => 'tags',
							'label'     => 'Product tags',
							'type'      => 'array',
							'chains_to' => 'term',
						),
						array(
							'key'       => 'brand',
							'label'     => 'Brands',
							'type'      => 'array',
							'chains_to' => 'term',
						),
						array(
							'key'   => 'category_list',
							'label' => 'Categories (linked list)',
							'type'  => 'html',
						),
						array(
							'key'   => 'tag_list',
							'label' => 'Tags (linked list)',
							'type'  => 'html',
						),
						array(
							'key'   => 'attribute',
							'label' => 'Attribute…',
							'type'  => 'text',
							'args'  => array( 'name' ),
						),
						array(
							'key'   => 'attributes',
							'label' => 'All attributes (label → value)',
							'type'  => 'array',
						),
						array(
							'key'   => 'add_to_cart_url',
							'label' => 'Add-to-cart URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'add_to_cart_text',
							'label' => 'Add-to-cart label',
							'type'  => 'text',
						),
						array(
							'key'   => 'is_purchasable',
							'label' => 'Purchasable?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'cart_url',
							'label' => 'Cart URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'checkout_url',
							'label' => 'Checkout URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'shop_url',
							'label' => 'Shop URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'average_rating',
							'label' => 'Average rating',
							'type'  => 'number',
						),
						array(
							'key'   => 'review_count',
							'label' => 'Review count',
							'type'  => 'number',
						),
						array(
							'key'   => 'rating_html',
							'label' => 'Star rating (markup)',
							'type'  => 'html',
						),
						array(
							'key'   => 'reviews_allowed',
							'label' => 'Reviews allowed?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'total_sales',
							'label' => 'Total sales',
							'type'  => 'number',
						),
						array(
							'key'   => 'weight',
							'label' => 'Weight',
							'type'  => 'text',
						),
						array(
							'key'   => 'length',
							'label' => 'Length',
							'type'  => 'text',
						),
						array(
							'key'   => 'width',
							'label' => 'Width',
							'type'  => 'text',
						),
						array(
							'key'   => 'height',
							'label' => 'Height',
							'type'  => 'text',
						),
						array(
							'key'   => 'dimensions',
							'label' => 'Dimensions (formatted)',
							'type'  => 'text',
						),
						array(
							'key'       => 'related',
							'label'     => 'Related products',
							'type'      => 'array',
							'chains_to' => 'product',
							'args'      => array( 'limit' ),
						),
						array(
							'key'       => 'upsells',
							'label'     => 'Upsells',
							'type'      => 'array',
							'chains_to' => 'product',
						),
						array(
							'key'       => 'cross_sells',
							'label'     => 'Cross-sells',
							'type'      => 'array',
							'chains_to' => 'product',
						),
						array(
							'key'       => 'variations',
							'label'     => 'Variations',
							'type'      => 'array',
							'chains_to' => 'product',
						),
						array(
							'key'       => 'gallery',
							'label'     => 'Gallery',
							'type'      => 'array',
							'chains_to' => 'image',
						),
						array(
							'key'       => 'reviews',
							'label'     => 'Reviews…',
							'type'      => 'array',
							'chains_to' => 'comment',
							'args'      => array( 'count' ),
						),
					),
				),
				'user'    => array(
					'label'   => 'Current User',
					'extends' => null,
					'fields'  => array(
						array(
							'key'   => 'name',
							'label' => 'Display name',
							'type'  => 'text',
						),
						array(
							'key'   => 'id',
							'label' => 'ID',
							'type'  => 'number',
						),
						array(
							'key'   => 'url',
							'label' => 'Website',
							'type'  => 'url',
						),
						array(
							'key'   => 'bio',
							'label' => 'Bio',
							'type'  => 'text',
						),
						array(
							'key'   => 'link',
							'label' => 'Posts URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'avatar',
							'label' => 'Avatar URL',
							'type'  => 'url',
							'args'  => array( 'size' ),
						),
						array(
							'key'   => 'email',
							'label' => 'Email (admins)',
							'type'  => 'text',
						),
						array(
							'key'   => 'meta',
							'label' => 'Custom field…',
							'type'  => 'text',
							'args'  => array( 'key' ),
						),
						array(
							'key'       => 'orders',
							'label'     => 'WooCommerce orders…',
							'type'      => 'array',
							'chains_to' => 'order',
							'args'      => array( 'count' ),
						),
					),
				),
				'term'    => array(
					'label'   => 'This Term',
					'extends' => null,
					'fields'  => array(
						array(
							'key'   => 'name',
							'label' => 'Name',
							'type'  => 'text',
						),
						array(
							'key'   => 'id',
							'label' => 'ID',
							'type'  => 'number',
						),
						array(
							'key'   => 'slug',
							'label' => 'Slug',
							'type'  => 'text',
						),
						array(
							'key'   => 'description',
							'label' => 'Description',
							'type'  => 'text',
						),
						array(
							'key'   => 'count',
							'label' => 'Post count',
							'type'  => 'number',
						),
						array(
							'key'   => 'link',
							'label' => 'Link',
							'type'  => 'url',
						),
					),
				),
				'image'   => array(
					'label'   => 'Image',
					'extends' => null,
					'fields'  => array(
						array(
							'key'   => 'src',
							'label' => 'URL',
							'type'  => 'url',
							'args'  => array( 'size' ),
						),
						array(
							'key'   => 'alt',
							'label' => 'Alt text',
							'type'  => 'text',
						),
						array(
							'key'   => 'width',
							'label' => 'Width',
							'type'  => 'number',
						),
						array(
							'key'   => 'height',
							'label' => 'Height',
							'type'  => 'number',
						),
						array(
							'key'   => 'caption',
							'label' => 'Caption',
							'type'  => 'text',
						),
					),
				),
				'site'    => array(
					'label'   => 'Site',
					'extends' => null,
					'fields'  => array(
						array(
							'key'   => 'name',
							'label' => 'Name',
							'type'  => 'text',
						),
						array(
							'key'   => 'description',
							'label' => 'Tagline',
							'type'  => 'text',
						),
						array(
							'key'   => 'url',
							'label' => 'Home URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'language',
							'label' => 'Language',
							'type'  => 'text',
						),
						array(
							'key'       => 'icon',
							'label'     => 'Site icon',
							'type'      => 'provider',
							'chains_to' => 'image',
						),
						array(
							'key'       => 'logo',
							'label'     => 'Logo',
							'type'      => 'provider',
							'chains_to' => 'image',
						),
						array(
							'key'   => 'option',
							'label' => 'Option…',
							'type'  => 'text',
							'args'  => array( 'key' ),
						),
					),
				),
				'request' => array(
					'label'   => 'URL / Request',
					'extends' => null,
					'fields'  => array(
						array(
							'key'   => 'get',
							'label' => 'Query parameter…',
							'type'  => 'text',
							'args'  => array( 'key' ),
						),
						array(
							'key'   => 'url',
							'label' => 'Current URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'referrer',
							'label' => 'Referrer',
							'type'  => 'url',
						),
					),
				),
				'cart'      => array(
					'label'   => 'Cart',
					'extends' => null,
					'fields'  => array(
						array(
							'key'   => 'count',
							'label' => 'Item count (units)',
							'type'  => 'number',
						),
						array(
							'key'   => 'lines',
							'label' => 'Line count',
							'type'  => 'number',
						),
						array(
							'key'   => 'is_empty',
							'label' => 'Is empty?',
							'type'  => 'bool',
						),
						array(
							'key'       => 'items',
							'label'     => 'Items',
							'type'      => 'array',
							'chains_to' => 'cart_item',
						),
						array(
							'key'   => 'total',
							'label' => 'Total (formatted)',
							'type'  => 'html',
						),
						array(
							'key'   => 'total_raw',
							'label' => 'Total (raw number)',
							'type'  => 'number',
						),
						array(
							'key'   => 'subtotal',
							'label' => 'Subtotal (formatted)',
							'type'  => 'html',
						),
						array(
							'key'   => 'subtotal_raw',
							'label' => 'Subtotal (raw number)',
							'type'  => 'number',
						),
						array(
							'key'   => 'discount_total',
							'label' => 'Discount total',
							'type'  => 'html',
						),
						array(
							'key'   => 'discount_total_raw',
							'label' => 'Discount total (raw)',
							'type'  => 'number',
						),
						array(
							'key'   => 'shipping_total',
							'label' => 'Shipping total',
							'type'  => 'html',
						),
						array(
							'key'   => 'shipping_total_raw',
							'label' => 'Shipping total (raw)',
							'type'  => 'number',
						),
						array(
							'key'   => 'tax_total',
							'label' => 'Tax total',
							'type'  => 'html',
						),
						array(
							'key'   => 'tax_total_raw',
							'label' => 'Tax total (raw)',
							'type'  => 'number',
						),
						array(
							'key'   => 'fee_total',
							'label' => 'Fee total',
							'type'  => 'html',
						),
						array(
							'key'   => 'needs_shipping',
							'label' => 'Needs shipping?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'coupons',
							'label' => 'Applied coupon codes',
							'type'  => 'array',
						),
						array(
							'key'   => 'free_shipping_min',
							'label' => 'Free-shipping threshold',
							'type'  => 'number',
						),
						array(
							'key'   => 'free_shipping_remaining',
							'label' => 'Amount left for free shipping',
							'type'  => 'number',
						),
						array(
							'key'   => 'free_shipping_progress',
							'label' => 'Free-shipping progress (0-100)',
							'type'  => 'number',
						),
						array(
							'key'   => 'has_free_shipping',
							'label' => 'Qualifies for free shipping?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'currency',
							'label' => 'Currency code',
							'type'  => 'text',
						),
						array(
							'key'   => 'currency_symbol',
							'label' => 'Currency symbol',
							'type'  => 'text',
						),
						array(
							'key'   => 'url',
							'label' => 'Cart URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'checkout_url',
							'label' => 'Checkout URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'shop_url',
							'label' => 'Shop URL',
							'type'  => 'url',
						),
					),
				),
				'cart_item' => array(
					'label'   => 'Cart Item',
					'extends' => null,
					'fields'  => array(
						array(
							'key'       => 'product',
							'label'     => 'Product (the bought variation)',
							'type'      => 'provider',
							'chains_to' => 'product',
						),
						array(
							'key'   => 'name',
							'label' => 'Name',
							'type'  => 'text',
						),
						array(
							'key'   => 'quantity',
							'label' => 'Quantity',
							'type'  => 'number',
						),
						array(
							'key'   => 'variation',
							'label' => 'Chosen options (label => value)',
							'type'  => 'array',
						),
						array(
							'key'   => 'price',
							'label' => 'Unit price (formatted)',
							'type'  => 'html',
						),
						array(
							'key'   => 'price_raw',
							'label' => 'Unit price (raw)',
							'type'  => 'number',
						),
						array(
							'key'   => 'subtotal',
							'label' => 'Line subtotal (formatted)',
							'type'  => 'html',
						),
						array(
							'key'   => 'line_total',
							'label' => 'Line total (formatted)',
							'type'  => 'html',
						),
						array(
							'key'   => 'line_total_raw',
							'label' => 'Line total (raw)',
							'type'  => 'number',
						),
						array(
							'key'   => 'line_subtotal',
							'label' => 'Line subtotal before discount',
							'type'  => 'html',
						),
						array(
							'key'   => 'line_subtotal_raw',
							'label' => 'Line subtotal before discount (raw)',
							'type'  => 'number',
						),
						array(
							'key'       => 'thumbnail',
							'label'     => 'Thumbnail',
							'type'      => 'image',
							'chains_to' => 'image',
						),
						array(
							'key'   => 'sku',
							'label' => 'SKU',
							'type'  => 'text',
						),
						array(
							'key'   => 'in_stock',
							'label' => 'In stock?',
							'type'  => 'bool',
						),
						array(
							'key'   => 'permalink',
							'label' => 'Product URL (options preselected)',
							'type'  => 'url',
						),
						array(
							'key'   => 'remove_url',
							'label' => 'Remove-from-cart URL',
							'type'  => 'url',
						),
						array(
							'key'   => 'key',
							'label' => 'Cart item key',
							'type'  => 'text',
						),
						array(
							'key'   => 'id',
							'label' => 'Variation / product ID',
							'type'  => 'number',
						),
						array(
							'key'   => 'product_id',
							'label' => 'Parent product ID',
							'type'  => 'number',
						),
						array(
							'key'   => 'variation_id',
							'label' => 'Variation ID (0 if simple)',
							'type'  => 'number',
						),
					),
				),
				'order'      => array(
					'label'   => 'Order',
					'extends' => null,
					'fields'  => array(
						array( 'key' => 'number', 'label' => 'Order number', 'type' => 'text' ),
						array( 'key' => 'id', 'label' => 'Order ID', 'type' => 'number' ),
						array( 'key' => 'status', 'label' => 'Status (slug)', 'type' => 'text' ),
						array( 'key' => 'status_label', 'label' => 'Status (label)', 'type' => 'text' ),
						array( 'key' => 'date', 'label' => 'Date placed', 'type' => 'date' ),
						array( 'key' => 'date_paid', 'label' => 'Date paid', 'type' => 'date' ),
						array( 'key' => 'date_completed', 'label' => 'Date completed', 'type' => 'date' ),
						array(
							'key'       => 'items',
							'label'     => 'Items',
							'type'      => 'array',
							'chains_to' => 'order_item',
						),
						array( 'key' => 'item_count', 'label' => 'Item count', 'type' => 'number' ),
						array( 'key' => 'total', 'label' => 'Total (formatted)', 'type' => 'html' ),
						array( 'key' => 'total_raw', 'label' => 'Total (raw)', 'type' => 'number' ),
						array( 'key' => 'subtotal', 'label' => 'Subtotal (formatted)', 'type' => 'html' ),
						array( 'key' => 'subtotal_raw', 'label' => 'Subtotal (raw)', 'type' => 'number' ),
						array( 'key' => 'discount_total', 'label' => 'Discount total', 'type' => 'html' ),
						array( 'key' => 'shipping_total', 'label' => 'Shipping total', 'type' => 'html' ),
						array( 'key' => 'tax_total', 'label' => 'Tax total', 'type' => 'html' ),
						array( 'key' => 'fee_total', 'label' => 'Fee total', 'type' => 'html' ),
						array( 'key' => 'currency', 'label' => 'Currency code', 'type' => 'text' ),
						array( 'key' => 'payment_method_title', 'label' => 'Payment method', 'type' => 'text' ),
						array( 'key' => 'payment_method', 'label' => 'Payment gateway (slug)', 'type' => 'text' ),
						array( 'key' => 'is_paid', 'label' => 'Is paid?', 'type' => 'bool' ),
						array( 'key' => 'needs_payment', 'label' => 'Needs payment?', 'type' => 'bool' ),
						array( 'key' => 'pay_url', 'label' => 'Pay-now URL', 'type' => 'url' ),
						array( 'key' => 'view_url', 'label' => 'View-order URL', 'type' => 'url' ),
						array( 'key' => 'billing_name', 'label' => 'Billing name', 'type' => 'text' ),
						array( 'key' => 'billing_email', 'label' => 'Billing email', 'type' => 'text' ),
						array( 'key' => 'billing_phone', 'label' => 'Billing phone', 'type' => 'text' ),
						array( 'key' => 'billing_address', 'label' => 'Billing address (formatted)', 'type' => 'html' ),
						array( 'key' => 'billing', 'label' => 'Billing fields (map)', 'type' => 'array' ),
						array( 'key' => 'shipping_name', 'label' => 'Shipping name', 'type' => 'text' ),
						array( 'key' => 'shipping_address', 'label' => 'Shipping address (formatted)', 'type' => 'html' ),
						array( 'key' => 'shipping', 'label' => 'Shipping fields (map)', 'type' => 'array' ),
						array( 'key' => 'shipping_method', 'label' => 'Shipping method', 'type' => 'text' ),
						array( 'key' => 'needs_shipping_address', 'label' => 'Needs shipping address?', 'type' => 'bool' ),
						array( 'key' => 'customer_note', 'label' => 'Customer note', 'type' => 'text' ),
						array( 'key' => 'customer_id', 'label' => 'Customer ID (0 = guest)', 'type' => 'number' ),
						array(
							'key'       => 'customer',
							'label'     => 'Customer',
							'type'      => 'provider',
							'chains_to' => 'user',
						),
						array( 'key' => 'coupons', 'label' => 'Coupon codes used', 'type' => 'array' ),
						array( 'key' => 'downloads', 'label' => 'Downloadable files', 'type' => 'array' ),
					),
				),
				'order_item' => array(
					'label'   => 'Order Item',
					'extends' => null,
					'fields'  => array(
						array( 'key' => 'name', 'label' => 'Name as billed', 'type' => 'text' ),
						array( 'key' => 'parent_name', 'label' => 'Product name (no options)', 'type' => 'text' ),
						array( 'key' => 'quantity', 'label' => 'Quantity', 'type' => 'number' ),
						array( 'key' => 'variation', 'label' => 'Options / line meta (map)', 'type' => 'array' ),
						array( 'key' => 'total', 'label' => 'Line total (formatted)', 'type' => 'html' ),
						array( 'key' => 'total_raw', 'label' => 'Line total (raw)', 'type' => 'number' ),
						array( 'key' => 'subtotal_raw', 'label' => 'Line subtotal before discount (raw)', 'type' => 'number' ),
						array( 'key' => 'tax_raw', 'label' => 'Line tax (raw)', 'type' => 'number' ),
						array( 'key' => 'sku', 'label' => 'SKU', 'type' => 'text' ),
						array(
							'key'       => 'product',
							'label'     => 'Product (null if deleted)',
							'type'      => 'provider',
							'chains_to' => 'product',
						),
						array(
							'key'       => 'thumbnail',
							'label'     => 'Thumbnail',
							'type'      => 'image',
							'chains_to' => 'image',
						),
						array( 'key' => 'permalink', 'label' => 'Product URL', 'type' => 'url' ),
						array( 'key' => 'downloads', 'label' => 'Downloads for this line', 'type' => 'array' ),
						array( 'key' => 'id', 'label' => 'Line item ID', 'type' => 'number' ),
						array( 'key' => 'product_id', 'label' => 'Product ID', 'type' => 'number' ),
						array( 'key' => 'variation_id', 'label' => 'Variation ID (0 if simple)', 'type' => 'number' ),
					),
				),
				'comment'    => array(
					'label'   => 'Comment / Review',
					'extends' => null,
					'fields'  => array(
						array( 'key' => 'author', 'label' => 'Author name', 'type' => 'text' ),
						array( 'key' => 'content', 'label' => 'Content (formatted)', 'type' => 'html' ),
						array( 'key' => 'content_raw', 'label' => 'Content (plain)', 'type' => 'text' ),
						array( 'key' => 'excerpt', 'label' => 'Excerpt…', 'type' => 'text', 'args' => array( 'words' ) ),
						array( 'key' => 'rating', 'label' => 'Star rating (0-5)', 'type' => 'number' ),
						array( 'key' => 'rating_html', 'label' => 'Star rating (markup)', 'type' => 'html' ),
						array( 'key' => 'verified', 'label' => 'Verified owner?', 'type' => 'bool' ),
						array( 'key' => 'date', 'label' => 'Date', 'type' => 'date' ),
						array( 'key' => 'avatar', 'label' => 'Avatar URL…', 'type' => 'url', 'args' => array( 'size' ) ),
						array( 'key' => 'author_url', 'label' => 'Author website', 'type' => 'url' ),
						array(
							'key'       => 'author_user',
							'label'     => 'Author (if registered)',
							'type'      => 'provider',
							'chains_to' => 'user',
						),
						array( 'key' => 'link', 'label' => 'Permalink to comment', 'type' => 'url' ),
						array( 'key' => 'id', 'label' => 'Comment ID', 'type' => 'number' ),
						array( 'key' => 'post_id', 'label' => 'Post/product ID', 'type' => 'number' ),
						array( 'key' => 'type', 'label' => 'Comment type', 'type' => 'text' ),
						array(
							'key'       => 'parent',
							'label'     => 'Parent comment',
							'type'      => 'provider',
							'chains_to' => 'comment',
						),
						array( 'key' => 'meta', 'label' => 'Comment meta…', 'type' => 'text', 'args' => array( 'key' ) ),
					),
				),
			);
		}

		/**
		 * One provider's tokens, ready to print — inherited fields included.
		 *
		 * @param string $provider Provider key, e.g. "product".
		 * @return array[] Each: token, label, type, [chains_to], [args].
		 */
		public static function tokens( $provider ) {
			$all = self::providers();
			$key = (string) $provider;
			if ( ! isset( $all[ $key ] ) ) {
				return array();
			}

			// Walk `extends` first so an inherited field keeps its position, then let
			// the provider's OWN entry for the same key replace it — product.categories
			// reads product_cat, not the post `category` the base entry describes.
			$own = array();
			foreach ( $all[ $key ]['fields'] as $f ) {
				$own[ $f['key'] ] = $f;
			}

			$fields = array();
			$parent = $all[ $key ]['extends'];
			if ( $parent && isset( $all[ $parent ] ) ) {
				foreach ( $all[ $parent ]['fields'] as $f ) {
					$fields[ $f['key'] ] = isset( $own[ $f['key'] ] ) ? $own[ $f['key'] ] : $f;
				}
			}
			foreach ( $all[ $key ]['fields'] as $f ) {
				if ( ! isset( $fields[ $f['key'] ] ) ) {
					$fields[ $f['key'] ] = $f;
				}
			}

			$out = array();
			foreach ( $fields as $f ) {
				$token = $key . '.' . $f['key'];
				if ( ! empty( $f['args'] ) ) {
					$token .= "('" . $f['args'][0] . "')";
				}
				$row = array(
					'token' => '{{ ' . $token . ' }}',
					'label' => $f['label'],
					'type'  => $f['type'],
				);
				if ( ! empty( $f['chains_to'] ) ) {
					$row['chains_to'] = $f['chains_to'];
				}
				if ( ! empty( $f['note'] ) ) {
					$row['note'] = $f['note'];
				}
				$out[] = $row;
			}

			return $out;
		}

		/** Provider keys, in picker order. */
		public static function provider_keys() {
			return array_keys( self::providers() );
		}
	}
}
