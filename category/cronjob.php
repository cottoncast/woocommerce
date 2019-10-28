<?php
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}

	/**
	 * A cronjob running daily
	 */
	add_action('init', 'cc_schedule_cronjob_config');
	function cc_schedule_cronjob_config() {

		if (! wp_next_scheduled ( 'cottoncast_cronjob_config' )) {
			wp_schedule_event(time(), 'daily', 'cottoncast_cronjob_config');
		}
	}
	add_action ('cottoncast_cronjob_config', 'cottoncast_fetch_config');

	function cc_unschedule_cronjob_config() {
		$timestamp = wp_next_scheduled ('cottoncast_cronjob_config');
		wp_unschedule_event ($timestamp, 'cottoncast_cronjob_config');
	}
	register_deactivation_hook (COTTONCAST_PLUGIN_PATH, 'cc_unschedule_cronjob_config');

