<?php
/**
 * Class WRC_Trash_Cron
 *
 * @since 1.4.0
 */
class WRC_Trash_Cron {
	static $frequency_in_hours = 24;
	static $delete_older_than = 7; // delete cache items older than {num} days
	static $query_limit = 1000;
	const CRON_NAME = 'wp_rest_cache_trash_cron';

	/**
	 * Initialize
	 */
	static function init() {
		// Set up any Cron needs, this should be able to run independently of the front-end processes
		add_action( 'wp', array( get_called_class(), 'schedule_cron' ) );
		add_action( static::CRON_NAME, array( get_called_class(), 'check_cache_for_trash' ) );
		add_filter( 'cron_schedules', array( get_called_class(), 'add_schedule_interval' ) );
	}

	/**
	 * Create the interval that we need for our cron that checks in on rest data.
	 *
	 * @since 1.4.0
	 *
	 * @param $schedules
	 *
	 * @return mixed
	 */
	public static function add_schedule_interval( $schedules ) {
		$frequency = static::$frequency_in_hours * HOUR_IN_SECONDS;

		$schedules['wp_rest_cache_trash'] = array(
			'interval' => $frequency,
			'display'  => 'Every ' . static::$frequency_in_hours . ' hour(s)',
		);

		return $schedules;
	}

	/**
	 * Set up the initial cron
	 *
	 * @since 1.4.0
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
			&& ! wp_next_scheduled( static::CRON_NAME )
		) {
			wp_schedule_event( time(), 'wp_rest_cache_trash', static::CRON_NAME );
			do_action( 'wrc_after_schedule_trash_cron', $primary_blog, $current_blog );
		}
	}

	/**
	 * Check the cache table for rows that need updated during our cron.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	static function check_cache_for_trash() {
		$delete_older_than = (int) apply_filters( 'wrc_trash_older_than', static::$delete_older_than );

		// The cron shouldn't even run if the value is set to zero but we're going to be sure here
		if ( 0 == $delete_older_than ) {
			return;
		}

		// Get the exact date we need to check `rest_last_requested` against
		$days_in_seconds = $delete_older_than * DAY_IN_SECONDS;
		$delete_older_than = date( 'Y-m-d', time() - $days_in_seconds );
		$optimize = false;

		/**
		 * Search our custom DB table for cached items whose "rest_last_requested"
		 * date is older than the amount of days set in $delete_older_than.
		 */
		global $wpdb;
		$query   = 'DELETE FROM ' . REST_CACHE_TABLE . ' WHERE rest_last_requested < "' . $delete_older_than . '" LIMIT ' . static::$query_limit;
		$results = $wpdb->query( $query );

		// Only run table optimize if there were deletions.
		if ( $results > 0 ) {
			$optimize = true;
		}

		/**
		 * Run a while loop based on the above query -- we need to continue to run
		 * the DELETE until the result is zero (meaning there's nothing left to delete).
		 */
		while ( $results > 0 ) {
			$results = $wpdb->query( $query );
		}

		// Run a table optimization to help tidy things up.
		$optimize = apply_filters( 'wrc_optimize_table_on_trash_collect', $optimize );
		if ( true == $optimize ) {
			$wpdb->query( 'OPTIMIZE TABLE `' . REST_CACHE_TABLE . '`');
		}

		return;
	}
}
