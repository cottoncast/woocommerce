<?php
/**
* Plugin Name: Cottoncast Products
* Plugin URI: https://cottoncast.com/integrations
* Description: Cottoncast. Thank you for joining us on our journey to become the best, not the biggest, Print on Demand company.
* Version: 1.2.4
* Author: Cottoncast
* Author URI: https://cottoncast.com/
* Developer: Chris Luttikhuis
* Developer URI: https://cottoncast.com/
*
* WC requires at least: 2.2
* WC tested up to: 3.8
*
* Copyright: © 2019 Cottoncast.
*/

if ( ! defined( 'ABSPATH' ) ) {
exit; // Exit if accessed directly
}

define('COTTONCAST_PLUGIN_PATH', __FILE__);
define('COTTONCAST_PLUGIN_VERSION', '1.2.4');

	/**
	 * Check if WooCommerce is active
	 **/
	if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

		// Installation and configuration
		include( 'installation.php' );

		// Category mapping
		include( 'category/cronjob.php' );

		include( 'category/condition.php' );
		include( 'category/category.php' );
		include( 'category/agegroup.php' );
		include( 'category/tags.php' );

		// Sending orders to the Cottoncast API
		include( 'order/cronjob.php' );

		// Receiving product updates
		include( 'product/webhook.php' );

		// Processing product updates in the background
		include( 'product/cronjob.php' );

		// The config endpoint. Used for updating settings directly from Studio
		include('config/endpoint.php');

		// The status endpoint. Directly called from Studio to gather information about the channel
		include('status/endpoint.php');

	}


