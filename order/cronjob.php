<?php
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}


    /**
     * Create a queue job once an order is paid if an order contains cottoncast products
     */
    add_action( 'woocommerce_order_status_processing', 'cc_orders_paid' );
    function cc_orders_paid( $order_id ){
        $order = wc_get_order( $order_id );

        // order is not paid. We are not touching those
        if (!$order->is_paid()) return;

        // order has been queued already. We are not gonna do that again.
        if ($order->get_meta('cottoncast_status')) return;

        // Check if there are any cottoncast product to be fulfilled
        $items = $order->get_items();

        $has_cottoncast_products = false;
        foreach ($items as $item)
        {
            $product = $item->get_product();
            $parent = false;
            if ($product->get_type() == 'variation')
            {
                $parent = wc_get_product($product->get_parent_id());
            }

            $meta = $product->get_meta_data();
            if ($product->get_meta('_is_cottoncast_product') == 'yes' || ($parent && $parent->get_meta('_is_cottoncast_product') == 'yes' ))
                $has_cottoncast_products = true;
        }

        if (!$has_cottoncast_products) return;

        // Yes. This order should be fulfilled (partially) by Cottoncast. Lets queue the order for processing.
        $order->add_meta_data('cottoncast_status', 1); // The order is queued.
        $order->add_order_note('The order has been queued for fulfillment at Cottoncast.');
        $order->save();

    }

    /**
     * A cronjob running hourly
     */
    add_action('init', 'cc_schedule_cronjob_orders');
    function cc_schedule_cronjob_orders() {

        if (! wp_next_scheduled ( 'cottoncast_cronjob_orders' )) {
            wp_schedule_event(time(), 'hourly', 'cottoncast_cronjob_orders');
        }
    }
    add_action ('cottoncast_cronjob_orders', 'cottoncast_process_queue');

    function cc_unschedule_cronjob_orders() {
        $timestamp = wp_next_scheduled ('cottoncast_cronjob_orders');
        wp_unschedule_event ($timestamp, 'cottoncast_cronjob_orders');
    }
    register_deactivation_hook (COTTONCAST_PLUGIN_PATH, 'cc_unschedule_cronjob_orders');


    /**
     * Processing orders
     *
     */
    function cottoncast_process_queue() {

        // Get all orders with status processing
        $orders = wc_get_orders(['status' => 'processing']);
        $x = 5;

        // filter for orders with cottoncast_status = 1
        foreach ($orders as $order)
        {
            if ($order->get_meta('cottoncast_status') == 1 )
            { // Queued for sending to Cottoncast API
                cottoncast_api_post_order($order);
            }

            if ($order->get_meta('cottoncast_status') == 4 )
            { // Status retry.
                $order->update_meta_data('cottoncast_status', 1); // Let's try again
                $retries = $order->get_meta('cottoncast_retries');
                if (!$retries) $retries = 0;
                $retries++;
                $order->update_meta_data('cottoncast_retries', $retries);
                $order->save();
            }
        }


    }


    function cottoncast_api_post_order($order)
    {
        // post the order
        $payload = new stdClass;
        $payload->store = get_option('cottoncast_settings')['cottoncast_api_settings_field_username'];
        $payload->order_ref = $order->get_id();

        $payload->billing = new stdClass;
        $payload->billing->fname = $order->get_billing_first_name();
        $payload->billing->name = $order->get_billing_last_name();
        $payload->billing->street = $order->get_billing_address_1() . ($order->get_billing_address_2() ? ' '.$order->get_billing_address_2() : '');
        $payload->billing->postcode = $order->get_billing_postcode();
        $payload->billing->city = $order->get_billing_city();
        $payload->billing->country = $order->get_billing_country();
        $payload->billing->phone = $order->get_billing_phone();
        $payload->billing->email = $order->get_billing_email();

        $payload->shipping = new stdClass;
        $payload->shipping->fname = $order->get_shipping_first_name();
        $payload->shipping->name = $order->get_shipping_last_name();
        $payload->shipping->street = $order->get_shipping_address_1() . ($order->get_shipping_address_2() ? ' '.$order->get_shipping_address_2() : '');
        $payload->shipping->postcode = $order->get_shipping_postcode();
        $payload->shipping->city = $order->get_shipping_city();
        $payload->shipping->country = $order->get_shipping_country();
        $payload->shipping->phone = $order->get_billing_phone();
        $payload->shipping->email = $order->get_billing_email();

        $payload->items = [];

        $items = $order->get_items();

        foreach ($items as $item)
        {
            $product = $item->get_product();
            $parent = false;
            if ($product->get_type() == 'variation')
                $parent = wc_get_product($product->get_parent_id());

            if ($product->get_meta('_is_cottoncast_product') == 'yes' || ($parent && $parent->get_meta('_is_cottoncast_product') == 'yes' ))
            {
                $cartItem = new stdClass;
                $cartItem->qty = $item->get_quantity();
                $cartItem->sku = $product->get_sku();
                $payload->items[] = $cartItem;
            }
        }

        $opt = [
            'body' => json_encode($payload),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( get_option('cottoncast_settings')['cottoncast_api_settings_field_username'] . ':' . get_option('cottoncast_settings')['cottoncast_api_settings_field_secret'] )
            ]
        ];

        $response = wp_remote_post(get_option('cottoncast_settings')['cottoncast_api_settings_field_order_endpoint'], $opt);

        if ($response && !empty($response['response']['code']) && $response['response']['code'] == 200 )
        {
            $api_response = json_decode($response['body']);
            $order->add_meta_data('cottoncast_order_id', $api_response->response->orderRef); // The order is queued.
            $order->update_meta_data('cottoncast_status', 2); // Success
            $order->add_order_note('The order is received by Cottoncast. It\'s order reference is '.$api_response->response->orderRef.'. ');
        } else {
            if (!empty($response['response']['code'])) {
                $api_response = json_decode($response['body']);
                if ($api_response)
                    $order->add_order_note('Cottoncast could not process the order: ' . $api_response->message);
            }
            $order->update_meta_data('cottoncast_status', 4); // Retry

        }
        $order->save();

        // if success set status = 2. If failed set status 4. Also set meta field cottoncast_retries.
        return $response;
    }


    /**
     * Creating a custom endpoint for receiving status updates for Cottoncast Orders
     */
    add_action( 'template_redirect', function() {
        if ($_SERVER['REQUEST_URI'] == '/cottoncast/orderstatus' || $_SERVER['REQUEST_URI'] == '/cottoncast/orderstatus/' ) {
            echo json_encode(cottoncast_api_orderstatus_response());
            exit;
        }
    } );


    function cottoncast_api_orderstatus_response()
    {
        $response = new stdClass;
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] != 'POST')
        {
            $response->status = "error";
            $response->message = "Invalid request method";
            status_header(405); // Method not allowed
            return $response;
        }

        $body = file_get_contents("php://input");

        $payload = json_decode($body);
        if (empty($payload)) {
            $response->status = "error";
            $response->message = "Invalid JSON";
            return $response;
        }


        if (!cottoncast_orders_verify_origin_request($body))
        {
            $response->status = "error";
            $response->message = "Unauthorized Access";
            status_header(401); // Unauthorized
            return $response;
        }

        $order = wc_get_order($payload->ref);

        if (!$order)
        {
            $response->status = "error";
            $response->message = "Order not found";
            status_header(400); // Bad Request
            return $response;
        }


        if ($payload->status->code == 'C')
        {
            $note = "Cottoncast has shipped the order.";
            $order->set_status('completed');

            if (!empty($payload->shipments))
            {
                foreach ($payload->shipments as $shipId => $shipment){
                    $id = $shipId + 1;
                    $note .= " You can track shipment #{$id} <a href=\"{$shipment->trackingurl}\">here</a>.";
                }
            }

            $order->add_order_note($note);
            $order->save();


        }

        $response->status = 'ok';
        status_header(200);

        return $response;
    }

    function cottoncast_orders_verify_origin_request($body)
    {
        $calculatedHmac = hash_hmac('sha256', $body,get_option('cottoncast_settings')['cottoncast_api_settings_field_secret']);

        if (empty($_SERVER['HTTP_COTTONCAST_AUTHENTICATION'])) return false;

        $header_hmac = $_SERVER['HTTP_COTTONCAST_AUTHENTICATION'];
        return $calculatedHmac === $header_hmac;
    }


