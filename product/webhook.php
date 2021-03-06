<?php
	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly
	}

	/**
	 * Creating a custom endpoint for receiving status updates for Cottoncast Orders
	 */
	add_action( 'template_redirect', function() {
		if ($_SERVER['REQUEST_URI'] == '/cottoncast/product' || $_SERVER['REQUEST_URI'] == '/cottoncast/product/' ) {
			echo json_encode(cottoncast_api_product_response());
			die;
		}
	}, 1 );


	function cottoncast_api_product_response()
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
			status_header(400); // Bad Request
			return $response;
		}


		if (!cottoncast_product_verify_origin_request($body))
		{
			$response->status = "error";
			$response->message = "Unauthorized access";
			status_header(401); // Unauthorized
			return $response;
		}

		global $wpdb;
		$wpdb->insert($wpdb->prefix . "cottoncast_products_queue",
			[
				'status' => 1,
				'payload' => $body,
				'timestamp' => date('Y-m-d H:i:s')
			]);
		// queue the job
		if ($wpdb->insert_id)
		{
			status_header(202); // Accepted for processing
			$response->status = 'ok';
		} else {
			$response->status = "error";
			$response->message = "MySQL error";
			status_header(500); // Internal server error
		}

		return $response;
	}

	function cottoncast_product_verify_origin_request($body)
	{
		$calculatedHmac = hash_hmac('sha256', $body,get_option('cottoncast_settings')['cottoncast_api_settings_field_secret']);

		if (empty($_SERVER['HTTP_COTTONCAST_AUTHENTICATION'])) return false;

		$header_hmac = $_SERVER['HTTP_COTTONCAST_AUTHENTICATION'];
		return $calculatedHmac === $header_hmac;
	}

    function cottoncast_basic_verify_origin_request()
    {

        if (isset($_SERVER['HTTP_AUTHORIZATION']))
        {
            $ha = base64_decode( substr($_SERVER['HTTP_AUTHORIZATION'],6) );
            list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $ha);
            unset($ha);
        }

        if (isset($_SERVER['HTTP_COTTONCAST_AUTHENTICATION']))
        {
            $ha = base64_decode(substr($_SERVER['HTTP_COTTONCAST_AUTHENTICATION'],6));
            list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $ha);
            unset($ha);
        }

        if (empty($_SERVER['PHP_AUTH_PW'])) return false;
        return get_option('cottoncast_settings')['cottoncast_api_settings_field_secret'] === $_SERVER['PHP_AUTH_PW'];
    }