<?php
/**
 * Gateway class file.
 *
 * @package Billmate_Checkout/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gateway class.
 */
class BCO_Gateway extends WC_Payment_Gateway {

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id                 = 'bco';
		$this->method_title       = __( 'Billmate Checkout', 'billmate-checkout-for-woocommerce' );
		$this->icon               = '';
		$this->method_description = __( 'Allows payments through ' . $this->method_title . '.', 'billmate-checkout-for-woocommerce' ); // phpcs:ignore

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables.
		$this->settings      = $this->get_option( 'woocommerce_bco_settings' );
		$this->enabled       = $this->get_option( 'enabled' );
		$this->title         = $this->get_option( 'title' );
		$this->description   = $this->get_option( 'description' );
		$this->debug         = $this->get_option( 'debug' );
		$this->testmode      = 'yes' === $this->get_option( 'testmode' );
		$this->checkout_flow = ( isset( $this->settings['checkout_flow'] ) ) ? $this->settings['checkout_flow'] : 'checkout';

		// Supports.
		$this->supports = apply_filters(
			'wc_bco_payments_supports',
			array( 'products' )
		);

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'billmate_thank_you' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_billmate_temp_order_id_to_order' ), 10, 3 );

		// Load scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ) );
	}

	/**
	 * Loads the needed scripts for Billmate_Checkout.
	 */
	public function load_scripts() {

		if ( 'yes' !== $this->enabled ) {
			return;
		}

		if ( ! is_checkout() ) {
			return;
		}

		if ( is_order_received_page() ) {
			return;
		}

		$src  = BILLMATE_CHECKOUT_URL;
		$src .= ( true === SCRIPT_DEBUG ? '/assets/js/bco-checkout.js' : '/assets/js/bco-checkout.min.js' );
		// Checkout script.
		wp_register_script(
			'bco-checkout',
			$src,
			array( 'jquery' ),
			BILLMATE_CHECKOUT_VERSION,
			true
		);

		$standard_woo_checkout_fields = array( 'billing_first_name', 'billing_last_name', 'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_city', 'billing_phone', 'billing_email', 'billing_state', 'billing_country', 'billing_company', 'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_address_2', 'shipping_postcode', 'shipping_city', 'shipping_state', 'shipping_country', 'shipping_company', 'terms', 'terms-field', 'account_username', 'account_password', '_wp_http_referer' );
		$bco_settings                 = get_option( 'woocommerce_bco_settings' );
		$checkout_flow                = ( isset( $bco_settings['checkout_flow'] ) ) ? $bco_settings['checkout_flow'] : 'checkout';
		$checkout_layout              = ( isset( $bco_settings['checkout_layout'] ) ) ? $bco_settings['checkout_layout'] : 'two_column_checkout';

		$params = array(
			'ajax_url'                             => admin_url( 'admin-ajax.php' ),
			'select_another_method_text'           => __( 'Select another payment method', 'billmate-checkout-for-woocommerce' ),
			'standard_woo_checkout_fields'         => $standard_woo_checkout_fields,
			'checkout_flow'                        => $checkout_flow,
			'checkout_layout'                      => $checkout_layout,
			'update_checkout_url'                  => WC_AJAX::get_endpoint( 'bco_wc_update_checkout' ),
			'update_checkout_nonce'                => wp_create_nonce( 'bco_wc_update_checkout' ),
			'change_payment_method_url'            => WC_AJAX::get_endpoint( 'bco_wc_change_payment_method' ),
			'change_payment_method_nonce'          => wp_create_nonce( 'bco_wc_change_payment_method' ),
			'get_checkout_url'                     => WC_AJAX::get_endpoint( 'bco_wc_get_checkout' ),
			'get_checkout_nonce'                   => wp_create_nonce( 'bco_wc_get_checkout' ),
			'iframe_shipping_address_change_url'   => WC_AJAX::get_endpoint( 'bco_wc_iframe_shipping_address_change' ),
			'iframe_shipping_address_change_nonce' => wp_create_nonce( 'bco_wc_iframe_shipping_address_change' ),
			'checkout_success_url'                 => WC_AJAX::get_endpoint( 'bco_wc_checkout_success' ),
			'checkout_success_nonce'               => wp_create_nonce( 'bco_wc_checkout_success' ),
		);

		wp_localize_script(
			'bco-checkout',
			'bco_wc_params',
			$params
		);
		wp_enqueue_script( 'bco-checkout' );

		wp_register_style(
			'bco',
			BILLMATE_CHECKOUT_URL . '/assets/css/bco-style.css',
			array(),
			BILLMATE_CHECKOUT_VERSION
		);
		wp_enqueue_style( 'bco' );
	}

	/**
	 * Check if this gateway is enabled and available in the user's country.
	 *
	 * @return boolean
	 */
	public function is_available() {
		if ( 'yes' === $this->enabled ) {
			// Do checks here.
			return true;
		}
		return false;
	}

	/**
	 * Saves Billmate specific data stored in WC()->session to Woo order when created.
	 *
	 * @param string $order_id The WooCommerce order ID.
	 * @param array  $posted_data The WooCommerce checkout form posted data.
	 * @param object $order WooCommerce order.
	 *
	 * @return void
	 */
	public function save_billmate_temp_order_id_to_order( $order_id, $posted_data, $order ) {
		if ( 'bco' === $order->get_payment_method() ) {
			update_post_meta( $order_id, '_billmate_temp_order_id', WC()->session->get( 'bco_wc_temp_order_id' ) );
			update_post_meta( $order_id, '_billmate_hash', WC()->session->get( 'bco_wc_hash' ) );
		}
	}

	/**
	 * Processes the WooCommerce Payment
	 *
	 * @param string $order_id The WooCommerce order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		WC()->session->set( 'bco_wc_order_id', $order_id );

		$confirmation_url = add_query_arg(
			array(
				'bco_confirm' => 'yes',
				'bco_flow'    => 'checkout',
				'wc_order_id' => $order_id,
			),
			$order->get_checkout_order_received_url()
		);
		$response         = array(
			'redirect_url' => $confirmation_url,
			'time'         => microtime(),
		);
		return array(
			'result'   => 'success',
			'redirect' => ( 'checkout' === $this->checkout_flow ) ? '#billmate-success=' . base64_encode( wp_json_encode( $response ) ) : $order->get_checkout_payment_url(), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Base64 used to give a unique nondescript string.
		);
	}

	/**
	 * This plugin doesn't handle order management, but it allows Billmate Order Management plugin to process refunds
	 * and then return true or false.
	 *
	 * @param string $order_id The WooCommerce order ID.
	 * @param float  $amount The amount to be refunded.
	 * @param string $reason The reason given for the refund.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return apply_filters( 'wc_billmate_checkout_process_refund', false, $order_id, $amount, $reason );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = include BILLMATE_CHECKOUT_PATH . '/includes/bco-form-fields.php';
	}

	/**
	 * Shows the Billmate thankyou on the wc thankyou page.
	 *
	 * @param string $order_id The WooCommerce order id.
	 * @return void
	 */
	public function billmate_thank_you( $order_id ) {
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			$order->add_order_note( __( 'Order finalized in thankyou page.', 'billmate-checkout-for-woocommerce' ) );

			// Clear cart.
			WC()->cart->empty_cart();

			// Unset sessions.
			bco_wc_unset_sessions();
		}
	}
}

/**
 * Add Billmate_Checkout payment gateway
 *
 * @wp_hook woocommerce_payment_gateways
 * @param  array $methods All registered payment methods.
 * @return array $methods All registered payment methods.
 */
function add_bco_method( $methods ) {
	$methods[] = 'BCO_Gateway';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_bco_method' );
