<?php
/**
 * Plugin Name:     Project Target Connector
 * Plugin URI:
 * Description:     This is a plugin which connects your store with your Project Target application.
 * Version:         1.0.1
 * Author:          MSG-GROUP
 * Author URI:      https://msg-group.net/
 * Developer:       MSG Group
 * License:         GNU General Public License v3.0
 * License URI:     http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:     project-target-connector
 * Domain Path:     /languages
*/

// Execution is only allowed as a part of the core system
defined( 'ABSPATH' ) or die( 'Direct execution is not allowed!' );


// Helper functions

function msgpt_remote_request( $endpoint, $args = array() ) {
	// Generate url & prepare headers
	$subdomain = get_option( 'msgpt_subdomain_name', '' );
	$base_url = str_replace( '{SUBDOMAIN}', $subdomain, 'https://{SUBDOMAIN}.projecttarget.net' );
	$url = $base_url . '/api' . $endpoint;
	$args = array_merge( $args, array( 'headers' => array(
		'Authorization' => 'Bearer ' . get_option( 'msgpt_access_token', '' ),
		'Content-Type' => 'application/json;charset=utf-8',
	) ) );
	if( array_key_exists( 'body', $args ) )
		$args['body'] = json_encode( $args['body'] );

	// Try to send request with current access token
	$response = wp_remote_request( $url, $args );
	$http_code = wp_remote_retrieve_response_code( $response );
	if ( 200 <= $http_code && $http_code <= 299 ) return $response;

	// If token is expired, then generate new token and try to send new request
	if ( $http_code == 401 ) {
		// Generate new token
		$new_token_response = wp_remote_request( $base_url . '/o/token/', array(
			'headers' => array( 'Content-Type' => 'application/json;charset=utf-8' ),
			'method' => 'POST',
			'body' => json_encode( array(
				"grant_type" => "refresh_token",
				"client_id" => get_option( 'msgpt_client_id', '' ),
				"refresh_token" => get_option( 'msgpt_refresh_token', '' ),
			) ),
		) );
		$new_token_http_code = wp_remote_retrieve_response_code( $new_token_response );
		$new_token_body = wp_remote_retrieve_body( $new_token_response );
		if ( 200 <= $new_token_http_code && $new_token_http_code <= 299 ) {
			$json_body = json_decode( $new_token_body );
			update_option( 'msgpt_access_token', $json_body->access_token );
			update_option( 'msgpt_refresh_token', $json_body->refresh_token );
		} else
			die( 'Response with unknown http status code ' . $new_token_http_code . ' during process of refreshing token' );

		// Send request again with new token
		$args['headers']['Authorization'] = 'Bearer ' . get_option( 'msgpt_access_token', '' );
		$new_response = wp_remote_request( $url, $args );
		$new_http_code = wp_remote_retrieve_response_code( $new_response );
		if ( 200 <= $new_http_code && $new_http_code <= 299 )
			return $new_response;
		else
			die( 'Response with unknown http status code ' . $new_http_code . ' after resending request with new token' );
	}

	die( 'Response with unknown http status code ' . $http_code );
}

function msgpt_new_customer( $billing_info ) {
	// Return zero if email not passed in billing information
	if( ! $billing_info['email'] ) return null;

	// Check if customer is already created in PT from this billing information
	$endpoint = '/customers/customer/?email=' . $billing_info['email'];
	$response = msgpt_remote_request( $endpoint, array( 'method' => 'GET' ) );
	$body = json_decode( wp_remote_retrieve_body( $response ) );
	if( $body ) return $body[0]->id;

	// Create new Customer in PT
	$data = array(
		'first_name' => $billing_info['first_name'],
		'last_name' => $billing_info['last_name'],
		'email' => $billing_info['email'],
		'phone1' => $billing_info['phone'],
		'address' => $billing_info['address_1'],
	);
	$new_response = msgpt_remote_request( '/customers/customer/', array( 'method' => 'POST', 'body' => $data ) );
	$customer = json_decode( wp_remote_retrieve_body( $new_response ) );

	// Return PT customer id
	return $customer->id;
}

function msgpt_new_product( $item ) {
	// Check if product is already created in PT from this item
	$pt_product_id = get_post_meta( $item->get_id(), '_msgpt_product_id', true );
	if( $pt_product_id ) return $pt_product_id;

	// Create new Product in PT
	$item_name = $item->get_name();
	$data = array( 'name' => $item_name, 'product_group' => 2 );
	$response = msgpt_remote_request( '/products/product/', array( 'method' => 'POST', 'body' => $data ) );

	// Set PT product_id metadata for this item
	$created_product = json_decode( wp_remote_retrieve_body( $response ) );
	$item->update_meta_data( '_msgpt_product_id', $created_product->id );
	$item->save();

	// Return PT product id
	return $created_product->id;
}


// PT Settings
function msgpt_connector_settings( $settings ) {
    $updated_settings = array();
    $new_settings = array(
        array(
            'id'    => 'project_target_connector_settings',
            'type'  => 'title',
            'title' => __( 'Project Target Connector Settings', 'project-target-connector' ),
            'desc' => __( 'Fill these required fields in order to connect your Project Target instance.', 'project-target-connector' ),
        ),
        array(
            'type'      => 'text',
            'name'      => __( 'Subdomain Name', 'project-target-connector' ),
            'id'        => 'msgpt_subdomain_name',
            'desc'      => __( 'Place your Subdomain Name provided by Project Target instance.', 'project-target-connector' ),
            'desc_tip'  =>  true,
            'css'       => 'width: 200px;',
        ),
        array(
            'type'      => 'text',
            'name'      => __( 'Client Id', 'project-target-connector' ),
            'id'        => 'msgpt_client_id',
            'desc'      => __( 'Place your Client Id provided by Project Target instance.', 'project-target-connector' ),
            'desc_tip'  =>  true,
        ),
        array(
            'type'      => 'text',
            'name'      => __( 'Access Token', 'project-target-connector' ),
            'id'        => 'msgpt_access_token',
            'desc'      => __( 'Place your Access Token provided by Project Target instance.', 'project-target-connector' ),
            'desc_tip'  =>  true,
        ),
        array(
            'type'      => 'text',
            'name'      => __( 'Refresh Token', 'project-target-connector' ),
            'id'        => 'msgpt_refresh_token',
            'desc'      => __( 'Place your Refresh Token provided by Project Target instance.', 'project-target-connector' ),
            'desc_tip'  =>  true,
        ),
        array(
            'type'      => 'text',
            'name'      => __( 'Date Format', 'project-target-connector' ),
            'id'        => 'msgpt_date_format',
            'desc'      => __( 'Place your Date Format provided by Project Target instance.', 'project-target-connector' ),
            'desc_tip'  =>  true,
            'css'       => 'width: 200px;',
        ),
        array(
            'type'  => 'sectionend',
            'id'    => 'project_target_connector_settings',
        ),
    );

    // Loop through settings to add them to the update ones and insert the new ones
    foreach ( $settings as $setting ) {
        $updated_settings[] = $setting;

        if ( isset( $setting['id'] ) && 'pricing_options' == $setting['id'] &&
            isset( $setting['type'] ) && 'sectionend' == $setting['type'] ) {

            $updated_settings = array_merge( $updated_settings, $new_settings );
        }
    }
    return $updated_settings;
}


// Create|Update|Delete Order functions

function msgpt_new_sale( $order_id ) {
	// Check if sale is already created in PT from this order
	$pt_sale_id = get_post_meta( $order_id, '_msgpt_sale_id', true );
    if( $pt_sale_id ) return $pt_sale_id;

    // Get order object and data
    $order = wc_get_order( $order_id );
    $order_data = $order->get_data();

	// Prepare post data
	$pt_customer_id = msgpt_new_customer( $order_data['billing'] );
	$date_format = get_option( 'msgpt_date_format' );
	$sale_date = date( $date_format );
	$date_created = $order->get_date_created();
	if( $date_created ) $sale_date = $date_created->date( $date_format );

	$data = array(
        'category' => 'sale',
        'sale_type' => 'standard',
        'sale_status' => $order_data['status'],
        'sale_date' => $sale_date,
        'quantity' => 0,
        'value' => 0,
        'currency' => $order_data['currency'],
        'customer' => $pt_customer_id,
        'users' => array(
	        array( 'user' => 2, 'user_sale_type' => 'sales_rep', 'comment' => '' ),
        ),
    );

    // Create new Sale in PT
    $response = msgpt_remote_request( '/sales/sale/', array( 'method' => 'POST', 'body' => $data ) );

	// Set PT sale_id metadata for this order
	$sale = json_decode( wp_remote_retrieve_body( $response ) );
	$order->update_meta_data( '_msgpt_sale_id', $sale->id );
    $order->save();

    // Return PT sale id
    return $sale->id;
}

function msgpt_update_sale( $order_id ) {
	// Get order object and data
	$order = wc_get_order( $order_id );
	$order_data = $order->get_data();

	// Skip drafts
	if( strpos( $order_data['status'], 'draft' ) !== false ) return;

	// Prepare post data
	$pt_sale_id = msgpt_new_sale( $order_id );
	$pt_customer_id = msgpt_new_customer( $order_data['billing'] );
	$date_format = get_option( 'msgpt_date_format' );
	$sale_date = date( $date_format );
	$date_created = $order->get_date_created();
	if( $date_created ) $sale_date = $date_created->date( $date_format );

	$data = array(
		'sale_status' => $order_data['status'],
		'sale_date' => $sale_date,
		'currency' => $order_data['currency'],
		'customer' => $pt_customer_id,
	);

	// Update Sale in PT
	$endpoint = '/sales/sale/' . $pt_sale_id . '/';
	msgpt_remote_request( $endpoint, array( 'method' => 'PATCH', 'body' => $data ) );
}

function msgpt_delete_sale( $order_id ) {
	// Check if post type is woocommerce order
	global $post_type;
	if( $post_type !== 'shop_order' ) return;

	// Delete Sale in PT
	$pt_sale_id = msgpt_new_sale( $order_id );
	$endpoint = '/sales/sale/' . $pt_sale_id . '/';
	msgpt_remote_request( $endpoint, array( 'method' => 'DELETE' ) );
}


// Create|Update|Delete Order Items functions

function msgpt_new_sale_product( $order_item_id, $order_item, $order_id ) {
	if( ! $order_item instanceof WC_Order_Item_Product ) return;

	// Prepare data
	$item = $order_item->get_product();
	$pt_sale_id = msgpt_new_sale( $order_id );
	$pt_product_id = msgpt_new_product( $item );
	$quantity = $order_item->get_quantity();
	$total = $order_item->get_total();

	// Create new sale-product relation
	$data = array( 'sale' => $pt_sale_id, 'product' => $pt_product_id, 'quantity' => $quantity, 'value' => $total );
	msgpt_remote_request( '/sales/sale-detail/', array( 'method' => 'POST', 'body' => $data ) );
}

function msgpt_update_sale_product( $order_item_id, $order_item, $order_id ) {
	if( ! $order_item instanceof WC_Order_Item_Product ) return;

	// Prepare data
	$item = $order_item->get_product();
	$quantity = $order_item->get_quantity();
	$total = $order_item->get_total();
	$pt_sale_id = msgpt_new_sale( $order_id );
	$pt_product_id = msgpt_new_product( $item );

	// Get sale-product relation in PT
	$endpoint = '/sales/sale-detail/?sale=' . $pt_sale_id . '&product=' . $pt_product_id;
	$response = msgpt_remote_request( $endpoint, array( 'method' => 'GET' ) );
	$sale_product = json_decode( wp_remote_retrieve_body( $response ) );

	// Update sale-product relation
	if( $sale_product ) {
		$endpoint = '/sales/sale-detail/' . $sale_product[0]->id . '/';
		$data = array( 'quantity' => $quantity, 'value' => $total );
		msgpt_remote_request( $endpoint, array( 'method' => 'PATCH', 'body' => $data ) );
	}
}

function msgpt_delete_sale_product( $order_item_id ) {
	// Prepare data
	$order_item = new WC_Order_Item_Product( $order_item_id );
	$order_id = $order_item->get_order_id();
	$item = $order_item->get_product();
	$quantity = $order_item->get_quantity();
	$total = $order_item->get_total();
	$pt_sale_id = msgpt_new_sale( $order_id );
	$pt_product_id = msgpt_new_product( $item );

	// Get sale-product relation in PT
	$endpoint = '/sales/sale-detail/';
	$filters = '?sale=' . $pt_sale_id . '&product=' . $pt_product_id . '&quantity=' . $quantity . '&value=' . $total;
	$response = msgpt_remote_request( $endpoint . $filters, array( 'method' => 'GET' ) );
	$sale_product = json_decode( wp_remote_retrieve_body( $response ) );

	// Delete sale-product relation
	if( $sale_product ) {
		$endpoint = '/sales/sale-detail/' . $sale_product[0]->id . '/';
		msgpt_remote_request( $endpoint, array( 'method' => 'DELETE' ) );
	}
}


// Check if WooCommerce plugin is activated
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    // Add Project Target Connection Settings section on WooCommerce->Settings->General tab
    add_filter( 'woocommerce_general_settings', 'msgpt_connector_settings' );

    // Check if subdomain and tokens are set (provided by PT Team)
    $subdomain = get_option( 'msgpt_subdomain_name' );
    $token = get_option( 'msgpt_access_token' );

    if ( $subdomain && $token ) {

        // Register here plugin actions and filters

	    // Orders
	    add_action( 'woocommerce_new_order', 'msgpt_new_sale' );
		add_action( 'woocommerce_update_order', 'msgpt_update_sale' );
	    add_action( 'before_delete_post', 'msgpt_delete_sale' );

	    // Order Items
		add_action( 'woocommerce_new_order_item', 'msgpt_new_sale_product', 10, 3 );
		add_action( 'woocommerce_update_order_item', 'msgpt_update_sale_product', 10, 3 );
		add_action( 'woocommerce_before_delete_order_item', 'msgpt_delete_sale_product' );

    }
}
