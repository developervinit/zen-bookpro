<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Product_Mode {
    /**
     * Meta key for product mode.
     */
    const META_KEY = '_zbp_product_mode';

    /**
     * Allowed mode values.
     *
     * @return array
     */
    private function get_allowed_modes() {
        return array( 'free_flow', 'event' );
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register() {
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_field' ) );
        add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_field' ) );
    }

    /**
     * Render booking mode select field for booking products.
     *
     * @return void
     */
    public function render_field() {
        global $post;

        $product_id = isset( $post->ID ) ? absint( $post->ID ) : 0;
        $mode       = 'free_flow';

        if ( $product_id > 0 ) {
            $saved_mode = get_post_meta( $product_id, self::META_KEY, true );
            $mode       = $this->sanitize_mode( $saved_mode );
        }



        echo '<div class="options_group show_if_booking">';

        woocommerce_wp_select(
            array(
                'id'          => self::META_KEY,
                'label'       => __( 'Booking Mode', 'zen-bookpro' ),
                'description' => __( 'Select how this booking product should behave.', 'zen-bookpro' ),
                'desc_tip'    => true,
                'value'       => $mode,
                'options'     => array(
                    'free_flow' => __( 'Free Flow', 'zen-bookpro' ),
                    'event'     => __( 'Event (Single Slot)', 'zen-bookpro' ),
                ),
            )
        );

        echo '</div>';
    }

    /**
     * Save booking mode value.
     *
     * @param WC_Product $product Product object.
     *
     * @return void
     */
    public function save_field( $product ) {
        if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
            return;
        }

        $raw_mode = isset( $_POST[ self::META_KEY ] ) ? wp_unslash( $_POST[ self::META_KEY ] ) : 'free_flow';
        $mode     = $this->sanitize_mode( $raw_mode );

        $product->update_meta_data( self::META_KEY, $mode );


    }

    /**
     * Sanitize mode value and enforce allowed options.
     *
     * @param string $mode Raw mode value.
     *
     * @return string
     */
    private function sanitize_mode( $mode ) {
        $mode = sanitize_key( $mode );

        if ( ! in_array( $mode, $this->get_allowed_modes(), true ) ) {
            return 'free_flow';
        }

        return $mode;
    }

}
