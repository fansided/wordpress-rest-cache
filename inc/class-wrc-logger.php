<?php
/**
 * Class WRC_Logger
 *
 * This class is used for logging to either file or database,
 * which is managed in the WP Admin > Settings > General
 *
 * This is a tool that was originally written to be able to debug cron statuses for REST Cache
 *
 */

class WRC_Logger {

	const LOG_DIR = __DIR__ . '/../logs/';
	const DETAIL_GLUE = '------------------- [DETAIL] ';
	const SETTING_FLAG = 'wrc_logger';
	const LOGGING_PREFIX = 'wrc-logs-';
	private $log_file = false, $method = 'off', $logging_key = null;

	public function __construct( $handler ) {
		$this->method = strtolower( get_option( self::SETTING_FLAG, 'off' ) );
		$this->init( $handler );
	}

	public function __destruct() {
		switch ( $this->method ) {
			case 'file':
				fclose( $this->log_file );
				break;
			case 'database':
			default:
				// Ahh... silence
				break;
		}
	}

	public function log( $message, $details = array() ) {
		$this->write( 'LOG', $message, $details );

	}

	public function warn( $message, $details = array() ) {
		$this->write( 'WARN', $message, $details );
	}

	public function error( $message, $details = array() ) {
		$this->write( 'ERROR', $message, $details );
	}

	private function write( $level, $message, $details = array() ) {
		switch ( $this->method ) {
			case 'file':
				fwrite( $this->log_file, date( 'Y-m-d H:i:s' ) . ' [' . $level . '] ' . trim( $message ) . "\r\n" );
				foreach ( $details as $detail ) {
					fwrite( $this->log_file, self::DETAIL_GLUE . trim( $detail ) . "\r\n" );
				}
				break;
			case 'database':
				$last_log = get_option( $this->logging_key );
				$log      = maybe_unserialize( $last_log );
				$log[]    = date( 'Y-m-d H:i:s' ) . ' [' . $level . '] ' . trim( $message );
				foreach ( $details as $detail ) {
					$log[] = self::DETAIL_GLUE . trim( $detail );
				}
				update_option( $this->logging_key, $log );
				break;
			default:
				// Ahh... silence
				break;
		}
	}

	private function init( $handler ) {
		$this->logging_key = self::LOGGING_PREFIX . preg_replace( '/[^a-z0-9]/', '-', strtolower( $handler ) ) . '-' . date( 'Y-m-d' );
		switch ( $this->method ) {
			case 'file':
				$logger = self::LOG_DIR . $this->logging_key . '.log';
				if ( file_exists( $logger ) && ! is_writable( $logger ) ) {
					return $this->log_file;
				}
				if ( ! ( $this->log_file = fopen( $logger, 'a' ) ) ) {
					$this->method = 'database';
					$this->init( $handler );
				}
				break;
			case 'database':
				break;
			default:
				// Don't want to log anything.
				$this->log_file = false;
				break;
		}
	}

	public static function site_setting() {
		if ( ! defined( 'DOING_CRON' ) && ! defined( 'DOING_AJAX' ) && function_exists( 'add_settings_field' ) ) {
			try {
				register_setting( 'general', self::SETTING_FLAG, array(
					'type'         => 'string',
					'description'  => 'Choose how you would like the cron job for WP REST Cache to be logged, if at all.',
					'show_in_rest' => false,
					'default'      => 'off',
				) );
				add_settings_field( self::SETTING_FLAG, 'WRC Cron Logging', array(
					get_called_class(),
					'wrc_log_setting',
				), 'general' );
			} catch ( Error $error ) {
				// Dang, fired to early
			} catch ( Exception $exception ) {
				// Dang, fired to early
			}
		}
	}

	/**
	 * Show a slug input box.
	 */
	public static function wrc_log_setting() {
		$logger = strtolower( get_option( self::SETTING_FLAG, 'off' ) );
		?>
		<select name="wrc_logger">
			<option value="off"<?php echo( ( 'off' == $logger ) ? ' selected' : '' ); ?>>Disabled</option>
			<option vlaue="database"<?php echo( ( 'database' == $logger ) ? ' selected' : '' ); ?>>Database</option>
			<option value="file"<?php echo( ( 'file' == $logger ) ? ' selected' : '' ); ?>>File</option>
		</select>
		<?php
	}
}