<?php
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    /**
     * Creating a custom endpoint for receiving status updates for Cottoncast Orders
     */
    add_action( 'template_redirect', function() {
        if ($_SERVER['REQUEST_URI'] == '/cottoncast/status' || $_SERVER['REQUEST_URI'] == '/cottoncast/status/' ) {
            echo json_encode(cottoncast_api_status_response());
            die;
        }
    }, 1 );


    function cottoncast_api_status_response()
    {
        $response = new \stdClass;
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] != 'GET')
        {
            $response->status = "error";
            $response->message = "Invalid request method";
            status_header(405); // Method not allowed
            return $response;
        }


        if (!cottoncast_basic_verify_origin_request())
        {
            $response->status = "error";
            $response->message = "Unauthorized access";
            status_header(401); // Unauthorized
            return $response;
        }

        $settings = get_option('cottoncast_settings');

        $response->status = 'ok';
        $response->version = COTTONCAST_PLUGIN_VERSION;
        $response->platform = 'woocommerce';
        $response->settings = new \stdClass;
        $response->settings = [
            'product.title.update' => isset($settings['product_title_update']) ? $settings['product_title_update']: true,
            'product.description.update' => isset($settings['product_description_update']) ? $settings['product_description_update'] : true,
            'product.price.update' => isset($settings['product_price_update']) ? $settings['product_price_update'] : true,
            'product.images.update' => isset($settings['product_images_update']) ? $settings['product_images_update'] : true,
            'product.tags.update' => isset($settings['product_tags_update']) ? $settings['product_tags_update'] : true,
            'product.status.publish' => isset($settings['product_status_publish']) ? $settings['product_status_publish'] : true
        ];

        $response->php = new \stdClass;
        $response->php->version = phpversion();
        $response->php->memory_limit = ini_get('memory_limit');
        $response->php->max_exec_time = ini_get('max_execution_time');
        $response->php->fopen_url_allow = ini_get('allow_url_fopen');
        $response->php->extensions = new \stdClass;
        $response->php->extensions->suhosin = extension_loaded('suhosin');
        $response->php->extensions->curl = function_exists('curl_version');

        $response->queue = [];

        global $wpdb;
        $results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cottoncast_products_queue  ORDER BY job_id DESC LIMIT 25", OBJECT );

        if ($results)
        {
            foreach ($results as $queue_item)
            {
                $item = new \stdClass;
                $item->ID = $queue_item->job_id;
                $payload = json_decode($queue_item->payload);
                $item->SKU = $payload->sku;
                $item->status = cottoncast_queue_status($queue_item->status);
                $item->date = $queue_item->timestamp;

                $response->queue[] = $item;
            }

        }
        $response->meta_version = md5(get_option('cottoncast_integration_config'));



        return $response;
    }