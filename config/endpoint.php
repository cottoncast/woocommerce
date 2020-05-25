<?php
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }

    /**
     * Creating a custom endpoint for receiving status updates for Cottoncast Orders
     */
    add_action( 'template_redirect', function() {
        if ($_SERVER['REQUEST_URI'] == '/cottoncast/config' || $_SERVER['REQUEST_URI'] == '/cottoncast/config/' ) {
            echo json_encode(cottoncast_api_config_response());
            die;
        }
    }, 1 );


    function cottoncast_api_config_response()
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

        $updateCount = 0;
        foreach ($payload as $key => $value)
        {
            if (!in_array($key, cottoncast_api_allowed_settings()))
            {
                $response->status = "error";
                $response->message = "Invalid setting $key";
                status_header(400); // Internal server error
            } else {
                $option = get_option( 'cottoncast_settings' );
                $option[str_replace('.','_',$key)] = $value;
                update_option('cottoncast_settings', $option);
                $updateCount++;
            }
        }

        // queue the job
        if ($updateCount)
        {
            status_header(200); // OK
            $response->status = 'ok';
        } else {
            $response->status = "error";
            $response->message = "MySQL error";
            status_header(500); // Internal server error
        }

        return $response;
    }

    function cottoncast_api_allowed_settings()
    {
        $settings = [
            'product.title.update',
            'product.description.update',
            'product.price.update',
            'product.images.update',
            'product.tags.update',
            'product.status.publish'
        ];
        return $settings;
    }

    function cottoncast_api_settings_map($key, $value)
    {
        if ($key == 'product.status.publish' && $value)
        {
            $value = 'publish';
        } else {
            $value = 'draft';
        }


        return $value;
    }