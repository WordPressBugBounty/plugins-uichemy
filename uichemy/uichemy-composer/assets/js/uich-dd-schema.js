/**
 * uich-dd-schema.js — the provider / field / filter catalog for the visual data picker.
 *
 * This MUST stay in sync with the PHP providers (includes/dynamic/class-uich-providers.php)
 * and filters (class-uich-filters.php). It is the menu the picker shows so a non-technical
 * user can build a binding by clicking (source -> field -> filter -> fallback) instead of
 * typing Twig. Field `provider` marks a chainable step (post.author -> user provider).
 *
 * Loads in both node (module.exports) and browser (window.UichDD).
 */
(function (root) {
	'use strict';

	// Labels are display text, so they go through wp.i18n in the browser. Under
	// node (the catalog generator) wp is absent and this is the identity, which
	// keeps the generated MCP catalog in English whatever the site language.
	var __ = ( root.wp && root.wp.i18n && root.wp.i18n.__ ) || function ( text ) {
		return text;
	};

	// Root providers available by page context. The picker also injects loop aliases.
	var PROVIDERS = {
		post: {
			label: __( 'This Post', 'uichemy' ),
			fields: [
				{ key: 'title', label: __( 'Title', 'uichemy' ), type: 'text' },
				{ key: 'content', label: __( 'Content', 'uichemy' ), type: 'html' },
				{ key: 'excerpt', label: __( 'Excerpt', 'uichemy' ), type: 'text' },
				{ key: 'link', label: __( 'Link', 'uichemy' ), type: 'url' },
				{ key: 'date', label: __( 'Date', 'uichemy' ), type: 'date', args: [ { name: 'format', label: __( 'Format', 'uichemy' ), default: 'F j, Y' } ] },
				{ key: 'id', label: __( 'ID', 'uichemy' ), type: 'number' },
				{ key: 'slug', label: __( 'Slug', 'uichemy' ), type: 'text' },
				{ key: 'status', label: __( 'Status', 'uichemy' ), type: 'text' },
				{ key: 'comment_count', label: __( 'Comment count', 'uichemy' ), type: 'number' },
				{ key: 'author', label: __( 'Author', 'uichemy' ), type: 'provider', provider: 'user' },
				{ key: 'thumbnail', label: __( 'Featured image', 'uichemy' ), type: 'provider', provider: 'image' },
				{ key: 'categories', label: __( 'Categories', 'uichemy' ), type: 'array', provider: 'term' },
				{ key: 'tags', label: __( 'Tags', 'uichemy' ), type: 'array', provider: 'term' },
				// Any taxonomy by slug - categories / tags only cover the two core ones.
				// Uich_Post_Provider_Pro answers terms('<taxonomy>').
				{ key: 'terms', label: __( 'Terms of a taxonomy', 'uichemy' ), type: 'array', provider: 'term', args: [ { name: 'taxonomy', label: __( 'Taxonomy', 'uichemy' ), default: 'category' } ] },
				// Adjacent-post navigation. Chainable back into `post`, so the picker
				// drills Previous post -> Title / Link / Featured image; resolves to
				// nothing at the first / last post so the link can be hidden with
				// {% if post.prev %}. Backed by Uich_Post_Provider_Pro::adjacent(), so
				// fieldIsPro() marks both Pro without Pro installed — listed rather than
				// hidden, which is how every other gated field here behaves.
				{ key: 'prev', label: __( 'Previous post', 'uichemy' ), type: 'provider', provider: 'post' },
				{ key: 'next', label: __( 'Next post', 'uichemy' ), type: 'provider', provider: 'post' },
				{ key: 'meta', label: __( 'Custom field…', 'uichemy' ), type: 'text', args: [ { name: 'key', label: __( 'Field key', 'uichemy' ), default: '' } ] }
			]
		},
		// Mirrors Uich_Product_Provider (Pro). `extends: 'post'` inherits every post
		// field; where a key appears in BOTH lists the product entry wins (see
		// getFields), which is how `categories` reads product_cat here rather than
		// the post `category` the inherited entry describes.
		product: {
			label: __( 'This Product', 'uichemy' ),
			extends: 'post',
			fields: [
				/* pricing */
				{ key: 'price', label: __( 'Price (raw number)', 'uichemy' ), type: 'number' },
				{ key: 'price_html', label: __( 'Price (formatted)', 'uichemy' ), type: 'html' },
				{ key: 'regular_price', label: __( 'Regular price', 'uichemy' ), type: 'number' },
				{ key: 'sale_price', label: __( 'Sale price', 'uichemy' ), type: 'number' },
				{ key: 'sale_percentage', label: __( 'Discount %', 'uichemy' ), type: 'number' },
				{ key: 'is_on_sale', label: __( 'Is on sale?', 'uichemy' ), type: 'bool' },
				{ key: 'sale_from', label: __( 'Sale starts', 'uichemy' ), type: 'date', args: [ { name: 'format', label: __( 'Format', 'uichemy' ), default: 'F j, Y' } ] },
				{ key: 'sale_to', label: __( 'Sale ends', 'uichemy' ), type: 'date', args: [ { name: 'format', label: __( 'Format', 'uichemy' ), default: 'F j, Y' } ] },
				{ key: 'currency', label: __( 'Currency code', 'uichemy' ), type: 'text' },
				{ key: 'currency_symbol', label: __( 'Currency symbol', 'uichemy' ), type: 'text' },
				/* identity */
				{ key: 'sku', label: __( 'SKU', 'uichemy' ), type: 'text' },
				{ key: 'type', label: __( 'Product type (simple / variable…)', 'uichemy' ), type: 'text' },
				{ key: 'is_featured', label: __( 'Featured?', 'uichemy' ), type: 'bool' },
				{ key: 'is_downloadable', label: __( 'Downloadable?', 'uichemy' ), type: 'bool' },
				{ key: 'is_virtual', label: __( 'Virtual?', 'uichemy' ), type: 'bool' },
				/* stock */
				{ key: 'stock_status', label: __( 'Stock status', 'uichemy' ), type: 'text' },
				{ key: 'stock_quantity', label: __( 'Stock quantity', 'uichemy' ), type: 'number' },
				{ key: 'is_in_stock', label: __( 'In stock?', 'uichemy' ), type: 'bool' },
				{ key: 'is_on_backorder', label: __( 'On backorder?', 'uichemy' ), type: 'bool' },
				{ key: 'availability', label: __( 'Availability text', 'uichemy' ), type: 'text' },
				/* content */
				{ key: 'short_description', label: __( 'Short description', 'uichemy' ), type: 'html' },
				{ key: 'description', label: __( 'Description', 'uichemy' ), type: 'html' },
				{ key: 'purchase_note', label: __( 'Purchase note', 'uichemy' ), type: 'html' },
				/* taxonomies */
				{ key: 'categories', label: __( 'Product categories', 'uichemy' ), type: 'array', provider: 'term' },
				{ key: 'tags', label: __( 'Product tags', 'uichemy' ), type: 'array', provider: 'term' },
				{ key: 'brand', label: __( 'Brands', 'uichemy' ), type: 'array', provider: 'term' },
				{ key: 'category_list', label: __( 'Categories (linked list)', 'uichemy' ), type: 'html' },
				{ key: 'tag_list', label: __( 'Tags (linked list)', 'uichemy' ), type: 'html' },
				{ key: 'attribute', label: __( 'Attribute…', 'uichemy' ), type: 'text', args: [ { name: 'name', label: __( 'Attribute name', 'uichemy' ), default: 'color' } ] },
				{ key: 'attributes', label: __( 'All attributes (label → value)', 'uichemy' ), type: 'array' },
				/* commerce actions */
				{ key: 'add_to_cart_url', label: __( 'Add-to-cart URL', 'uichemy' ), type: 'url' },
				{ key: 'add_to_cart_text', label: __( 'Add-to-cart label', 'uichemy' ), type: 'text' },
				{ key: 'is_purchasable', label: __( 'Purchasable?', 'uichemy' ), type: 'bool' },
				{ key: 'cart_url', label: __( 'Cart URL', 'uichemy' ), type: 'url' },
				{ key: 'checkout_url', label: __( 'Checkout URL', 'uichemy' ), type: 'url' },
				{ key: 'shop_url', label: __( 'Shop URL', 'uichemy' ), type: 'url' },
				/* reviews */
				{ key: 'average_rating', label: __( 'Average rating', 'uichemy' ), type: 'number' },
				{ key: 'review_count', label: __( 'Review count', 'uichemy' ), type: 'number' },
				{ key: 'rating_html', label: __( 'Star rating (markup)', 'uichemy' ), type: 'html' },
				{ key: 'reviews_allowed', label: __( 'Reviews allowed?', 'uichemy' ), type: 'bool' },
				{ key: 'total_sales', label: __( 'Total sales', 'uichemy' ), type: 'number' },
				/* shipping */
				{ key: 'weight', label: __( 'Weight', 'uichemy' ), type: 'text' },
				{ key: 'length', label: __( 'Length', 'uichemy' ), type: 'text' },
				{ key: 'width', label: __( 'Width', 'uichemy' ), type: 'text' },
				{ key: 'height', label: __( 'Height', 'uichemy' ), type: 'text' },
				{ key: 'dimensions', label: __( 'Dimensions (formatted)', 'uichemy' ), type: 'text' },
				/* related products — chainable back into `product` */
				{ key: 'related', label: __( 'Related products', 'uichemy' ), type: 'array', provider: 'product', args: [ { name: 'limit', label: __( 'How many', 'uichemy' ), default: '4' } ] },
				{ key: 'upsells', label: __( 'Upsells', 'uichemy' ), type: 'array', provider: 'product' },
				{ key: 'cross_sells', label: __( 'Cross-sells', 'uichemy' ), type: 'array', provider: 'product' },
				{ key: 'variations', label: __( 'Variations', 'uichemy' ), type: 'array', provider: 'product' },
				/* media */
				{ key: 'gallery', label: __( 'Gallery', 'uichemy' ), type: 'array', provider: 'image' }
			]
		},
		user: {
			label: __( 'Current User', 'uichemy' ),
			fields: [
				{ key: 'name', label: __( 'Display name', 'uichemy' ), type: 'text' },
				{ key: 'id', label: __( 'ID', 'uichemy' ), type: 'number' },
				{ key: 'url', label: __( 'Website', 'uichemy' ), type: 'url' },
				{ key: 'bio', label: __( 'Bio', 'uichemy' ), type: 'text' },
				{ key: 'link', label: __( 'Posts URL', 'uichemy' ), type: 'url' },
				{ key: 'avatar', label: __( 'Avatar URL', 'uichemy' ), type: 'url', args: [ { name: 'size', label: __( 'Size', 'uichemy' ), default: '96' } ] },
				{ key: 'email', label: __( 'Email (admins)', 'uichemy' ), type: 'text' },
				{ key: 'meta', label: __( 'Custom field…', 'uichemy' ), type: 'text', args: [ { name: 'key', label: __( 'Field key', 'uichemy' ), default: '' } ] }
			]
		},
		term: {
			label: __( 'This Term', 'uichemy' ),
			fields: [
				{ key: 'name', label: __( 'Name', 'uichemy' ), type: 'text' },
				{ key: 'id', label: __( 'ID', 'uichemy' ), type: 'number' },
				{ key: 'slug', label: __( 'Slug', 'uichemy' ), type: 'text' },
				{ key: 'description', label: __( 'Description', 'uichemy' ), type: 'text' },
				{ key: 'count', label: __( 'Post count', 'uichemy' ), type: 'number' },
				{ key: 'link', label: __( 'Link', 'uichemy' ), type: 'url' }
			]
		},
		image: {
			label: __( 'Image', 'uichemy' ),
			fields: [
				{ key: 'src', label: __( 'URL', 'uichemy' ), type: 'url', args: [ { name: 'size', label: __( 'Size', 'uichemy' ), default: 'full' } ] },
				{ key: 'alt', label: __( 'Alt text', 'uichemy' ), type: 'text' },
				{ key: 'width', label: __( 'Width', 'uichemy' ), type: 'number' },
				{ key: 'height', label: __( 'Height', 'uichemy' ), type: 'number' },
				{ key: 'caption', label: __( 'Caption', 'uichemy' ), type: 'text' }
			]
		},
		site: {
			label: __( 'Site', 'uichemy' ),
			fields: [
				{ key: 'name', label: __( 'Name', 'uichemy' ), type: 'text' },
				{ key: 'description', label: __( 'Tagline', 'uichemy' ), type: 'text' },
				{ key: 'url', label: __( 'Home URL', 'uichemy' ), type: 'url' },
				{ key: 'language', label: __( 'Language', 'uichemy' ), type: 'text' },
				{ key: 'icon', label: __( 'Site icon', 'uichemy' ), type: 'provider', provider: 'image' },
				{ key: 'logo', label: __( 'Logo', 'uichemy' ), type: 'provider', provider: 'image' },
				{ key: 'option', label: __( 'Option…', 'uichemy' ), type: 'text', args: [ { name: 'key', label: __( 'Option name', 'uichemy' ), default: '' } ] }
			]
		},
		request: {
			label: __( 'URL / Request', 'uichemy' ),
			fields: [
				{ key: 'get', label: __( 'Query parameter…', 'uichemy' ), type: 'text', args: [ { name: 'key', label: __( 'Param name', 'uichemy' ), default: '' } ] },
				{ key: 'url', label: __( 'Current URL', 'uichemy' ), type: 'url' },
				{ key: 'referrer', label: __( 'Referrer', 'uichemy' ), type: 'url' }
			]
		}
	};

	// Filters grouped by the value types they apply to. `any` always shows.
	var FILTERS = [
		{ name: 'upper', label: __( 'UPPERCASE', 'uichemy' ), types: [ 'text' ] },
		{ name: 'lower', label: __( 'lowercase', 'uichemy' ), types: [ 'text' ] },
		{ name: 'capitalize', label: __( 'Capitalize', 'uichemy' ), types: [ 'text' ] },
		{ name: 'title', label: __( 'Title Case', 'uichemy' ), types: [ 'text' ] },
		{ name: 'trim', label: __( 'Trim spaces', 'uichemy' ), types: [ 'text' ] },
		{ name: 'truncate', label: __( 'Truncate…', 'uichemy' ), types: [ 'text' ], args: [ { name: 'length', label: __( 'Length', 'uichemy' ), default: '100' } ] },
		{ name: 'excerpt', label: __( 'Excerpt (words)…', 'uichemy' ), types: [ 'text', 'html' ], args: [ { name: 'words', label: __( 'Words', 'uichemy' ), default: '25' } ] },
		{ name: 'striptags', label: __( 'Strip HTML', 'uichemy' ), types: [ 'html', 'text' ] },
		{ name: 'date', label: __( 'Format date…', 'uichemy' ), types: [ 'date' ], args: [ { name: 'format', label: __( 'Format', 'uichemy' ), default: 'F j, Y' } ] },
		{ name: 'time_ago', label: __( 'Time ago', 'uichemy' ), types: [ 'date' ] },
		{ name: 'number_format', label: __( 'Number format…', 'uichemy' ), types: [ 'number' ], args: [ { name: 'decimals', label: __( 'Decimals', 'uichemy' ), default: '0' } ] },
		{ name: 'round', label: __( 'Round…', 'uichemy' ), types: [ 'number' ], args: [ { name: 'precision', label: __( 'Decimals', 'uichemy' ), default: '0' } ] },
		{ name: 'currency', label: __( 'As currency', 'uichemy' ), types: [ 'number' ] },
		{ name: 'join', label: __( 'Join with…', 'uichemy' ), types: [ 'array' ], args: [ { name: 'glue', label: __( 'Separator', 'uichemy' ), default: ', ' } ] },
		{ name: 'length', label: __( 'Count', 'uichemy' ), types: [ 'array' ] },
		{ name: 'first', label: __( 'First item', 'uichemy' ), types: [ 'array' ] },
		{ name: 'last', label: __( 'Last item', 'uichemy' ), types: [ 'array' ] },
		{ name: 'reverse', label: __( 'Reverse', 'uichemy' ), types: [ 'array' ] },
		{ name: 'slug', label: __( 'Slugify', 'uichemy' ), types: [ 'text' ] },
		{ name: 'wpautop', label: __( 'Auto paragraphs', 'uichemy' ), types: [ 'html' ] },
		{ name: 'raw', label: __( 'Raw (allow HTML)', 'uichemy' ), types: [ 'html', 'text' ] }
	];

	// Loop sources for the loop builder.
	var LOOP_SOURCES = [
		{ key: 'get_posts', label: __( 'Posts', 'uichemy' ), alias: 'post', postType: true },
		{ key: 'get_products', label: __( 'Products (WooCommerce)', 'uichemy' ), alias: 'product', postType: false, taxonomy: 'product_cat' },
		{ key: 'get_terms', label: __( 'Terms / Categories', 'uichemy' ), alias: 'term' },
		{ key: 'get_users', label: __( 'Users', 'uichemy' ), alias: 'user' }
	];

	var ORDERBY = [ 'date', 'title', 'menu_order', 'rand', 'ID', 'name', 'count' ];
	var CONDITION_OPS = [
		{ key: '==', label: __( 'is', 'uichemy' ) },
		{ key: '!=', label: __( 'is not', 'uichemy' ) },
		{ key: '>', label: __( 'greater than', 'uichemy' ) },
		{ key: '<', label: __( 'less than', 'uichemy' ) },
		{ key: 'is_set', label: __( 'is set', 'uichemy' ) },
		{ key: 'is_empty', label: __( 'is empty', 'uichemy' ) }
	];

	function getFields(providerKey) {
		var p = PROVIDERS[ providerKey ];
		if ( ! p ) { return []; }
		if ( ! p.extends || ! PROVIDERS[ p.extends ] ) { return p.fields.slice(); }

		// Inherited fields keep their position, but a provider's OWN entry for the
		// same key replaces the inherited one — `product.categories` reads
		// product_cat, so it must not be listed twice with the post description.
		var own = {};
		p.fields.forEach( function ( f ) { own[ f.key ] = f; } );

		var out = PROVIDERS[ p.extends ].fields.map( function ( f ) {
			return own[ f.key ] || f;
		} );

		var listed = {};
		out.forEach( function ( f ) { listed[ f.key ] = 1; } );
		p.fields.forEach( function ( f ) {
			if ( ! listed[ f.key ] ) { out.push( f ); }
		} );

		return out;
	}

	function filtersForType(type) {
		return FILTERS.filter( function ( f ) {
			return ( f.types.indexOf( type ) !== -1 ) || ( f.types.indexOf( 'any' ) !== -1 );
		} );
	}

	/* ------------------- optional-plugin integration gating ------------------- */
	// Some sources depend on an optional plugin (WooCommerce products, …). The
	// server reports which are active synchronously via
	// window.uichAtomFields.integrations ({ woo, acf, jet }); a source tied to an
	// inactive integration is hidden from the pickers. An unknown/absent flag is
	// treated as ACTIVE so a stale or missing localize never hides a core source.
	var SOURCE_INTEGRATION      = { product: 'woo' };
	var LOOP_SOURCE_INTEGRATION = { get_products: 'woo' };

	function activeIntegrations() {
		return ( typeof window !== 'undefined' && window.uichAtomFields && window.uichAtomFields.integrations ) || {};
	}
	function integrationActive(name) {
		if ( ! name ) { return true; }
		return activeIntegrations()[ name ] !== false;
	}
	function sourceAvailable(key) { return integrationActive( SOURCE_INTEGRATION[ key ] ); }

	/* ----------------------------- Free / Pro tiering -------------------------- */
	// Free ships four dynamic tags: Post Title, Post Image, Post Content and Post
	// URL. Everything else in this catalog is Pro — including every Term/Category
	// field. This mirrors uichemy_dynamic_free_fields() in
	// includes/uichemy-pro-gate.php — the PHP list is the one that actually enforces
	// (a Pro field resolves to nothing in Free), so KEEP THE TWO IN SYNC. Here it
	// only decides what the picker offers.
	//
	// `image` carries the whole chain because it IS the Post Image tag (an <img>
	// needs src/alt/width/height). `post.id` is intentionally absent: it resolves in
	// Free as loop plumbing ("Exclude current post") but is not offered as a tag.
	// `term` is empty on purpose — Category Title went Pro on 2026-07-31 — which
	// makes sourceIsPro('term') true and locks the whole source.
	var FREE_FIELDS = {
		post:  [ 'title', 'content', 'link', 'thumbnail' ],
		term:  [],
		image: [ 'src', 'alt', 'width', 'height', 'caption' ]
	};

	function isPro() {
		return !! ( typeof window !== 'undefined' && window.uichAtomFields && window.uichAtomFields.isPro );
	}

	function proUrl() {
		var cfg = ( typeof window !== 'undefined' && window.uichAtomFields ) || {};
		return cfg.proUrl || 'https://uichemy.com/pricing/';
	}

	/** True when this field needs Pro in the current build. */
	function fieldIsPro(providerKey, fieldKey) {
		if ( isPro() ) { return false; }
		var free = FREE_FIELDS[ providerKey ];
		if ( ! free ) { return true; }            // whole source is Pro (product, user, site, request…)
		return free.indexOf( fieldKey ) === -1;
	}

	/** True when every field of a source is Pro, so the source itself is locked. */
	function sourceIsPro(providerKey) {
		if ( isPro() ) { return false; }
		var free = FREE_FIELDS[ providerKey ];
		return ! free || ! free.length;
	}

	// Free loops over POSTS only. Products / Terms / Users / External API are Pro —
	// mirrors uichemy_free_loop_sources(), which is what actually enforces (a Pro
	// source returns an empty collection, so the loop shows its empty state).
	var FREE_LOOP_SOURCES = [ 'get_posts' ];

	/** True when this loop source needs Pro in the current build. */
	function loopSourceIsPro(key) {
		if ( isPro() ) { return false; }
		return FREE_LOOP_SOURCES.indexOf( key ) === -1;
	}

	function loopSourceAvailable(key) { return integrationActive( LOOP_SOURCE_INTEGRATION[ key ] ); }
	function availableSources(list) {
		return ( list || [] ).filter( function ( k ) { return sourceAvailable( k ); } );
	}
	function availableLoopSources() {
		return LOOP_SOURCES.filter( function ( s ) { return loopSourceAvailable( s.key ); } );
	}

	var POST_TYPES = [];
	// Introspected custom (ACF) fields collected for the picker's dedicated "ACF"
	// group. Each carries the real provider it binds to (source) so it still
	// compiles to <source>.meta('key') — there is no standalone `acf` provider.
	var ACF_FIELDS = [];

	// Merge live-introspected fields (ACF/Meta Box/registered meta) + post types into the schema.
	// Called once on editor load from the /atom-fields REST response.
	function extend(data) {
		if ( ! data ) { return; }
		ACF_FIELDS = [];
		var seenAcf = {};
		[ 'post', 'product', 'user', 'term', 'site' ].forEach( function ( prov ) {
			if ( ! data[ prov ] || ! PROVIDERS[ prov ] ) { return; }
			var have = {};
			getFields( prov ).forEach( function ( f ) { have[ f.key ] = 1; } );
			data[ prov ].forEach( function ( f ) {
				if ( ! have[ f.key ] ) { PROVIDERS[ prov ].fields.push( f ); have[ f.key ] = 1; }
				// A field backed by a meta key is a discovered custom (ACF) field:
				// remember it (with its provider) for the ACF group. Dedupe by key
				// across providers so a shared field lists once (first provider wins).
				if ( f.metaKey && ! seenAcf[ f.metaKey ] ) {
					seenAcf[ f.metaKey ] = 1;
					ACF_FIELDS.push( Object.assign( {}, f, { source: prov } ) );
				}
			} );
		} );
		if ( Array.isArray( data.postTypes ) ) { POST_TYPES = data.postTypes; }
	}
	function postTypes() { return POST_TYPES; }
	function acfFields() { return ACF_FIELDS.slice(); }

	var lib = {
		PROVIDERS: PROVIDERS,
		FILTERS: FILTERS,
		LOOP_SOURCES: LOOP_SOURCES,
		ORDERBY: ORDERBY,
		CONDITION_OPS: CONDITION_OPS,
		getFields: getFields,
		filtersForType: filtersForType,
		integrationActive: integrationActive,
		isPro: isPro,
		proUrl: proUrl,
		FREE_FIELDS: FREE_FIELDS,
		FREE_LOOP_SOURCES: FREE_LOOP_SOURCES,
		fieldIsPro: fieldIsPro,
		sourceIsPro: sourceIsPro,
		loopSourceIsPro: loopSourceIsPro,
		sourceAvailable: sourceAvailable,
		loopSourceAvailable: loopSourceAvailable,
		availableSources: availableSources,
		availableLoopSources: availableLoopSources,
		extend: extend,
		postTypes: postTypes,
		acfFields: acfFields
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = lib;
	}
	root.UichDD = Object.assign( root.UichDD || {}, { schema: lib } );
} )( typeof window !== 'undefined' ? window : globalThis );
