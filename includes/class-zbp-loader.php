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
        $shortcode = new ZBP_Shortcode( new ZBP_Product_Service() );
        $shortcode->register();
    }
}
