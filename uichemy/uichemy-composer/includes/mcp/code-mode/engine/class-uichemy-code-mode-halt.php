<?php
/**
 * Stops a Code Mode run for a reason the script must not be able to catch
 * (a limit, a refused construct), as opposed to the exceptions a script
 * throws and catches itself.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Halt' ) ) {

	/**
	 * An uncatchable stop, with the error kind it reports.
	 */
	class UiChemy_Code_Mode_Halt extends \Exception {

		/** @var string 'limit', 'refused' or 'runtime'. */
		public $kind;

		/** @var int The script line it stopped on. */
		public $script_line;

		/**
		 * @param string $kind    Error kind.
		 * @param string $message Message.
		 * @param int    $line    Script line.
		 */
		public function __construct( $kind, $message, $line = 0 ) {
			parent::__construct( (string) $message );
			$this->kind        = (string) $kind;
			$this->script_line = (int) $line;
		}
	}
}
