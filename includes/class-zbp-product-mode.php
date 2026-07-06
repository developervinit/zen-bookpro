<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Product_Mode {
    /**
     * Meta key for product mode.
     */
    const META_KEY = '_zbp_product_mode';
    const META_KEY_CANCELLATION_POLICY = '_zbp_cancellation_policy';
    const META_KEY_LOCATION = '_zbp_location';

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

        // Fetch bookable dates and merge with currently cancelled dates
        if ( $product_id > 0 ) {
            $product_obj = wc_get_product( $product_id );
            if ( $product_obj && $product_obj->is_type( 'booking' ) ) {
                $bookable_dates  = $this->get_bookable_dates( $product_obj );
                $cancelled_dates = $product_obj->get_meta( '_zbp_cancelled_dates' );
                if ( ! is_array( $cancelled_dates ) ) {
                    $cancelled_dates = array();
                }

                $all_dates = array_unique( array_merge( $cancelled_dates, $bookable_dates ) );
                sort( $all_dates );

                echo '<div class="options_group show_if_booking show_if_zbp_event zbp-cancellation-dates-wrapper" style="padding: 10px 162px; border-bottom: 1px solid #eee;">';
                echo '<label style="float: left; width: 150px; margin-left: -162px; font-weight: 700;">' . esc_html__( 'Cancel Dates', 'zen-bookpro' ) . '</label>';
                echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff; display: inline-block; min-width: 300px; box-sizing: border-box; border-radius: 4px;">';
                if ( empty( $all_dates ) ) {
                    echo '<p style="margin: 0; color: #888;">' . esc_html__( 'No bookable dates found.', 'zen-bookpro' ) . '</p>';
                } else {
                    foreach ( $all_dates as $date ) {
                        $checked = in_array( $date, $cancelled_dates, true ) ? 'checked="checked"' : '';
                        $formatted_date = wp_date( get_option( 'date_format' ), strtotime( $date ) );
                        echo '<label style="display: block; margin: 6px 0; font-weight: normal; cursor: pointer; float: none; width: auto; clear: none; text-align: left;">';
                        echo '<input type="checkbox" name="_zbp_cancelled_dates[]" value="' . esc_attr( $date ) . '" ' . $checked . ' style="margin-right: 8px; vertical-align: middle;" />';
                        echo '<span style="vertical-align: middle;">' . esc_html( $formatted_date ) . ' (' . esc_html( $date ) . ')</span>';
                        echo '</label>';
                    }
                }
                echo '</div>';
                echo '<p class="description" style="margin-top: 5px; clear: both;">' . esc_html__( 'Select the specific dates of this event that should be cancelled.', 'zen-bookpro' ) . '</p>';
                echo '</div>';
            }
        }

        ?>
        <script type="text/javascript">
        jQuery(function($){
            function toggleZbpEventFields() {
                var mode = $('#_zbp_product_mode').val();
                if (mode === 'event') {
                    $('.show_if_zbp_event').show();
                } else {
                    $('.show_if_zbp_event').hide();
                }
            }
            $('#_zbp_product_mode').on('change', toggleZbpEventFields);
            toggleZbpEventFields();
        });
        </script>
        <?php

        woocommerce_wp_textarea_input(
            array(
                'id'          => self::META_KEY_CANCELLATION_POLICY,
                'label'       => __( 'Cancelation policy', 'zen-bookpro' ),
                'description' => __( 'Enter cancellation policy for this booking product.', 'zen-bookpro' ),
                'desc_tip'    => true,
                'value'       => $product_id > 0 ? (string) get_post_meta( $product_id, self::META_KEY_CANCELLATION_POLICY, true ) : '',
            )
        );

        woocommerce_wp_textarea_input(
            array(
                'id'          => self::META_KEY_LOCATION,
                'label'       => __( 'Location', 'zen-bookpro' ),
                'description' => __( 'Enter location for this booking product.', 'zen-bookpro' ),
                'desc_tip'    => true,
                'value'       => $product_id > 0 ? (string) get_post_meta( $product_id, self::META_KEY_LOCATION, true ) : '',
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

        $raw_cancellation_policy = isset( $_POST[ self::META_KEY_CANCELLATION_POLICY ] ) ? wp_unslash( $_POST[ self::META_KEY_CANCELLATION_POLICY ] ) : '';
        $raw_location            = isset( $_POST[ self::META_KEY_LOCATION ] ) ? wp_unslash( $_POST[ self::META_KEY_LOCATION ] ) : '';

        $product->update_meta_data( self::META_KEY_CANCELLATION_POLICY, sanitize_text_field( $raw_cancellation_policy ) );
        $product->update_meta_data( self::META_KEY_LOCATION, sanitize_text_field( $raw_location ) );

        // Handle date-specific cancellations
        if ( 'event' === $mode ) {
            $old_cancelled_dates = $product->get_meta( '_zbp_cancelled_dates' );
            if ( ! is_array( $old_cancelled_dates ) ) {
                $old_cancelled_dates = array();
            }

            $new_cancelled_dates = isset( $_POST['_zbp_cancelled_dates'] ) ? array_map( 'sanitize_text_field', $_POST['_zbp_cancelled_dates'] ) : array();

            // 1. Handle uncancelled dates (removed from checklist)
            $uncancelled_dates = array_diff( $old_cancelled_dates, $new_cancelled_dates );
            if ( ! empty( $uncancelled_dates ) ) {
                $updated_cancelled = array_diff( $old_cancelled_dates, $uncancelled_dates );
                $product->update_meta_data( '_zbp_cancelled_dates', array_values( $updated_cancelled ) );
                $product->save();
            }

            // 2. Handle newly cancelled dates (added to checklist)
            $newly_added_dates = array_diff( $new_cancelled_dates, $old_cancelled_dates );
            if ( ! empty( $newly_added_dates ) ) {
                $cancellation_service = new ZBP_Cancellation_Service();
                foreach ( $newly_added_dates as $date ) {
                    $cancellation_service->cancel_event( $product->get_id(), $date );
                }
            }
        } else {
            // Clear cancelled dates if mode changes away from event
            $product->update_meta_data( '_zbp_cancelled_dates', array() );
        }


    }

    /**
     * Retrieve valid bookable dates for a booking product.
     *
     * @param WC_Product $product WooCommerce Product object.
     * @return array Array of Y-m-d date strings.
     */
    private function get_bookable_dates( $product ) {
        if ( ! $product || ! $product->is_type( 'booking' ) ) {
            return array();
        }

        // Resolve WC_Product_Booking instance
        $booking_product = null;
        if ( class_exists( 'WC_Product_Booking' ) ) {
            $booking_product = new WC_Product_Booking( $product->get_id() );
        }

        if ( ! $booking_product ) {
            return array();
        }

        $timezone = wp_timezone();
        $now      = new DateTime( 'now', $timezone );
        $from     = $now->setTime( 0, 0, 0 )->getTimestamp();

        // Retrieve max booking window
        $max_date = $booking_product->get_max_date();
        if ( ! empty( $max_date['value'] ) && ! empty( $max_date['unit'] ) ) {
            $to = strtotime( '+' . $max_date['value'] . ' ' . $max_date['unit'], $from );
        } else {
            $to = strtotime( '+12 month', $from );
        }

        // Retrieve bookable blocks in range
        $blocks = array();
        if ( method_exists( $booking_product, 'get_blocks_in_range' ) ) {
            $blocks = $booking_product->get_blocks_in_range( $from, $to );
        }

        $dates = array();
        if ( is_array( $blocks ) ) {
            foreach ( $blocks as $timestamp ) {
                $dates[] = wp_date( 'Y-m-d', $timestamp );
            }
        }

        return array_values( array_unique( $dates ) );
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
