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

        if ( is_admin() ) {
            $admin = new ZBP_Admin( $product_service );
            $admin->register();
        }
    }
}
