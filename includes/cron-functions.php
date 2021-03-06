<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule social media posts with wp_schedule_single_event
 * @param  id $post_id
 * @param  object $post
 * @return void
 */
function ppp_schedule_share( $post_id, $post ) {
	global $ppp_options;
	$allowed_post_types = isset( $ppp_options['post_types'] ) ? $ppp_options['post_types'] : array();
	$allowed_post_types = apply_filters( 'ppp_schedule_share_post_types', $allowed_post_types );

	if ( !isset( $_POST['post_status'] ) || !array_key_exists( $post->post_type, $allowed_post_types ) ) {
		return;
	}

	ppp_remove_scheduled_shares( $post_id );

	if ( ( $_POST['post_status'] == 'publish' && $_POST['original_post_status'] == 'publish' ) ||
		 ( $_POST['post_status'] == 'future' && $_POST['original_post_status'] == 'future' ) ) {
		// Be sure to clear any currently scheduled tweets so we aren't creating multiple instances
		// This will stop something from moving between draft and post and continuing to schedule tweets
		ppp_remove_scheduled_shares( $post_id );
	}

	if( ( $_POST['post_status'] == 'publish' && $_POST['original_post_status'] != 'publish' ) || // From anything to published
		( $_POST['post_status'] == 'future' && $_POST['original_post_status'] == 'future' ) || // Updating a future post
		( $_POST['post_status'] == 'publish' && $_POST['original_post_status'] == 'publish' ) ) { // Updating an already published post
		global $ppp_options, $ppp_social_settings;

		$timestamps = ppp_get_timestamps( $post_id );

		foreach ( $timestamps as $timestamp => $name ) {
			wp_schedule_single_event( $timestamp, 'ppp_share_post_event', array( $post_id, $name ) );
		}
	}
}
// This action is for the cron event. It triggers ppp_share_post when the crons run
add_action( 'ppp_share_post_event', 'ppp_share_post', 10, 2 );

/**
 * Given a post ID remove it's scheduled shares
 * @param  int $post_id The Post ID to remove shares for
 * @return void
 */
function ppp_remove_scheduled_shares( $post_id ) {
	do_action( 'ppp_pre_remove_scheduled_shares', $post_id );

	$current_shares = get_post_meta( $post_id, '_ppp_tweets', true );

	foreach ( $current_shares as $key => $share ) {
			$name = 'sharedate_' . $key . '_' . $post_id;
			wp_clear_scheduled_hook( 'ppp_share_post_event', array( $post_id, $name ) );

	}

	do_action( 'ppp_post_remove_scheduled_shares', $post_id );
}

/**
 * Given an array of arguments, remove a share
 * @param  array $args Array containing 2 values $post_id and $name
 * @return void
 */
function ppp_remove_scheduled_share( $args ) {
	wp_clear_scheduled_hook( 'ppp_share_post_event', $args );
	return;
}

/**
 * Get all the crons hooked into 'ppp_share_post_event'
 * @return array All crons scheduled for Post Promoter Pro
 */
function ppp_get_shceduled_crons() {
	$all_crons = get_option( 'cron' );
	$ppp_crons = array();

	foreach ( $all_crons as $timestamp => $cron ) {
		if ( ! isset( $cron['ppp_share_post_event'] ) ) {
			continue;
		}

		foreach ( $cron['ppp_share_post_event'] as $key => $single_event ) {
			$single_event['timestamp'] = $timestamp;
			$ppp_crons[$key] = $single_event;
		}

	}

	return apply_filters( 'ppp_get_scheduled_crons', $ppp_crons );
}

/**
 * Given a time, see if there are any tweets scheduled within the range of the within
 *
 * @since  2.2.3
 * @param  int $time   The timestamp to check for
 * @param  int $within The number of seconds to check, before and after a given time
 * @return bool        If there are any tweets scheduled within this timeframe
 *
 */
function ppp_has_cron_within( $time = 0, $within = 0 ) {
	if ( empty( $time ) ) {
		$time = current_time( 'timestamp' );
	}

	if ( empty( $within ) ) {
		$within = ppp_get_default_conflict_window();
	}

	$crons = ppp_get_shceduled_crons();

	if ( empty( $crons ) ) {
		return false;
	}

	$scheduled_times = wp_list_pluck( $crons, 'timestamp' );

	$found_time = false;
	foreach ( $scheduled_times as $key => $scheduled_time ) {
		$found_time = ppp_is_time_within( $scheduled_time, $time, $within );
		if ( $found_time ) {
			break;
		}
	}

	return $found_time;
}

/**
 * Check if $time is within the +/- of $target_time
 *
 * @since  2.2.3
 * @param  integer $time        The Time to check
 * @param  integer $target_time The Target time
 * @param  integer $within      The +/- in seconds
 * @return bool                 If the time is within the range of the target_time
 *
 */
function ppp_is_time_within( $time = 0, $target_time = 0, $within = 0 ) {
	$min = $target_time - $within;
	$max = $target_time + $within;

	return ( ( $time >= $min ) && ( $time <= $max ) );
}

/**
 * The default +/- on when we should warn about conflicting tweets
 * @return int The +/- to warn on
 */
function ppp_get_default_conflict_window() {
	return apply_filters( 'ppp_default_conflict_window', HOUR_IN_SECONDS / 2 );
}
