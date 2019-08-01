<?php

/**
 * Class WP_Http_Cache
 *
 * While this class used to hook into the Http Transports,
 * as of WordPress 4.6 it now uses pre_http_request and http_requests
 * filter since the transports filter is no longer used.
 *
 */
class WRC_Caching {
	/**
	 * Initialize
	 */
	static function init() {
		// ensure our filters don't run during crons
		if ( ! defined( 'DOING_CRON' ) ) {
			add_filter( 'pre_http_request', array( get_called_class(), 'pre_http_request' ), 9, 3 );
			// If it gets past pre_http_request and to the http response filter,
			// check if we should create/update the data via store_data
			add_filter( 'http_response', array( get_called_class(), 'store_data' ), 9, 3 );
		}
	}

	/**
	 * Save or update cached data in our custom table based on the md5'd URL
	 * *** Note, there can only be 3 arguments to this function because it's
	 * run on the `http_response` filter.
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
			(
				true == apply_filters( 'wrc_only_cache_200', false )
				&& 200 != $status_code
			)
			// only check is_cacheable_call if we're not running force update.
			// Force update is usually set during cron, at which point we already know it's a cacheable call
			|| false === static::is_cacheable_call( $args, $url )
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
			// current UTC time
			'rest_last_requested' => date( 'Y-m-d', time() ),
			'rest_tag'            => $tag,
			'rest_to_update'      => $update,
			// Always set args to an empty string - they're only stored on "check expired" so the cron has info it needs.
			'rest_args'           => '',
			'rest_status_code'         => $status_code,
		);

		// either update or insert
		$wpdb->replace( REST_CACHE_TABLE, $data );

		return $response;
	}

	/**
	 * Verifies that a remote call is cacheable based on query args and URL
	 *
	 * @since 0.9.0
	 *
	 * @param $args
	 * @param $url
	 *
	 * @return bool
	 */
	static function is_cacheable_call( $args, $url ) {
		// the first easy to check params are if a filename exists or if the rest cache param is set to "exclude"
		if (
			! empty( $args['filename'] )
			|| ( ! empty( $args['wp-rest-cache'] ) && 'exclude' === $args['wp-rest-cache'] )
		) {
			return false;
		}

		$method = ! empty( $args['method'] ) ? strtolower( $args['method'] ) : '';

		// if the domain matches one in the exclusions list, skip it
		$check_url  = parse_url( $url );
		$exclusions = apply_filters( 'wp_rest_cache_exclusions', WP_REST_CACHE_EXCLUSIONS );
		// this could end up being an array already depending on how someone filters it, only explode as necessary
		if ( ! is_array( $exclusions ) ) {
			$exclusions = explode( ',', $exclusions );
		}

		// TODO: fix the exclusions functionality...

		if ( 'get' !== $method || in_array( $check_url['host'], $exclusions ) || ! empty( $_REQUEST['force-check'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Utilize the pre_http_request filter as the filter used
	 * previously (http_api_transports) is useless as of WP version 4.6
	 *
	 * @since 0.9.0
	 *
	 * @param $preempt
	 * @param $args
	 * @param $url
	 *
	 * @return bool
	 */
	static function pre_http_request( $preempt, $args, $url ) {
		/**
		 * Returning false will simply allow the request to continue,
		 * though we'll need to be sure to remove the other http_request
		 * filter so that doesn't get run.
		 */
		if ( ! static::is_cacheable_call( $args, $url ) ) {
			remove_filter( 'http_response', array( get_called_class(), 'store_data' ), 1 );

			return false;
		}

		// if we've made it past all of the above checks, continue on with running the HTTP request
		/**
		 * If this is indeed a cacheable request, return the actual data via our
		 * `maybe_return_requested_data` function which will either return 'false'
		 * if the actual request still needs to be made or it will return the
		 * currently stored result.
		 */

		return static::maybe_return_requested_data( $url, $args );
	}

	/**
	 * Either return a cached result or run an HTTP curl request
	 *
	 * @since 0.1.0
	 *
	 * @param      $url
	 * @param      $args
	 *
	 * @return array|mixed|WP_Error
	 */
	static function maybe_return_requested_data( $url, $args ) {

		$cached_request = static::maybe_cached_request( $url, $args );

		if ( ! empty( $cached_request['rest_response'] ) ) {
			return maybe_unserialize( $cached_request['rest_response'] );
		}

		// false is returned because it tells the `pre_http_request` filter that it needs to move on to the actual http request
		return false;
	}

	/**
	 * Pull the cached data row from our custom table by matching the md5'd URL
	 *
	 * @since 0.1.0
	 *
	 * @param $url
	 *
	 * @return array|bool|null|object|void
	 */
	static function get_data( $url ) {
		global $wpdb;

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

		$md5    = md5( strtolower( $domain . $path . $query ) );

		$data = $wpdb->get_row( 'SELECT * FROM ' . REST_CACHE_TABLE . ' WHERE rest_md5 = "' . $md5 . '" ', ARRAY_A );

		// if the query doesn't return a row from the DB, return false
		if ( null === $data ) {
			return false;
		}

		return $data;
	}

	/**
	 * Check to see if we've already got this call stored and if it's expired
	 *
	 * @since 0.1.0
	 *
	 * @param $url
	 * @param $args
	 *
	 * @return array|bool|null|object|void
	 */
	public static function maybe_cached_request( $url, $args ) {
		$data = static::get_data( $url );

		if ( ! empty( $data ) ) {
			// check to see if this request is expired
			static::check_for_expired_result( $data, $args );

			return $data;
		}

		return false;
	}

	/**
	 * Compares the current time in the row returned from static::get_data()
	 * We're also documenting the "rest_last_requested" info here
	 *
	 * @param array $data The full result from get_data, passed in via maybe_cached_request
	 * @param array $args Args passed into the initial request
	 *
	 * @since 0.1.0
	 */
	protected static function check_for_expired_result( $data, $args ) {
		/**
		 * TODO: get guaranteed UTC time here, Seth and Justin had to do the same
		 */
		global $wpdb;
		$doUpdate = false;
		switch(true){
			case 1 == $data['rest_to_update']:
				$doUpdate = true;
				break;
			case strtotime( $data['rest_expires'] ) < time():
				$doUpdate = true;
				$data['rest_args']      = maybe_serialize( $args );
				$data['rest_to_update'] = 1;
				break;
		}
		if($doUpdate){
			$data['rest_last_requested'] = date('Y-m-d');
			$wpdb->replace( REST_CACHE_TABLE, $data );
		}
	}
}
