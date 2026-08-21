<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Shortcode {
    /**
     * Product service instance.
     *
     * @var ZBP_Product_Service
     */
    private $product_service;

    /**
     * Constructor.
     *
     * @param ZBP_Product_Service $product_service Product service.
     */
    public function __construct( $product_service ) {
        $this->product_service = $product_service;
    }

    /**
     * Register shortcode and front-end assets.
     *
     * @return void
     */
    public function register() {
        add_shortcode( 'zen_bookpro', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'wp_ajax_zbp_get_slots', array( $this, 'ajax_get_slots' ) );
        add_action( 'wp_ajax_nopriv_zbp_get_slots', array( $this, 'ajax_get_slots' ) );
        add_action( 'wp_ajax_zbp_validate_booking_add_to_cart', array( $this, 'ajax_validate_booking_add_to_cart' ) );
        add_action( 'wp_ajax_nopriv_zbp_validate_booking_add_to_cart', array( $this, 'ajax_validate_booking_add_to_cart' ) );
    }

    /**
     * Register public assets.
     *
     * @return void
     */
    public function register_assets() {
        $script_url = ZBP_PLUGIN_URL . 'public/assets/js/script.js';
        $script_path = ZBP_PLUGIN_PATH . 'public/assets/js/script.js';
        $style_url = ZBP_PLUGIN_URL . 'public/assets/css/style.css';
        $style_path = ZBP_PLUGIN_PATH . 'public/assets/css/style.css';

        $script_ver = ZBP_VERSION . '.' . ( file_exists( $script_path ) ? filemtime( $script_path ) : time() );
        $style_ver  = ZBP_VERSION . '.' . ( file_exists( $style_path ) ? filemtime( $style_path ) : time() );

        wp_register_style(
            'zbp-style',
            $style_url,
            array(),
            $style_ver
        );

        wp_register_script(
            'zbp-script',
            $script_url,
            array(),
            $script_ver,
            true
        );

        wp_localize_script(
            'zbp-script',
            'zbpAjax',
            array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'zbp_get_slots' ),
                'isAdmin'     => current_user_can( 'manage_options' ),
                'cartUrl'     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
            )
        );
    }

    /**
     * Handle AJAX request to fetch products and slots for selected date.
     *
     * @return void
     */
    public function ajax_get_slots() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'zbp_get_slots' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid request.', 'zen-bookpro' ),
                ),
                403
            );
        }

        $raw_date = isset( $_POST['date'] ) ? wp_unslash( $_POST['date'] ) : '';
        $date     = sanitize_text_field( (string) $raw_date );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid date format. Use YYYY-MM-DD.', 'zen-bookpro' ),
                ),
                400
            );
        }



        $filters = array(
            'experience_category' => isset( $_POST['experience'] ) ? absint( wp_unslash( $_POST['experience'] ) ) : 0,
            'activity_type'       => isset( $_POST['activity'] ) ? absint( wp_unslash( $_POST['activity'] ) ) : 0,
            'selected_date'       => $date,
        );

        $products = $this->product_service->get_products( $filters );

        $response_products = array_map(
            function ( $product ) {
                $booking_coin_cost = isset( $product['booking_coin_cost'] ) ? sanitize_text_field( $product['booking_coin_cost'] ) : ( isset( $product['zen_coins'] ) ? sanitize_text_field( $product['zen_coins'] ) : '' );

                return array(
                    'id'              => isset( $product['id'] ) ? absint( $product['id'] ) : 0,
                    'name'            => isset( $product['title'] ) ? sanitize_text_field( $product['title'] ) : '',
                    'description'     => isset( $product['description'] ) ? sanitize_textarea_field( $product['description'] ) : '',
                    'cancellation_policy' => isset( $product['cancellation_policy'] ) ? sanitize_textarea_field( $product['cancellation_policy'] ) : '',
                    'location'        => isset( $product['location'] ) ? sanitize_text_field( $product['location'] ) : '',
                    'mode'            => isset( $product['mode'] ) ? sanitize_key( $product['mode'] ) : 'free_flow',
                    'duration'        => isset( $product['duration'] ) ? sanitize_text_field( $product['duration'] ) : '',
                    'zen_duration'    => isset( $product['zen_duration'] ) ? sanitize_text_field( $product['zen_duration'] ) : '',
                    'booking_duration_minutes' => isset( $product['booking_duration_minutes'] ) ? (int) $product['booking_duration_minutes'] : 0,
                    'experience_category' => isset( $product['experience_category'] ) ? sanitize_text_field( $product['experience_category'] ) : '',
                    'booking_coin_cost' => $booking_coin_cost,
                    'zen_coins'       => $booking_coin_cost,
                    'image'           => isset( $product['image'] ) ? esc_url_raw( $product['image'] ) : '',
                    'product_featured_image' => isset( $product['product_featured_image'] ) ? esc_url_raw( $product['product_featured_image'] ) : '',
                    'gallery'         => isset( $product['gallery'] ) ? $product['gallery'] : array(),
                    'price_html'      => isset( $product['price_html'] ) ? $product['price_html'] : '',
                    'slots'           => isset( $product['slots'] ) ? $product['slots'] : array(),
                    'max_spots'       => isset( $product['max_spots'] ) ? (int) $product['max_spots'] : 1,
                    'booked_spots'    => isset( $product['booked_spots'] ) ? (int) $product['booked_spots'] : 0,
                    'event_status'    => isset( $product['event_status'] ) ? $product['event_status'] : 'join',
                    'slot_debug'      => isset( $product['slot_debug'] ) ? $product['slot_debug'] : '',
                    'zen_instructor'  => isset( $product['zen_instructor'] ) ? sanitize_text_field( $product['zen_instructor'] ) : '',
                    'product_list_order' => isset( $product['product_list_order'] ) ? $product['product_list_order'] : '',
                );
            },
            $products
        );

        wp_send_json_success(
            array(
                'products' => $response_products,
            )
        );
    }

    /**
     * Validate the booking cart state before the front-end fires Woo add-to-cart.
     *
     * @return void
     */
    public function ajax_validate_booking_add_to_cart() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'zbp_get_slots' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid request.', 'zen-bookpro' ),
                ),
                403
            );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

        if ( $product_id <= 0 ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Unable to prepare this booking. Please try again.', 'zen-bookpro' ),
                ),
                400
            );
        }

        $this->ensure_wc_cart();

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Unable to read your cart. Please refresh and try again.', 'zen-bookpro' ),
                ),
                500
            );
        }

        if ( $this->cart_has_booking_item() && $this->is_booking_product( $product_id ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Please book one class, workshop, event, or Fire & Ice session at a time.', 'zen-bookpro' ),
                ),
                409
            );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Booking can be added to cart.', 'zen-bookpro' ),
            )
        );
    }

    /**
     * Ensure the Woo cart is available during admin-ajax requests.
     *
     * @return void
     */
    private function ensure_wc_cart() {
        if ( function_exists( 'WC' ) && WC()->cart ) {
            return;
        }

        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }
    }

    /**
     * Check whether the cart already contains a booking item.
     *
     * @return bool
     */
    private function cart_has_booking_item() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['booking'] ) ) {
                return true;
            }

            if ( ! empty( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product && $cart_item['data']->is_type( array( 'booking', 'bookable' ) ) ) {
                return true;
            }

            $cart_product_id = ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
            $variation_id    = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

            if ( $cart_product_id && $this->is_booking_product( $variation_id ? $variation_id : $cart_product_id ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a product is a booking product.
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    private function is_booking_product( $product_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return false;
        }

        $product = wc_get_product( $product_id );

        if ( $product instanceof WC_Product && $product->is_type( array( 'booking', 'bookable' ) ) ) {
            return true;
        }

        if ( (float) get_post_meta( $product_id, '_cbb_booking_coin_cost', true ) > 0 ) {
            return true;
        }

        if ( $product instanceof WC_Product && $product->get_parent_id() && (float) get_post_meta( $product->get_parent_id(), '_cbb_booking_coin_cost', true ) > 0 ) {
            return true;
        }

        return false;
    }
    /**
     * Render shortcode template.
     *
     * @param array $atts Shortcode attributes.
     *
     * @return string
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'product_id' => '',
                'experience' => '',
                'activity'   => '',
                'date'       => '',
            ),
            $atts,
            'zen_bookpro'
        );

        wp_enqueue_style( 'zbp-style' );
        wp_enqueue_script( 'zbp-script' );

        $template_file = ZBP_PLUGIN_PATH . 'public/templates/booking-ui.php';

        if ( ! file_exists( $template_file ) ) {
            return '';
        }

        $experience_filter = isset( $_GET['experience'] ) ? wp_unslash( $_GET['experience'] ) : $atts['experience'];
        $activity_filter   = isset( $_GET['activity'] ) ? wp_unslash( $_GET['activity'] ) : $atts['activity'];
        $date_filter       = isset( $_GET['date'] ) ? wp_unslash( $_GET['date'] ) : $atts['date'];

        $filters = array(
            'experience_category' => absint( $experience_filter ),
            'activity_type'       => absint( $activity_filter ),
            'selected_date'       => sanitize_text_field( $date_filter ),
        );

        $products = $this->product_service->get_products( $filters );

        ob_start();
        $product_id = $atts['product_id'];
        include $template_file;

        return ob_get_clean();
    }

}

