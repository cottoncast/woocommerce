<?php
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}

	function cottoncast_fetch_config($checkVersion = null)
	{
	    $integration_config = json_decode(get_option('cottoncast_integration_config'));

	    if ($checkVersion && $integration_config && !empty($integration_config->response->version)) {
            if ($integration_config->response->version == $checkVersion) {
                return false;
            }
        }


		$config_url = get_option('cottoncast_settings')['cottoncast_api_settings_field_config_endpoint'];
		$auth_string = get_option('cottoncast_settings')['cottoncast_api_settings_field_username'].":".get_option('cottoncast_settings')['cottoncast_api_settings_field_secret'];

		if (function_exists('curl_version'))
		{
			$ch = curl_init();
			curl_setopt ($ch, CURLOPT_URL, $config_url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_USERPWD, $auth_string);
			$body = curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
		} else if (ini_get('allow_url_fopen')) {
			$context = stream_context_create([
				"http" => [
					"header" => "Authorization: Basic ". base64_encode($auth_string)
				]
			]);
			$body = file_get_contents($config_url, false, $context);
			preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0], $match);
			$code = $match[1];
		} else {
			throw new Exception("Unable to fetch the integration configuration");
		}

		if ($code == 200 && json_encode($body))
		{
			update_option('cottoncast_integration_config', $body);
		}
        return true;
	}


	function cottoncast_products_install () {
		global $wpdb;

		$table_name = $wpdb->prefix . "cottoncast_products_queue";

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name  (
  job_id int(11) NOT NULL AUTO_INCREMENT,
  status smallint(3) NOT NULL,
  payload text not null,
  timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
  PRIMARY KEY  (job_id)
) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		add_option( "cottoncast_products_db_version", "1.0" );

		cottoncast_fetch_config();

	}

	register_activation_hook( COTTONCAST_PLUGIN_PATH, 'cottoncast_products_install' );

	/**
	 * Add link to settings page
	 */
	add_filter( 'plugin_action_links_' . plugin_basename(COTTONCAST_PLUGIN_PATH), 'my_plugin_action_links' );
	function my_plugin_action_links( $links ) {
		$links[] = '<a href="'. esc_url( get_admin_url(null, 'options-general.php?page=cottoncast-settings-page') ) .'">Settings</a>';
		return $links;
	}


	/**
	 * Create settings page
	 */
	add_action( 'admin_menu', 'cottoncast_add_options_page' , 1 );
	add_action( 'admin_init', 'cottoncast_settings_init' );

	function cottoncast_add_options_page() {
		add_options_page( 'Cottoncast', 'Cottoncast', 'manage_options', 'cottoncast-settings-page', 'cottoncast_settings_page' );
	}


	function cottoncast_settings_init()
	{
		register_setting( 'cottoncastPlugin', 'cottoncast_settings' );

		/* Connect section */
		add_settings_section(
			'cottoncast_api_section',
			__( 'Connect', 'wordpress' ),
			'cottoncast_sections_callback',
			'cottoncastPlugin'
		);

        add_settings_field(
            'cottoncast_api_settings_field_username',
            __( 'Username', 'wordpress' ),
            'cottoncast_api_settings_field_username_render',
            'cottoncastPlugin',
            'cottoncast_api_section'
        );

        add_settings_field(
            'cottoncast_api_settings_field_secret',
            __( 'Secret', 'wordpress' ),
            'cottoncast_api_settings_field_secret_render',
            'cottoncastPlugin',
            'cottoncast_api_section'
        );

        /* API Section */
		add_settings_section(
			'cottoncast_api_endpoint_section',
			__( 'API Endpoints', 'wordpress' ),
			'cottoncast_endpoint_sections_callback',
			'cottoncastPlugin'
		);

        add_settings_field(
            'cottoncast_api_settings_field_order_endpoint',
            __( 'Order Endpoint', 'wordpress' ),
            'cottoncast_api_settings_order_endpoint_render',
            'cottoncastPlugin',
            'cottoncast_api_endpoint_section'
        );

        add_settings_field(
            'cottoncast_api_settings_field_config_endpoint',
            __( 'Config Endpoint', 'wordpress' ),
            'cottoncast_api_settings_config_endpoint_render',
            'cottoncastPlugin',
            'cottoncast_api_endpoint_section'
        );


        /* Products Section */
        add_settings_section(
            'cottoncast_products_section',
            __( 'Products', 'wordpress' ),
            'cottoncast_product_sections_callback',
            'cottoncastPlugin'
        );

        add_settings_field(
            'cottoncast_product_settings_field_product_status',
            __( 'Product Status for new products', 'wordpress' ),
            'cottoncast_product_settings_field_product_status_render',
            'cottoncastPlugin',
            'cottoncast_products_section'
        );




	}

    /*
     * Field rendering
     */

	function cottoncast_api_settings_field_username_render(  ) {
		$options = get_option( 'cottoncast_settings' );
		?>
		<input type='text' name='cottoncast_settings[cottoncast_api_settings_field_username]' value='<?php echo $options['cottoncast_api_settings_field_username']; ?>'>
		<?php
	}

	function cottoncast_api_settings_field_secret_render(  )
	{
		$options = get_option( 'cottoncast_settings' );
		?>
		<input type='text' name='cottoncast_settings[cottoncast_api_settings_field_secret]' value='<?php echo $options['cottoncast_api_settings_field_secret']; ?>'>

		<?php
	}

	function cottoncast_api_settings_config_endpoint_default()
	{
		return 'https://api.cottoncast.com/integrations/config';
	}

	function cottoncast_api_settings_order_endpoint_default()
	{
		return 'https://api.cottoncast.com/order';
	}

	function cottoncast_api_settings_order_endpoint_render(  )
	{
		$options = get_option( 'cottoncast_settings' );
		?>
		<input type='text' name='cottoncast_settings[cottoncast_api_settings_field_order_endpoint]' value='<?php echo $options['cottoncast_api_settings_field_order_endpoint'] ? $options['cottoncast_api_settings_field_order_endpoint'] : cottoncast_api_settings_order_endpoint_default() ; ?>'>

		<?php
	}

	function cottoncast_api_settings_config_endpoint_render(  )
	{
		$options = get_option( 'cottoncast_settings' );
		?>
        <input type='text' name='cottoncast_settings[cottoncast_api_settings_field_config_endpoint]' value='<?php echo $options['cottoncast_api_settings_field_config_endpoint'] ? $options['cottoncast_api_settings_field_config_endpoint'] : cottoncast_api_settings_config_endpoint_default() ; ?>'>

		<?php
	}


    function cottoncast_product_settings_field_product_status_render(  )
    {
        $options = get_option( 'cottoncast_settings' );
        ?>
        <input type='radio' name='cottoncast_settings[cottoncast_product_settings_field_product_status]' value='draft' <?php echo $options['cottoncast_product_settings_field_product_status'] == 'draft' ? 'checked="checked"' : '' ?>>
        <label>Draft</label>
        <input type='radio' name='cottoncast_settings[cottoncast_product_settings_field_product_status]' value='publish' <?php echo $options['cottoncast_product_settings_field_product_status'] != 'draft' ? 'checked="checked"' : '' ?>>
        <label>Published</label>
        <?php
    }


	/*
	 * Section Callbacks
	 */

	function cottoncast_sections_callback(  ) {
		echo __( 'Manage your connection with CottonCast.', 'wordpress' );
	}

	function cottoncast_endpoint_sections_callback(  ) {
		echo __( 'By default our production endpoints.', 'wordpress' );
	}

    function cottoncast_product_sections_callback(  ) {
        echo __( 'Customize our product integration', 'wordpress' );
    }

	function cottoncast_settings_page(  ) {
		?>
		<form action='options.php' method='post'>

			<h1>Cottoncast Settings</h1>

			<?php
				settings_fields( 'cottoncastPlugin' );
				do_settings_sections( 'cottoncastPlugin' );
				submit_button();
			?>

		</form>
		<?php
	}

	/**
	 * Show custom attribute to products to identify it's a cottoncast product in the backend
	 */
	add_action( 'woocommerce_product_options_sku', 'cc_orders_add_fulfillment_field' );
	function cc_orders_add_fulfillment_field() {
		global $post;

		$is_cottoncast_product = get_post_meta(
			$post->ID,
			'_is_cottoncast_product',
			true
		);

		$args = array(
			'label' => 'Cottoncast Product', // Text in Label
			'class' => '',
			'style' => '',
			'wrapper_class' => '',
			'value' => $is_cottoncast_product ? $is_cottoncast_product : 'no',
			'id' => '_is_cottoncast_product', // required
			'name' => '_is_cottoncast_product',
			'desc_tip' => '',
			'custom_attributes' => '', // array of attributes
			'description' => 'This product will be fulfilled by Cottoncast'
		);
		woocommerce_wp_checkbox( $args );
	}


	/**
	 * Save custom attribute _is_cottoncast_product to post_meta
	 */
	add_action( 'woocommerce_process_product_meta', 'cc_orders_save_fulfillment_field' );
	function cc_orders_save_fulfillment_field( $post_id ) {

		$custom_field_value = isset( $_POST['_is_cottoncast_product'] ) ? 'yes' : 'no';

		$product = wc_get_product( $post_id );
		$product->update_meta_data( '_is_cottoncast_product', $custom_field_value );
		$product->save();
	}


	/*
	 * Remove obsolete cronjobs
	 */
    add_action("init", "cottoncast_cron_job_delete");

	function cottoncast_cron_job_delete()
    {
        wp_clear_scheduled_hook('cottoncast_cronjob_config');
    }