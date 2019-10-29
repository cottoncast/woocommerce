<?php
/**
* Plugin Name: Cottoncast Products
* Plugin URI: https://cottoncast.com/integrations
* Description: Cottoncast. Thank you for joining us on our journey to become the best, not the biggest, Print on Demand company.
* Version: 1.1.0
* Author: Cottoncast
* Author URI: https://cottoncast.com/
* Developer: Chris Luttikhuis
* Developer URI: https://cottoncast.com/
*
* WC requires at least: 2.2
* WC tested up to: 2.3
*
* Copyright: © 2019 Cottoncast.
*/

if ( ! defined( 'ABSPATH' ) ) {
exit; // Exit if accessed directly
}

define('COTTONCAST_PLUGIN_PATH', __FILE__);

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

	}


