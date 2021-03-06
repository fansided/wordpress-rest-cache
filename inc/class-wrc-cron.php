<?php

/**
 * Class WP_Http_Cache
 *
 * While this class used to hook into the Http Transports,
 * as of WordPress 4.6 it now uses pre_http_request and http_requests
 * filter since the transports filter is no longer used.
 *
 * TODO: consider "paginating" the cached updating via cron. Currently one cron executes to loop over the rows that
 * need new calls/updates
 */
class WRC_Cron {

	public static $logger = null;

	/**
	 * Initialize
	 */
	static function init() {
		// set up any Cron needs, this should be able to run independently of the front-end processes
		add_action( 'wp', array( get_called_class(), 'schedule_cron' ) );
		add_action( 'wp_rest_cache_cron', array( get_called_class(), 'check_cache_for_updates' ) );
		add_action( 'wp_rest_cache_expired_cron', array( get_called_class(), 'check_expired_cache' ) );
		add_filter( 'cron_schedules', array( get_called_class(), 'add_schedule_interval' ) );
	}

	/**
	 * Create the interval that we need for our cron that checks in on rest data.
	 *
	 * @since 0.1.0
	 *
	 * @param $schedules
	 *
	 * @return mixed
	 */
	public static function add_schedule_interval( $schedules ) {

		$schedules['5_minutes'] = array(
			'interval' => 300, // 5 minutes in seconds
			'display'  => 'Once every 5 minutes',
		);

		return $schedules;
	}

	/**
	 * Set up the initial cron
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	static function schedule_cron() {
		$is_multisite = is_multisite();
		if ( $is_multisite ) {
			$primary_blog = get_current_site();
			$current_blog = get_current_blog_id();
		} else {
			$primary_blog = 1;
			$current_blog = 1;
		}

		/**
		 * If we're on a multisite, only schedule the cron if we're on the primary blog
		 */
		if (
		( ! $is_multisite || ( $is_multisite && $primary_blog->id === $current_blog ) )
		) {
			$cronsScheduled = false;
			if( ! wp_next_scheduled( 'wp_rest_cache_cron' ) ) {
				wp_schedule_event( time(), '5_minutes', 'wp_rest_cache_cron' );
				$cronsScheduled = true;
			}
			if( ! wp_next_scheduled( 'wp_rest_cache_expired_cron' ) ) {
				wp_schedule_event( time(), 'hourly', 'wp_rest_cache_expired_cron' );
				$cronsScheduled = true;
			}
			if( $cronsScheduled ) {
				do_action( 'wrc_after_schedule_cron', $primary_blog, $current_blog );
			}
		}
	}

	/**
	 * Check the cache table for rows that need updated during our cron.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	static function check_cache_for_updates() {
		/**
		 * Search our custom DB table for where rest_to_update === 1.
		 * For each  one that === 1, we need to trigger a new wp_remote_get using the args.
		 * We need to split each one of these out into its own execution, so we don't time
		 * out PHP by, for example, running ten 7-second calls in a row.
		 */
		global $wpdb;
		$limit = 2000;
		if ( class_exists( 'WRC_Logger' ) ) {
			self::$logger = new WRC_Logger( get_called_class() );
			$cron_limit   = get_option( WRC_Logger::SETTING_FLAG . '_limit', '2000' );
			if ( is_numeric( $cron_limit ) ) {
				$limit = $cron_limit;
			}
		}

		$query   = '
SELECT 
	rest_domain, 
	rest_path, 
	rest_args, 
	rest_query_args
FROM ' . REST_CACHE_TABLE . ' 
WHERE rest_to_update = 1 
LIMIT ' . $limit;
		$results = $wpdb->get_results( $query, ARRAY_A );

		if ( is_array( $results ) && ! empty( $results ) ) {
			$cache_to_clear = count( $results );
			$cache_cleared  = $cache_failed = $cache_attempted = 0;
			self::maybe_log( 'log', 'Found ' . $cache_to_clear . ' records we need to update cache for.' );
			foreach ( $results as $row ) {

				// run maybe_unserialize on rest_args and check to see if the update arg is set and set to false if it is
				$args = maybe_unserialize( $row['rest_args'] );
				$url  = $row['rest_domain'] . $row['rest_path'];
				$url .= ( ! empty( $row['rest_query_args'] ) ) ? '?' . trim( $row['rest_query_args'] ) : '';
				if ( ! empty( $args['wp-rest-cache']['update'] ) ) {
					$args['wp-rest-cache']['update'] = 0;
				}

				/**
				 * Make the call as a wp_safe_remote_get - the response will be saved when we run
				 * `apply_filters( 'http_response', $response, $args, $url )` below
				 */
				$response = wp_remote_get( $url, $args );

				$cache_attempted ++;
				if ( is_object( $response ) && 'WP_Error' == get_class( $response ) ) {
					$cache_failed ++;
					self::maybe_log( 'error', 'FAIL: ' . $cache_attempted . ' of ' . $cache_to_clear . ' ( ' . $url . ' )', $response->get_error_messages() );
				} else {
					if ( $response ) {
						try {
							self::store_data( $response, $args, $url );
							self::maybe_log( 'log', 'DONE: ' . $cache_attempted . ' of ' . $cache_to_clear );
							$cache_cleared ++;
						} catch ( Exception $ex ) {
							self::maybe_log( 'error', 'EXCEPTION: ' . $ex->getMessage() );
							$cache_failed ++;
						} catch ( Error $er ) {
							self::maybe_log( 'error', 'ERROR: ' . $er->getMessage() );
							$cache_failed ++;
						}
					} else {
						self::maybe_log( 'warn', 'Should never hit here.' );
					}
				}
			}
			if ( $cache_cleared == $cache_to_clear ) {
				self::maybe_log( 'log', 'Everything has been cleared as it should!' );
			}
			if ( $cache_failed ) {
				self::maybe_log( 'warn', 'Failed to update ' . $cache_failed . ' of ' . $cache_to_clear );
			}
		} else {
			self::maybe_log( 'log', 'All cache looks to be up to date.' );
		}
		self::maybe_log( 'log', get_called_class() . ' has been completed.' );

		return;
	}

	/**
	 * Retrieve rows that have not been requested after their expiration
	 * Limiting to records older than 1 year
	 */
	static function check_expired_cache(){

		global $wpdb;
		$limit = 2000;
		if ( class_exists( 'WRC_Logger' ) ) {
			self::$logger = new WRC_Logger( get_called_class() );
			$cron_limit   = get_option( WRC_Logger::SETTING_FLAG . '_limit', '2000' );
			if ( is_numeric( $cron_limit ) ) {
				$limit = $cron_limit;
			}
		}

		$expiredBefore = date('Y-m-d', strtotime('-1 year'));

		$query   = '
DELETE FROM ' . REST_CACHE_TABLE . ' 
WHERE  rest_expires < "' . $expiredBefore . '" AND rest_to_update=0
LIMIT ' . $limit;

		try {
			if ( $wpdb->query( $query ) ) {
				// executed successfully
			} else {
				if ( function_exists( 'newrelic_notice_error' ) ) {
					newrelic_notice_error( 'CRON FAIL: Unable to perform cleanup on un-requested old API cache. Limit ' . $limit . ', Expired ' . $expiredBefore );
				}
			}
		}catch( \Exception $e){
			if ( function_exists( 'newrelic_notice_error' ) ) {
				newrelic_notice_error( 'CRON FAIL: '.$e->getMessage().'. Limit ' . $limit . ', Expired ' . $expiredBefore );
			}
		}
		return;

	}

	/**
	 * Save or update cached data in our custom table based on the md5'd URL
	 *
	 * TODO: get rid of the redundancy between this version of store_data and the version in WRC_Caching class
	 *
	 * @since 0.1.0
	 *
	 * @param      $response
	 * @param      $args
	 * @param      $url
	 *
	 * @return mixed
	 */
	static function store_data( $response, $args, $url ) {
		$status_code = wp_remote_retrieve_response_code( $response );

		// don't try to store if we don't have a 200 response
		if (
			true == apply_filters( 'wrc_only_cache_200', false )
			&& 200 != $status_code
		) {
			return $response;
		}

		// if no cache expiration is set, we'll set the default expiration time
		if ( empty( $args['wp-rest-cache']['expires'] ) ) {
			$args['wp-rest-cache']['expires'] = WP_Rest_Cache::$default_expires;
		}

		$expiration_date = WP_Rest_Cache::get_expiration_date( $args['wp-rest-cache']['expires'], $status_code );

		global $wpdb;

		// if you're on PHP < 5.4.7 make sure you're not leaving the scheme out, as it'll screw up parse_url
		$parsed_url = parse_url( $url );
		$scheme     = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host       = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$port       = isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$user       = isset( $parsed_url['user'] ) ? $parsed_url['user'] : '';
		$pass       = isset( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
		$pass       = ( $user || $pass ) ? $pass . '@' : '';
		$path       = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$query      = isset( $parsed_url['query'] ) ? $parsed_url['query'] : '';
		$fragment   = isset( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';

		// Organize Query Args
		if( ! empty( $query ) ) {
			$query_args = explode( '&', $query );
			sort( $query_args, SORT_STRING );
			$query = implode( '&', $query_args );
		}

		// a domain could potentially not have a scheme, in which case we need to skip appending the colon
		$domain = $scheme . $user . $pass . $host . $port;
		$query .= $fragment;

		$tag    = ! empty( $args['wp-rest-cache']['tag'] ) ? $args['wp-rest-cache']['tag'] : '';
		$update = ! empty( $args['wp-rest-cache']['update'] ) ? $args['wp-rest-cache']['update'] : 0;
		$md5    = md5( strtolower( $domain . $path . $query ) );

		$data = array(
			'rest_md5'            => $md5,
			'rest_domain'         => $domain,
			'rest_path'           => $path,
			'rest_query_args'     => $query,
			'rest_response'       => maybe_serialize( $response ),
			'rest_expires'        => $expiration_date,
			'rest_last_requested' => date( 'Y-m-d', time() ),
			// current UTC time
			'rest_tag'            => $tag,
			'rest_to_update'      => $update,
			'rest_args'           => '',
			'rest_status_code'    => $status_code,
		);

		// either update or insert
		$wpdb->replace( REST_CACHE_TABLE, $data );

		return $response;
	}

	static function maybe_log( $level, $message, $details = array() ) {
		if ( ! is_null( self::$logger ) ) {
			if ( in_array( $level, array( 'log', 'warn', 'error' ) ) ) {
				self::$logger->$level( $message, $details );
			} else {
				self::$logger->warn( 'Logger attempted to write invalid level. (' . sanitize_text_field( $level ) . ')' );
			}
		}
	}
}