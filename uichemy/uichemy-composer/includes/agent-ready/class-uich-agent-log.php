<?php
/**
 * Agent Ready call log: one JSON line per site-MCP call and agent-file hit.
 *
 * Files only (no database writes on the public path), one file per day in the
 * cache folder, which is denied to the web. IP addresses are never stored:
 * only a salted hash, enough to spot one client flooding the site. Files older
 * than the retention setting are deleted daily.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Log' ) ) {

	/**
	 * Append-only daily log.
	 */
	class Uich_Agent_Log {

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'uichemy_agent_ready_cache_cleanup', array( __CLASS__, 'prune' ) );
		}

		/**
		 * Append one entry.
		 *
		 * @param string $kind  'mcp' or 'page'.
		 * @param array  $entry Fields (tool, ok, ms, status...). Strings are cut to 200 chars.
		 * @return void
		 */
		public static function add( $kind, array $entry ) {
			$dir = self::dir();
			if ( ! $dir ) {
				return;
			}
			$ip   = Uich_Agent_Urls::client_ip( (string) Uich_Agent_Settings::value( 'mcp.trusted_proxy', '' ) );
			$line = array(
				't'    => gmdate( 'c' ),
				'kind' => $kind,
				'ip'   => substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 16 ),
				'ua'   => self::agent_name(),
			);
			foreach ( $entry as $key => $value ) {
				$line[ sanitize_key( $key ) ] = is_scalar( $value ) ? ( is_string( $value ) ? substr( $value, 0, 200 ) : $value ) : null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- append-only log.
			// Saved as .jsonl.php behind an exit guard: servers that ignore .htaccess (nginx) would
			// otherwise serve the log file as plain text. The reader skips the guard line.
			$file = $dir . gmdate( 'Y-m-d' ) . '.jsonl.php';
			$head = is_file( $file ) ? '' : "<?php exit; ?>\n";
			@file_put_contents( $file, $head . wp_json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX );
		}

		/**
		 * Read recent entries, newest first.
		 *
		 * @param int $days  How many days back.
		 * @param int $limit Max entries.
		 * @return array
		 */
		public static function recent( $days = 7, $limit = 500 ) {
			$dir = self::dir();
			if ( ! $dir ) {
				return array();
			}
			$out = array();
			for ( $i = 0; $i < max( 1, (int) $days ) && count( $out ) < $limit; $i++ ) {
				$file = $dir . gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) . '.jsonl.php';
				if ( ! is_file( $file ) ) {
					continue;
				}
				$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
				foreach ( array_reverse( (array) $lines ) as $line ) {
					$row = json_decode( $line, true );
					if ( is_array( $row ) ) {
						$out[] = $row;
					}
					if ( count( $out ) >= $limit ) {
						break;
					}
				}
			}
			return $out;
		}

		/**
		 * Daily totals by kind and by tool.
		 *
		 * @param int $days Days back.
		 * @return array
		 */
		public static function summary( $days = 7 ) {
			$rows    = self::recent( $days, 20000 );
			$summary = array(
				'days'   => (int) $days,
				'total'  => count( $rows ),
				'mcp'    => 0,
				'page'   => 0,
				'errors' => 0,
				'tools'  => array(),
				'agents' => array(),
				'daily'  => array(),
			);
			// One entry per day, oldest first, so a chart can show every day even when it is empty.
			$daily = array();
			for ( $i = max( 1, (int) $days ) - 1; $i >= 0; $i-- ) {
				$daily[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = array(
					'total' => 0,
					'mcp'   => 0,
					'page'  => 0,
				);
			}
			$visitors = array();
			foreach ( $rows as $row ) {
				$day = isset( $row['t'] ) ? substr( (string) $row['t'], 0, 10 ) : '';
				if ( isset( $daily[ $day ] ) ) {
					++$daily[ $day ]['total'];
					if ( isset( $row['kind'] ) && isset( $daily[ $day ][ $row['kind'] ] ) ) {
						++$daily[ $day ][ $row['kind'] ];
					}
				}
				if ( ! empty( $row['ip'] ) ) {
					$visitors[ $row['ip'] ] = true;
				}
				$kind = isset( $row['kind'] ) ? $row['kind'] : '';
				if ( isset( $summary[ $kind ] ) && is_int( $summary[ $kind ] ) ) {
					++$summary[ $kind ];
				}
				if ( isset( $row['ok'] ) && ! $row['ok'] ) {
					++$summary['errors'];
				}
				if ( ! empty( $row['tool'] ) ) {
					$summary['tools'][ $row['tool'] ] = ( isset( $summary['tools'][ $row['tool'] ] ) ? $summary['tools'][ $row['tool'] ] : 0 ) + 1;
				}
				$ua = isset( $row['ua'] ) ? $row['ua'] : 'other';
				$summary['agents'][ $ua ] = ( isset( $summary['agents'][ $ua ] ) ? $summary['agents'][ $ua ] : 0 ) + 1;
			}
			arsort( $summary['tools'] );
			arsort( $summary['agents'] );
			foreach ( $daily as $date => $counts ) {
				$summary['daily'][] = array_merge( array( 'date' => $date ), $counts );
			}
			$summary['visitors'] = count( $visitors );
			return $summary;
		}

		/**
		 * Delete log files older than the retention setting.
		 *
		 * @return void
		 */
		public static function prune() {
			$dir = self::dir();
			if ( ! $dir ) {
				return;
			}
			$keep = (int) Uich_Agent_Settings::value( 'logs.retention_days', 30 );
			self::purge_legacy();
			foreach ( (array) glob( $dir . '*.jsonl.php' ) as $file ) {
				if ( filemtime( $file ) < time() - $keep * DAY_IN_SECONDS ) {
					wp_delete_file( $file );
				}
			}
		}

		/**
		 * Remove plain .jsonl logs from older versions: they were readable from the web.
		 *
		 * @return void
		 */
		public static function purge_legacy() {
			$dir = self::dir();
			if ( ! $dir ) {
				return;
			}
			foreach ( (array) glob( $dir . '*.jsonl' ) as $legacy ) {
				wp_delete_file( $legacy );
			}
		}

		/**
		 * Log folder.
		 *
		 * @return string|false
		 */
		private static function dir() {
			$root = Uich_Agent_Store::cache_root();
			if ( is_wp_error( $root ) ) {
				return false;
			}
			$dir = $root . 'logs/';
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return false;
			}
			return $dir;
		}

		/**
		 * Remember the MCP client name (initialize clientInfo) for this IP.
		 *
		 * @param string $name Client name.
		 * @return void
		 */
		public static function remember_client( $name ) {
			$name = substr( sanitize_text_field( $name ), 0, 60 );
			if ( '' !== $name ) {
				set_transient( 'uich_agent_client_' . self::ip_key(), $name, DAY_IN_SECONDS );
			}
		}

		/**
		 * Short hash of the caller's IP.
		 *
		 * @return string
		 */
		private static function ip_key() {
			$ip = Uich_Agent_Urls::client_ip( (string) Uich_Agent_Settings::value( 'mcp.trusted_proxy', '' ) );
			return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 16 );
		}

		/**
		 * A short, known agent name from the user agent, or the MCP client name
		 * this IP sent at initialize; never the raw user agent.
		 *
		 * @return string
		 */
		private static function agent_name() {
			$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against a fixed list only.
			$known = array(
				'chatgpt-user'    => 'ChatGPT',
				'oai-searchbot'   => 'OpenAI search',
				'gptbot'          => 'GPTBot',
				'claude-user'     => 'Claude',
				'claude-searchbot' => 'Claude search',
				'claudebot'       => 'ClaudeBot',
				'perplexity'      => 'Perplexity',
				'google-agent'    => 'Google agent',
				'googleagent'     => 'Google agent',
				'google-extended' => 'Google-Extended',
				'gemini'          => 'Gemini',
				'grok'            => 'Grok',
				'xai'             => 'Grok',
				'bingbot'         => 'Bing',
				'applebot'        => 'Applebot',
				'meta-external'   => 'Meta',
				'mistral'         => 'Mistral',
				'cursor'          => 'Cursor',
				'claude-code'     => 'Claude Code',
				'claude'          => 'Claude',
				'anthropic'       => 'Claude',
				'openai'          => 'ChatGPT',
				'chatgpt'         => 'ChatGPT',
				'windsurf'        => 'Windsurf',
				'copilot'         => 'Copilot',
				'vscode'          => 'VS Code',
				'mcp-remote'      => 'MCP client',
				'mcp-inspector'   => 'MCP Inspector',
			);
			foreach ( $known as $needle => $name ) {
				if ( false !== strpos( $ua, $needle ) ) {
					return $name;
				}
			}
			$client = get_transient( 'uich_agent_client_' . self::ip_key() );
			if ( $client ) {
				$c = strtolower( $client );
				foreach ( $known as $needle => $name ) {
					if ( false !== strpos( $c, $needle ) ) {
						return $name;
					}
				}
				return $client;
			}
			return '' === $ua ? 'unknown' : 'other';
		}
	}
}
