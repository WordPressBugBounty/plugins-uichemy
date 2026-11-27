<?php
/**
 * Site profile: the business facts agents ask about most.
 *
 * Name, type, summary, locations with opening hours, contact actions, price
 * range, service area, policies and what an agent can do here. Stored as
 * profile.json in the private folder.
 *
 * The site's own pages stay the source of truth: extract-profile reads a draft
 * from the rendered home page (its schema, phone and email links), the SEO
 * module's site identity and WooCommerce's store address; a person (or Claude,
 * with the owner) confirms it with update-profile. The confirmed profile then
 * feeds get_site_info, check_open_now, the top of llms.txt and SEO's schema, so
 * the visible page, the schema and every agent answer say the same thing.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Profile' ) ) {

	/**
	 * Site profile.
	 */
	class Uich_Agent_Profile {

		/**
		 * File in the private folder.
		 */
		const FILE = 'profile.json';

		/**
		 * Week days, Monday first.
		 */
		const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

		/**
		 * Request cache.
		 *
		 * @var array|null
		 */
		private static $cache = null;

		/**
		 * Business types (schema.org), grouped for the settings screen.
		 *
		 * @return string[]
		 */
		public static function types() {
			return array( 'Organization', 'Corporation', 'NGO', 'LocalBusiness', 'OnlineStore', 'Store', 'ClothingStore', 'Restaurant', 'CafeOrCoffeeShop', 'Bakery', 'BarOrPub', 'ProfessionalService', 'LegalService', 'AccountingService', 'FinancialService', 'MedicalBusiness', 'Dentist', 'Physician', 'HealthClub', 'BeautySalon', 'HairSalon', 'DaySpa', 'RealEstateAgent', 'TravelAgency', 'AutoRepair', 'HomeAndConstructionBusiness', 'EducationalOrganization', 'School', 'EventVenue', 'LodgingBusiness', 'Hotel' );
		}

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'uichemy_agent_ready_actions', array( __CLASS__, 'actions' ) );
			add_filter( 'uichemy_agent_ready_site_info', array( __CLASS__, 'site_info' ), 5 );
			add_filter( 'uichemy_agent_ready_builtin_tools', array( __CLASS__, 'tools' ) );
			add_filter( 'uichemy_agent_ready_llms_summary', array( __CLASS__, 'llms_summary' ) );
			add_filter( 'uichemy_agent_ready_llms_extra', array( __CLASS__, 'llms_extra' ) );
			add_filter( 'uichemy_agent_ready_page_footer', array( __CLASS__, 'page_footer' ), 5 );
			add_filter( 'uichemy_seo_graph', array( __CLASS__, 'seo_graph' ), 20 );
			// Name, summary, email, phone and social links are shared with SEO > Site identity:
			// a change there must reach llms.txt and the agent pages too.
			add_action( 'uichemy_seo_settings_updated', array( __CLASS__, 'shared_changed' ) );
			add_shortcode( 'uichemy_agent_facts', array( __CLASS__, 'sc_facts' ) );
		}

		/**
		 * Empty profile.
		 *
		 * @return array
		 */
		public static function defaults() {
			return array(
				'name'          => '',
				'type'          => 'Organization',
				'summary'       => '',
				'description'   => '',
				'locations'     => array(),
				'contacts'      => array(
					'phone'       => '',
					'whatsapp'    => '',
					'email'       => '',
					'booking_url' => '',
					'order_links' => array(),
				),
				'price_range'   => '',
				'service_area'  => '',
				'languages'     => array(),
				'policies'      => array(),
				'social'        => array(),
				'can_do'        => array(),
				'last_verified' => '',
			);
		}

		/**
		 * The confirmed profile (defaults when none is saved).
		 *
		 * @return array
		 */
		public static function get() {
			if ( null === self::$cache ) {
				$stored      = Uich_Agent_Store::read_json( self::FILE );
				self::$cache = self::with_shared( self::sanitize( is_array( $stored ) ? $stored : array() ) );
			}
			return self::$cache;
		}

		/**
		 * Profile fields that live in SEO > Site identity: profile path => SEO key.
		 * One copy, two screens: both read and write the SEO setting, so a change
		 * in either place shows in both.
		 */
		const SHARED = array(
			'name'           => 'entity_name',
			'summary'        => 'entity_description',
			'contacts.email' => 'entity_email',
			'contacts.phone' => 'entity_phone',
			'social'         => 'social_profiles',
		);

		/**
		 * Option set once the profile's own values were moved into SEO.
		 */
		const SHARED_DONE = 'uichemy_agent_profile_shared';

		/**
		 * Whether the SEO module (and so the shared fields) is there.
		 *
		 * @return bool
		 */
		private static function seo_on() {
			return class_exists( 'Uich_SEO_Settings' );
		}

		/**
		 * Read one profile path ("contacts.email").
		 *
		 * @param array  $p    Profile.
		 * @param string $path Path.
		 * @return mixed
		 */
		private static function pick( array $p, $path ) {
			$parts = explode( '.', $path );
			return 2 === count( $parts ) ? $p[ $parts[0] ][ $parts[1] ] : $p[ $parts[0] ];
		}

		/**
		 * Set one profile path.
		 *
		 * @param array  $p     Profile (by reference).
		 * @param string $path  Path.
		 * @param mixed  $value Value.
		 * @return void
		 */
		private static function put( array &$p, $path, $value ) {
			$parts = explode( '.', $path );
			if ( 2 === count( $parts ) ) {
				$p[ $parts[0] ][ $parts[1] ] = $value;
			} else {
				$p[ $parts[0] ] = $value;
			}
		}

		/**
		 * The profile with the shared fields read from SEO.
		 *
		 * The first time, values only the profile has are moved into SEO, so nothing
		 * the owner typed is lost. After that SEO holds the one copy.
		 *
		 * @param array $p Profile.
		 * @return array
		 */
		private static function with_shared( array $p ) {
			if ( ! self::seo_on() ) {
				return $p;
			}
			$seo = (array) Uich_SEO_Settings::all();
			if ( ! get_option( self::SHARED_DONE ) ) {
				$move = array();
				foreach ( self::SHARED as $path => $key ) {
					$mine = self::pick( $p, $path );
					if ( ! empty( $mine ) && empty( $seo[ $key ] ) ) {
						$move[ $key ] = $mine;
					}
				}
				$moved = $move ? Uich_SEO_Settings::update( $move ) : true;
				if ( ! is_wp_error( $moved ) ) {
					update_option( self::SHARED_DONE, time(), false );
					$seo = (array) Uich_SEO_Settings::all();
				}
			}
			foreach ( self::SHARED as $path => $key ) {
				$value = isset( $seo[ $key ] ) ? $seo[ $key ] : '';
				if ( 'social' === $path ) {
					$value = array_values( array_filter( array_map( 'esc_url_raw', (array) $value ) ) );
				} else {
					$value = (string) $value;
				}
				self::put( $p, $path, $value );
			}
			return $p;
		}

		/**
		 * SEO identity changed: forget the cached profile and rebuild agent files.
		 *
		 * @return void
		 */
		public static function shared_changed() {
			self::$cache = null;
			if ( class_exists( 'Uich_Agent_Pages' ) ) {
				Uich_Agent_Pages::bump();
			}
		}

		/**
		 * Whether a profile has been confirmed.
		 *
		 * @return bool
		 */
		public static function exists() {
			$p = self::get();
			return '' !== $p['name'] || ! empty( $p['locations'] ) || '' !== $p['contacts']['phone'];
		}

		/**
		 * Save a profile (full replace of the given top-level keys).
		 *
		 * @param array $patch Profile keys.
		 * @return array|WP_Error
		 */
		public static function update( array $patch ) {
			$next                  = self::sanitize( array_merge( self::get(), $patch ) );
			$next['last_verified'] = gmdate( 'Y-m-d' );
			// Shared fields are saved in SEO > Site identity first; SEO's own checks apply.
			if ( self::seo_on() ) {
				$seo     = (array) Uich_SEO_Settings::all();
				$changes = array();
				foreach ( self::SHARED as $path => $key ) {
					$value = self::pick( $next, $path );
					$have  = isset( $seo[ $key ] ) ? $seo[ $key ] : ( 'social' === $path ? array() : '' );
					if ( $value !== $have ) {
						$changes[ $key ] = $value;
					}
				}
				if ( $changes ) {
					$done = Uich_SEO_Settings::update( $changes );
					if ( is_wp_error( $done ) ) {
						// Name the profile field, not the SEO key, so the right input shows the error.
						$fields = array_flip( self::SHARED );
						$out    = new WP_Error();
						foreach ( $done->get_error_codes() as $code ) {
							$out->add( isset( $fields[ $code ] ) ? $fields[ $code ] : $code, $done->get_error_message( $code ) );
						}
						return $out;
					}
				}
				update_option( self::SHARED_DONE, time(), false );
			}
			// The profile file keeps a copy too, used if the SEO module is switched off.
			$saved                 = Uich_Agent_Store::write_json( self::FILE, $next );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			self::$cache = null;
			if ( class_exists( 'Uich_Agent_Pages' ) ) {
				Uich_Agent_Pages::bump();
			}
			return self::get();
		}

		/**
		 * Clamp a profile to its shape.
		 *
		 * @param array $in Raw.
		 * @return array
		 */
		public static function sanitize( array $in ) {
			$d   = self::defaults();
			$out = array(
				'name'          => self::line( isset( $in['name'] ) ? $in['name'] : '', 120 ),
				'type'          => isset( $in['type'] ) && in_array( $in['type'], self::types(), true ) ? $in['type'] : $d['type'],
				'summary'       => self::line( isset( $in['summary'] ) ? $in['summary'] : '', 600 ),
				'description'   => mb_substr( sanitize_textarea_field( wp_strip_all_tags( isset( $in['description'] ) ? (string) $in['description'] : '' ) ), 0, 2000 ),
				'locations'     => array(),
				'contacts'      => array(
					'phone'       => self::phone( isset( $in['contacts']['phone'] ) ? $in['contacts']['phone'] : '' ),
					'whatsapp'    => self::phone( isset( $in['contacts']['whatsapp'] ) ? $in['contacts']['whatsapp'] : '' ),
					'email'       => sanitize_email( isset( $in['contacts']['email'] ) ? (string) $in['contacts']['email'] : '' ),
					'booking_url' => esc_url_raw( isset( $in['contacts']['booking_url'] ) ? (string) $in['contacts']['booking_url'] : '', array( 'http', 'https' ) ),
					'order_links' => self::links( isset( $in['contacts']['order_links'] ) ? $in['contacts']['order_links'] : array(), 10 ),
				),
				'price_range'   => self::line( isset( $in['price_range'] ) ? $in['price_range'] : '', 60 ),
				'service_area'  => self::line( isset( $in['service_area'] ) ? $in['service_area'] : '', 200 ),
				'languages'     => array_slice( array_values( array_filter( array_map( array( __CLASS__, 'short' ), isset( $in['languages'] ) ? (array) $in['languages'] : array() ) ) ), 0, 20 ),
				'policies'      => self::links( isset( $in['policies'] ) ? $in['policies'] : array(), 20 ),
				'social'        => array_slice( array_values( array_filter( array_map( 'esc_url_raw', isset( $in['social'] ) ? (array) $in['social'] : array() ) ) ), 0, 20 ),
				'can_do'        => array_slice( array_values( array_filter( array_map( array( __CLASS__, 'can_do_line' ), isset( $in['can_do'] ) ? (array) $in['can_do'] : array() ) ) ), 0, 20 ),
				'last_verified' => isset( $in['last_verified'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['last_verified'] ) ? $in['last_verified'] : '',
			);
			foreach ( array_slice( isset( $in['locations'] ) ? (array) $in['locations'] : array(), 0, 20 ) as $loc ) {
				if ( is_array( $loc ) ) {
					$out['locations'][] = self::location( $loc );
				}
			}
			return $out;
		}

		/**
		 * One location.
		 *
		 * @param array $loc Raw.
		 * @return array
		 */
		private static function location( array $loc ) {
			$addr  = isset( $loc['address'] ) && is_array( $loc['address'] ) ? $loc['address'] : array();
			$hours = array();
			foreach ( self::DAYS as $day ) {
				$hours[ $day ] = self::ranges( isset( $loc['hours'][ $day ] ) ? $loc['hours'][ $day ] : array() );
			}
			$special = array();
			foreach ( array_slice( isset( $loc['special_hours'] ) ? (array) $loc['special_hours'] : array(), 0, 60 ) as $sp ) {
				if ( ! is_array( $sp ) || empty( $sp['date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $sp['date'] ) ) {
					continue;
				}
				$special[] = array(
					'date'   => $sp['date'],
					'closed' => ! empty( $sp['closed'] ),
					'hours'  => empty( $sp['closed'] ) ? self::ranges( isset( $sp['hours'] ) ? $sp['hours'] : array() ) : array(),
					'note'   => self::line( isset( $sp['note'] ) ? $sp['note'] : '', 120 ),
				);
			}
			$tz = isset( $loc['timezone'] ) ? (string) $loc['timezone'] : '';
			return array(
				'name'          => self::line( isset( $loc['name'] ) ? $loc['name'] : '', 120 ),
				'address'       => array(
					'street'   => self::line( isset( $addr['street'] ) ? $addr['street'] : '', 200 ),
					'city'     => self::line( isset( $addr['city'] ) ? $addr['city'] : '', 100 ),
					'region'   => self::line( isset( $addr['region'] ) ? $addr['region'] : '', 100 ),
					'postcode' => self::line( isset( $addr['postcode'] ) ? $addr['postcode'] : '', 20 ),
					'country'  => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', isset( $addr['country'] ) ? (string) $addr['country'] : '' ), 0, 2 ) ),
				),
				'geo'           => array(
					'lat' => isset( $loc['geo']['lat'] ) && is_numeric( $loc['geo']['lat'] ) ? max( -90, min( 90, (float) $loc['geo']['lat'] ) ) : null,
					'lng' => isset( $loc['geo']['lng'] ) && is_numeric( $loc['geo']['lng'] ) ? max( -180, min( 180, (float) $loc['geo']['lng'] ) ) : null,
				),
				'phone'         => self::phone( isset( $loc['phone'] ) ? $loc['phone'] : '' ),
				'map_url'       => esc_url_raw( isset( $loc['map_url'] ) ? (string) $loc['map_url'] : '', array( 'http', 'https' ) ),
				'hours'         => $hours,
				'special_hours' => $special,
				'timezone'      => in_array( $tz, timezone_identifiers_list(), true ) ? $tz : '',
			);
		}

		/**
		 * Time ranges [["09:00","17:00"], ...]; overnight ranges allowed ("18:00","02:00").
		 *
		 * @param mixed $ranges Raw.
		 * @return array
		 */
		private static function ranges( $ranges ) {
			$out = array();
			foreach ( array_slice( (array) $ranges, 0, 4 ) as $r ) {
				if ( is_array( $r ) && 2 === count( $r ) && preg_match( '/^([01]\d|2[0-4]):[0-5]\d$/', (string) $r[0] ) && preg_match( '/^([01]\d|2[0-4]):[0-5]\d$/', (string) $r[1] ) ) {
					$out[] = array( (string) $r[0], (string) $r[1] );
				}
			}
			return $out;
		}

		/* -------------------------------------------------------------
		 * Opening hours
		 * ----------------------------------------------------------- */

		/**
		 * Whether a location is open at a moment, with today's hours and the next change.
		 *
		 * @param array $loc Location.
		 * @param int   $now Unix time.
		 * @return array
		 */
		public static function open_state( array $loc, $now ) {
			$tz   = new DateTimeZone( '' !== $loc['timezone'] ? $loc['timezone'] : wp_timezone_string() );
			$time = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
			$open = false;
			$next = null;

			// Check yesterday's overnight ranges, today, and the next six days.
			for ( $offset = -1; $offset <= 7; $offset++ ) {
				$day    = $time->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' )->setTime( 0, 0 );
				$ranges = self::ranges_for( $loc, $day );
				foreach ( $ranges as $r ) {
					list( $sh, $sm ) = array_map( 'intval', explode( ':', $r[0] ) );
					list( $eh, $em ) = array_map( 'intval', explode( ':', $r[1] ) );
					$start = $day->setTime( $sh, $sm );
					$end   = $day->setTime( $eh % 24, $em );
					if ( $end <= $start || 24 === $eh ) {
						$end = $end->modify( '+1 day' );
					}
					if ( $time >= $start && $time < $end ) {
						$open = true;
						$next = $next && $next < $end ? $next : $end;
					} elseif ( $start > $time && ( ! $next || $start < $next ) && ! $open ) {
						$next = $start;
					}
				}
			}

			$today_ranges = self::ranges_for( $loc, $time->setTime( 0, 0 ) );
			return array(
				'location'    => '' !== $loc['name'] ? $loc['name'] : null,
				'open_now'    => $open,
				'local_time'  => $time->format( 'c' ),
				'today'       => array_map(
					function ( $r ) {
						return $r[0] . '-' . $r[1];
					},
					$today_ranges
				),
				'next_change' => $next ? $next->format( 'c' ) : null,
				'next_change_is' => $next ? ( $open ? 'closes' : 'opens' ) : null,
			);
		}

		/**
		 * Ranges for one calendar day: special hours win over the weekly hours.
		 *
		 * @param array             $loc Location.
		 * @param DateTimeImmutable $day Day (local midnight).
		 * @return array
		 */
		private static function ranges_for( array $loc, DateTimeImmutable $day ) {
			$date = $day->format( 'Y-m-d' );
			foreach ( $loc['special_hours'] as $sp ) {
				if ( $sp['date'] === $date ) {
					return $sp['closed'] ? array() : $sp['hours'];
				}
			}
			$key = self::DAYS[ ( (int) $day->format( 'N' ) ) - 1 ];
			return isset( $loc['hours'][ $key ] ) ? $loc['hours'][ $key ] : array();
		}

		/**
		 * Locations that have opening hours.
		 *
		 * @return array
		 */
		private static function locations_with_hours() {
			$out = array();
			foreach ( self::get()['locations'] as $i => $loc ) {
				foreach ( $loc['hours'] as $ranges ) {
					if ( $ranges ) {
						$out[ $i ] = $loc;
						break;
					}
				}
			}
			return $out;
		}

		/* -------------------------------------------------------------
		 * Outputs
		 * ----------------------------------------------------------- */

		/**
		 * get_site_info: public profile facts.
		 *
		 * @param array $info Info.
		 * @return array
		 */
		public static function site_info( array $info ) {
			if ( ! self::exists() ) {
				return $info;
			}
			$p = self::get();
			if ( '' !== $p['name'] ) {
				$info['name'] = $p['name'];
			}
			$info['type']          = $p['type'];
			$info['summary']       = $p['summary'];
			$info['description']   = $p['description'];
			$info['locations']     = $p['locations'];
			$info['contacts']      = $p['contacts'];
			$info['price_range']   = $p['price_range'];
			$info['service_area']  = $p['service_area'];
			$info['languages']     = $p['languages'];
			$info['policies']      = $p['policies'];
			$info['social']        = $p['social'];
			$info['last_verified'] = $p['last_verified'];
			return array_filter(
				$info,
				function ( $v ) {
					return '' !== $v && array() !== $v && null !== $v;
				}
			);
		}

		/**
		 * Add check_open_now when any location has hours.
		 *
		 * @param array $tools Built-in tools.
		 * @return array
		 */
		public static function tools( array $tools ) {
			$locs = self::locations_with_hours();
			if ( ! $locs || in_array( 'check_open_now', (array) Uich_Agent_Settings::value( 'mcp.disabled_builtins', array() ), true ) ) {
				return $tools;
			}
			$names = array();
			foreach ( $locs as $loc ) {
				if ( '' !== $loc['name'] ) {
					$names[] = $loc['name'];
				}
			}
			$props = new stdClass();
			if ( count( $locs ) > 1 && $names ) {
				$props = array(
					'location' => array(
						'type'        => 'string',
						'enum'        => $names,
						'description' => 'Which location. Omit for all.',
					),
				);
			}
			$tools['check_open_now'] = array(
				'name'         => 'check_open_now',
				'title'        => 'Open now?',
				'description'  => 'Whether the business is open right now, today\'s hours and when it next opens or closes, from the owner-confirmed hours (holidays included).',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => $props,
				),
				'annotations'  => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
				'source'       => 'builtin',
				'write'        => false,
				'run'          => array( __CLASS__, 'run_open_now' ),
			);
			return $tools;
		}

		/**
		 * Tool: check_open_now.
		 *
		 * @param array $args Arguments.
		 * @return array
		 */
		public static function run_open_now( array $args ) {
			$items = array();
			foreach ( self::locations_with_hours() as $loc ) {
				if ( ! empty( $args['location'] ) && $loc['name'] !== $args['location'] ) {
					continue;
				}
				$items[] = self::open_state( $loc, time() );
			}
			return array(
				'items'         => $items,
				'last_verified' => self::get()['last_verified'],
				'note'          => 'Hours as confirmed by the owner; special hours and holidays are included.',
			);
		}

		/**
		 * llms.txt summary from the profile.
		 *
		 * @param string $summary Current summary.
		 * @return string
		 */
		public static function llms_summary( $summary ) {
			$p = self::get();
			return '' !== $p['summary'] ? $p['summary'] : $summary;
		}

		/**
		 * llms.txt section: contact, locations and hours.
		 *
		 * @param string $extra Existing extra sections.
		 * @return string
		 */
		public static function llms_extra( $extra ) {
			if ( ! self::exists() ) {
				return $extra;
			}
			$p     = self::get();
			$lines = array();
			if ( '' !== $p['contacts']['phone'] ) {
				$lines[] = '- [Phone](tel:' . preg_replace( '/[^\d+]/', '', $p['contacts']['phone'] ) . '): ' . $p['contacts']['phone'];
			}
			if ( '' !== $p['contacts']['whatsapp'] ) {
				$lines[] = '- [WhatsApp](https://wa.me/' . preg_replace( '/\D/', '', $p['contacts']['whatsapp'] ) . '): ' . $p['contacts']['whatsapp'];
			}
			if ( '' !== $p['contacts']['email'] ) {
				$lines[] = '- [Email](mailto:' . $p['contacts']['email'] . '): ' . $p['contacts']['email'];
			}
			if ( '' !== $p['contacts']['booking_url'] ) {
				$lines[] = '- [Book](' . $p['contacts']['booking_url'] . '): official booking page.';
			}
			foreach ( $p['contacts']['order_links'] as $link ) {
				$lines[] = '- [' . $link['title'] . '](' . $link['url'] . ')' . ( '' !== $link['note'] ? ': ' . $link['note'] : '' );
			}
			foreach ( $p['locations'] as $loc ) {
				$addr  = implode( ', ', array_filter( array( $loc['address']['street'], $loc['address']['city'], $loc['address']['region'], $loc['address']['postcode'], $loc['address']['country'] ) ) );
				$hours = self::hours_text( $loc );
				$phone = isset( $loc['phone'] ) ? (string) $loc['phone'] : '';
				// An empty location row (nothing filled in yet) says nothing to an agent.
				if ( '' === $loc['name'] && '' === $addr && '' === $hours && '' === $phone && '' === $loc['map_url'] ) {
					continue;
				}
				$note    = trim( ltrim( $addr . ( '' !== $phone ? ( '' !== $addr ? ', ' : '' ) . $phone : '' ) . $hours, '. ' ) );
				$url     = '' !== $loc['map_url'] ? $loc['map_url'] : home_url( '/' );
				$lines[] = '- [' . ( '' !== $loc['name'] ? $loc['name'] : 'Location' ) . '](' . $url . ')' . ( '' !== $note ? ': ' . $note : '' );
			}
			foreach ( $p['policies'] as $link ) {
				$lines[] = '- [' . $link['title'] . '](' . $link['url'] . ')' . ( '' !== $link['note'] ? ': ' . $link['note'] : '' );
			}
			if ( ! $lines ) {
				return $extra;
			}
			$section = "## Contact, locations and hours\n\n" . implode( "\n", $lines );
			return trim( $extra . "\n\n" . $section );
		}

		/**
		 * Weekly hours as one short line.
		 *
		 * @param array $loc Location.
		 * @return string
		 */
		private static function hours_text( array $loc ) {
			$parts = array();
			foreach ( self::DAYS as $day ) {
				$r       = $loc['hours'][ $day ];
				$parts[] = ucfirst( $day ) . ' ' . ( $r ? implode( ',', array_map( function ( $x ) {
					return $x[0] . '-' . $x[1];
				}, $r ) ) : 'closed' );
			}
			$has = false;
			foreach ( $loc['hours'] as $r ) {
				$has = $has || ! empty( $r );
			}
			return $has ? '. Hours: ' . implode( '; ', $parts ) . '.' : '';
		}

		/**
		 * Agent file footer: official contact.
		 *
		 * @param string[] $lines Lines.
		 * @return string[]
		 */
		public static function page_footer( array $lines ) {
			$p = self::get();
			if ( '' !== $p['contacts']['phone'] ) {
				$lines[] = 'Official phone: ' . $p['contacts']['phone'];
			}
			if ( '' !== $p['contacts']['booking_url'] ) {
				$lines[] = 'Book: ' . $p['contacts']['booking_url'];
			}
			return $lines;
		}

		/**
		 * Feed the profile into SEO's schema: the site's Organization node takes
		 * the business type, address, phone, hours and price range. One schema
		 * output, never two.
		 *
		 * @param array $graph Schema graph.
		 * @return array
		 */
		public static function seo_graph( $graph ) {
			if ( ! self::exists() || ! is_array( $graph ) ) {
				return $graph;
			}
			$p = self::get();
			foreach ( $graph as $i => $node ) {
				if ( ! is_array( $node ) || empty( $node['@id'] ) || '#organization' !== substr( (string) $node['@id'], -13 ) ) {
					continue;
				}
				$node['@type'] = $p['type'];
				if ( '' !== $p['name'] ) {
					$node['name'] = $p['name'];
				}
				if ( '' !== $p['contacts']['phone'] ) {
					$node['telephone'] = $p['contacts']['phone'];
				}
				if ( '' !== $p['contacts']['email'] ) {
					$node['email'] = $p['contacts']['email'];
				}
				if ( '' !== $p['price_range'] && 'Organization' !== $p['type'] ) {
					$node['priceRange'] = $p['price_range'];
				}
				if ( $p['social'] ) {
					$node['sameAs'] = array_values( array_unique( array_merge( isset( $node['sameAs'] ) ? (array) $node['sameAs'] : array(), $p['social'] ) ) );
				}
				$loc = isset( $p['locations'][0] ) ? $p['locations'][0] : null;
				if ( $loc ) {
					$node['address'] = array_filter(
						array(
							'@type'           => 'PostalAddress',
							'streetAddress'   => $loc['address']['street'],
							'addressLocality' => $loc['address']['city'],
							'addressRegion'   => $loc['address']['region'],
							'postalCode'      => $loc['address']['postcode'],
							'addressCountry'  => $loc['address']['country'],
						)
					);
					if ( null !== $loc['geo']['lat'] && null !== $loc['geo']['lng'] ) {
						$node['geo'] = array(
							'@type'     => 'GeoCoordinates',
							'latitude'  => $loc['geo']['lat'],
							'longitude' => $loc['geo']['lng'],
						);
					}
					$spec = array();
					$map  = array(
						'mon' => 'Monday',
						'tue' => 'Tuesday',
						'wed' => 'Wednesday',
						'thu' => 'Thursday',
						'fri' => 'Friday',
						'sat' => 'Saturday',
						'sun' => 'Sunday',
					);
					foreach ( $loc['hours'] as $day => $ranges ) {
						foreach ( $ranges as $r ) {
							$spec[] = array(
								'@type'     => 'OpeningHoursSpecification',
								'dayOfWeek' => $map[ $day ],
								'opens'     => $r[0],
								'closes'    => $r[1],
							);
						}
					}
					if ( $spec ) {
						$node['openingHoursSpecification'] = $spec;
					}
				}
				$graph[ $i ] = $node;
			}
			return $graph;
		}

		/**
		 * [uichemy_agent_facts]: the profile as a visible block, so people and
		 * agents read the same facts on the page itself.
		 *
		 * @return string
		 */
		public static function sc_facts() {
			if ( ! self::exists() ) {
				return '';
			}
			$p   = self::get();
			$out = '<section class="uich-agent-facts">';
			if ( '' !== $p['description'] ) {
				$out .= '<p>' . esc_html( $p['description'] ) . '</p>';
			}
			$out .= '<dl>';
			$row  = function ( $label, $value ) {
				return '' === $value ? '' : '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
			};
			$c    = $p['contacts'];
			$out .= $row( __( 'Phone', 'uichemy' ), '' !== $c['phone'] ? '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $c['phone'] ) ) . '">' . esc_html( $c['phone'] ) . '</a>' : '' );
			$out .= $row( __( 'Email', 'uichemy' ), '' !== $c['email'] ? '<a href="mailto:' . esc_attr( $c['email'] ) . '">' . esc_html( $c['email'] ) . '</a>' : '' );
			$out .= $row( __( 'Book', 'uichemy' ), '' !== $c['booking_url'] ? '<a href="' . esc_url( $c['booking_url'] ) . '">' . esc_html( $c['booking_url'] ) . '</a>' : '' );
			$out .= $row( __( 'Price range', 'uichemy' ), esc_html( $p['price_range'] ) );
			$out .= $row( __( 'Service area', 'uichemy' ), esc_html( $p['service_area'] ) );
			foreach ( $p['locations'] as $loc ) {
				$addr = implode( ', ', array_filter( array( $loc['address']['street'], $loc['address']['city'], $loc['address']['region'], $loc['address']['postcode'], $loc['address']['country'] ) ) );
				$out .= $row( '' !== $loc['name'] ? $loc['name'] : __( 'Address', 'uichemy' ), esc_html( $addr . self::hours_text( $loc ) ) );
			}
			$out .= '</dl>';
			if ( '' !== $p['last_verified'] ) {
				/* translators: %s: date */
				$out .= '<p><small>' . esc_html( sprintf( __( 'Last checked %s', 'uichemy' ), $p['last_verified'] ) ) . '</small></p>';
			}
			return $out . '</section>';
		}

		/* -------------------------------------------------------------
		 * Draft from the site
		 * ----------------------------------------------------------- */

		/**
		 * Read a draft profile from what the site already says. Nothing is saved.
		 *
		 * @return array { draft, sources }
		 */
		public static function extract() {
			$draft   = self::defaults();
			$sources = array();

			$draft['name']    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$draft['summary'] = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
			$sources[]        = 'WordPress site title and tagline';

			if ( class_exists( 'Uich_SEO_Settings' ) ) {
				$seo = (array) Uich_SEO_Settings::all();
				if ( ! empty( $seo['entity_name'] ) ) {
					$draft['name'] = (string) $seo['entity_name'];
				}
				if ( ! empty( $seo['entity_description'] ) ) {
					$draft['description'] = (string) $seo['entity_description'];
				}
				if ( ! empty( $seo['entity_phone'] ) ) {
					$draft['contacts']['phone'] = (string) $seo['entity_phone'];
				}
				if ( ! empty( $seo['entity_email'] ) ) {
					$draft['contacts']['email'] = (string) $seo['entity_email'];
				}
				if ( ! empty( $seo['social_profiles'] ) ) {
					$draft['social'] = array_values( (array) $seo['social_profiles'] );
				}
				$sources[] = 'SEO module site identity';
			}

			if ( class_exists( 'WooCommerce' ) && get_option( 'woocommerce_store_address' ) ) {
				$country = (string) get_option( 'woocommerce_default_country', '' );
				$parts   = explode( ':', $country );
				$draft['locations'][] = array(
					'name'    => '',
					'address' => array(
						'street'   => trim( get_option( 'woocommerce_store_address' ) . ' ' . get_option( 'woocommerce_store_address_2' ) ),
						'city'     => (string) get_option( 'woocommerce_store_city' ),
						'region'   => isset( $parts[1] ) ? $parts[1] : '',
						'postcode' => (string) get_option( 'woocommerce_store_postcode' ),
						'country'  => $parts[0],
					),
				);
				$draft['type'] = 'OnlineStore';
				$sources[]     = 'WooCommerce store address';
			}

			// The rendered home page: schema, phone and email links.
			$resp = Uich_Agent_Urls::fetch_own( home_url( '/' ), 'text/html' );
			$html = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
			if ( '' !== $html ) {
				if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
					foreach ( $m[1] as $json ) {
						$data  = json_decode( html_entity_decode( $json ), true );
						$nodes = isset( $data['@graph'] ) ? (array) $data['@graph'] : array( $data );
						foreach ( $nodes as $node ) {
							if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
								continue;
							}
							$type = is_array( $node['@type'] ) ? reset( $node['@type'] ) : $node['@type'];
							if ( in_array( $type, self::types(), true ) ) {
								if ( 'Organization' !== $type ) {
									$draft['type'] = $type;
								}
								if ( ! empty( $node['telephone'] ) && '' === $draft['contacts']['phone'] ) {
									$draft['contacts']['phone'] = (string) $node['telephone'];
								}
								if ( ! empty( $node['priceRange'] ) ) {
									$draft['price_range'] = (string) $node['priceRange'];
								}
								$sources[] = 'Home page schema (' . $type . ')';
							}
						}
					}
				}
				if ( '' === $draft['contacts']['phone'] && preg_match( '#href=["\']tel:([^"\']+)#i', $html, $t ) ) {
					$draft['contacts']['phone'] = rawurldecode( $t[1] );
					$sources[]                  = 'Phone link on the home page';
				}
				if ( '' === $draft['contacts']['email'] && preg_match( '#href=["\']mailto:([^"\'?]+)#i', $html, $e ) ) {
					$draft['contacts']['email'] = rawurldecode( $e[1] );
					$sources[]                  = 'Email link on the home page';
				}
			}

			return array(
				'draft'   => self::sanitize( $draft ),
				'sources' => array_values( array_unique( $sources ) ),
				'missing' => array_keys(
					array_filter(
						array(
							'summary'   => '' === $draft['summary'],
							'phone'     => '' === $draft['contacts']['phone'],
							'locations' => empty( $draft['locations'] ),
							'hours'     => true,
						)
					)
				),
				'note'    => 'A draft read from your site. Check every fact before saving; nothing is saved yet.',
			);
		}

		/* -------------------------------------------------------------
		 * Ability actions
		 * ----------------------------------------------------------- */

		/**
		 * Add profile actions.
		 *
		 * @param array $actions Actions.
		 * @return array
		 */
		public static function actions( array $actions ) {
			$actions['get-profile']       = array(
				'description' => 'The confirmed site profile (business facts agents use) and the allowed business types.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_get' ),
			);
			$actions['extract-profile']   = array(
				'description' => 'Read a draft profile from what the site already says (home page schema, phone and email links, SEO site identity, WooCommerce store address). Nothing is saved; confirm with the owner first.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'act_extract' ),
			);
			$actions['update-profile']    = array(
				'description' => 'Save profile facts the owner confirmed. Send the top-level keys to replace: name, type, summary, description, locations [{name,address{street,city,region,postcode,country},geo{lat,lng},phone,map_url,hours{mon..sun:[["09:00","17:00"]]},special_hours[{date,closed,hours,note}],timezone}], contacts {phone,whatsapp,email,booking_url,order_links[{title,url,note}]}, price_range, service_area, languages, policies [{title,url,note}], social [urls].',
				'params'      => array(
					'profile' => array(
						'type'        => 'object',
						'description' => 'Profile keys to replace.',
					),
				),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'act_update' ),
			);
			return $actions;
		}

		/**
		 * Action: get-profile.
		 *
		 * @return array
		 */
		public static function act_get() {
			return array(
				'profile' => self::get(),
				'exists'  => self::exists(),
				'types'   => self::types(),
			);
		}

		/**
		 * Action: extract-profile.
		 *
		 * @return array
		 */
		public static function act_extract() {
			return self::extract();
		}

		/**
		 * Action: update-profile.
		 *
		 * @param array $params { profile }.
		 * @return array|WP_Error
		 */
		public static function act_update( array $params ) {
			if ( empty( $params['profile'] ) || ! is_array( $params['profile'] ) ) {
				return new WP_Error( 'uich_agent_profile', 'Pass "profile" with the keys to replace.' );
			}
			$saved = self::update( $params['profile'] );
			return is_wp_error( $saved ) ? $saved : array( 'profile' => $saved );
		}

		/**
		 * One line of plain text.
		 *
		 * @param mixed $v   Value.
		 * @param int   $max Max length.
		 * @return string
		 */
		private static function line( $v, $max ) {
			return mb_substr( sanitize_text_field( wp_strip_all_tags( (string) $v ) ), 0, $max );
		}

		/**
		 * A short label (language names, etc.).
		 *
		 * @param mixed $v Value.
		 * @return string
		 */
		private static function short( $v ) {
			return self::line( $v, 40 );
		}

		/**
		 * One "agents can..." sentence.
		 *
		 * @param mixed $v Value.
		 * @return string
		 */
		private static function can_do_line( $v ) {
			return self::line( $v, 160 );
		}

		/**
		 * A phone number: digits, spaces, + ( ) - only.
		 *
		 * @param mixed $v Value.
		 * @return string
		 */
		private static function phone( $v ) {
			return substr( trim( preg_replace( '/[^\d+()\-\s]/', '', (string) $v ) ), 0, 40 );
		}

		/**
		 * Links [{title,url,note}].
		 *
		 * @param mixed $links Raw.
		 * @param int   $max   Max items.
		 * @return array
		 */
		private static function links( $links, $max ) {
			$out = array();
			foreach ( (array) $links as $l ) {
				if ( ! is_array( $l ) ) {
					continue;
				}
				$url = esc_url_raw( isset( $l['url'] ) ? (string) $l['url'] : '', array( 'http', 'https' ) );
				if ( '' === $url ) {
					continue;
				}
				$out[] = array(
					'title' => self::line( isset( $l['title'] ) ? $l['title'] : $url, 80 ),
					'url'   => $url,
					'note'  => self::line( isset( $l['note'] ) ? $l['note'] : '', 160 ),
				);
			}
			return array_slice( $out, 0, $max );
		}
	}
}
