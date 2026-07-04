<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Loader {
    /**
     * Register plugin hooks.
     *
     * @return void
     */
    public function run() {
        $product_service = new ZBP_Product_Service();

        $shortcode = new ZBP_Shortcode( $product_service );
        $shortcode->register();

        $email_service = new ZBP_Email_Service();
        $email_service->register();

        if ( is_admin() ) {
            $admin = new ZBP_Admin( $product_service );
            $admin->register();

            $product_mode = new ZBP_Product_Mode();
            $product_mode->register();
        }

        // Handle GET requests to add booking products to cart.
        add_action( 'wp_loaded', array( $this, 'maybe_copy_get_booking_fields_to_post' ), 5 );
    }

    /**
     * Copy wc_bookings_field_ parameters from $_GET to $_POST when adding to cart.
     * This makes GET-based add-to-cart redirects work seamlessly with WooCommerce Bookings,
     * which natively expects those variables to be in $_POST.
     *
     * @return void
     */
    public function maybe_copy_get_booking_fields_to_post() {
        if ( empty( $_GET['add-to-cart'] ) ) {
            return;
        }

        // If wc_bookings_field_ parameters are in $_GET, but not in $_POST, copy them.
        foreach ( $_GET as $key => $val ) {
            if ( strpos( $key, 'wc_bookings_field_' ) === 0 ) {
                if ( ! isset( $_POST[ $key ] ) ) {
                    $_POST[ $key ] = sanitize_text_field( wp_unslash( $val ) );
                }
            }
        }
    }
}
