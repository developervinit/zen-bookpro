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
        add_action( 'wp_ajax_zbp_admin_get_slots_for_date', array( $this, 'ajax_admin_get_slots' ) );
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

                echo '<div class="form-field show_if_zbp_event zbp-cancellation-dates-wrapper" style="clear: both; margin: 9px 0; padding-left: 162px; min-height: 40px;">';
                echo '<label style="float: left; width: 150px; margin-left: -162px; font-weight: 700;">' . esc_html__( 'Cancel Dates', 'zen-bookpro' ) . '</label>';
                echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff; display: inline-block; min-width: 300px; box-sizing: border-box; border-radius: 4px; vertical-align: middle;">';
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
            }

            // Render Free Flow Slots Checklist UI
            $cancelled_slots = get_post_meta( $product_id, '_zbp_cancelled_slots', true );
            if ( ! is_array( $cancelled_slots ) ) {
                $cancelled_slots = array();
            }

            wp_nonce_field( 'zbp_admin_nonce', 'zbp_admin_nonce_field' );
            ?>
            <div class="form-field show_if_zbp_free_flow zbp-free-flow-cancellation-wrapper" style="clear: both; margin: 9px 0; padding-left: 162px; min-height: 40px; display: none;">
                <label style="float: left; width: 150px; margin-left: -162px; font-weight: 700;"><?php esc_html_e( 'Cancel Slots', 'zen-bookpro' ); ?></label>
                <div style="background: #f9f9f9; border: 1px solid #ccc; padding: 12px; border-radius: 4px; display: inline-block; min-width: 450px; box-sizing: border-box;">
                    <p style="margin: 0 0 10px 0;">
                        <label style="font-weight: 600; display: block; margin-bottom: 4px;"><?php esc_html_e( '1. Select Date:', 'zen-bookpro' ); ?></label>
                        <input type="date" id="zbp_freeflow_cancel_date_picker" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" style="width: 100%; max-width: 250px; vertical-align: middle; height: 30px; line-height: 28px; padding: 0 8px;" />
                        <button type="button" id="zbp_freeflow_load_slots_btn" class="button" style="margin-left: 8px; vertical-align: middle;"><?php esc_html_e( 'Load Slots', 'zen-bookpro' ); ?></button>
                    </p>
                    <div id="zbp_freeflow_slots_list_container" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; background: #fff; padding: 8px; border-radius: 3px; display: none; margin-top: 10px;">
                        <!-- Slots checkboxes loaded here via AJAX -->
                    </div>
                    <p id="zbp_freeflow_slots_helper_text" style="margin: 8px 0 0 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Select a date and click \'Load Slots\' to manage cancellations for that date.', 'zen-bookpro' ); ?></p>
                </div>
                <input type="hidden" name="_zbp_cancelled_slots" id="_zbp_cancelled_slots" value="<?php echo esc_attr( wp_json_encode( $cancelled_slots ) ); ?>" />
            </div>
            <?php
        }

        ?>
        <script type="text/javascript">
        jQuery(function($){
            function toggleZbpEventFields() {
                var mode = $('#_zbp_product_mode').val();
                if (mode === 'event') {
                    $('.show_if_zbp_event').show();
                    $('.show_if_zbp_free_flow').hide();
                } else if (mode === 'free_flow') {
                    $('.show_if_zbp_event').hide();
                    $('.show_if_zbp_free_flow').show();
                } else {
                    $('.show_if_zbp_event').hide();
                    $('.show_if_zbp_free_flow').hide();
                }
            }
            $('#_zbp_product_mode').on('change', toggleZbpEventFields);
            toggleZbpEventFields();

            // Free Flow slot loading and selection handling
            $('#zbp_freeflow_load_slots_btn').on('click', function(e){
                e.preventDefault();
                var productId = <?php echo (int) $product_id; ?>;
                var selectedDate = $('#zbp_freeflow_cancel_date_picker').val();
                var nonce = $('#zbp_admin_nonce_field').val();

                if (!selectedDate) {
                    alert('Please select a date first.');
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true).text('Loading...');
                var $container = $('#zbp_freeflow_slots_list_container');
                var $helper = $('#zbp_freeflow_slots_helper_text');

                $container.hide().html('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zbp_admin_get_slots_for_date',
                        product_id: productId,
                        date: selectedDate,
                        security: nonce
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text('Load Slots');
                        if (response.success && response.data && response.data.slots) {
                            var slots = response.data.slots;
                            if (slots.length === 0) {
                                $container.html('<p style="margin: 0; color: #888;">No slots available for this date.</p>').show();
                                return;
                            }

                            var cancelledStr = $('#_zbp_cancelled_slots').val() || '[]';
                            var cancelledArray = [];
                            try {
                                cancelledArray = JSON.parse(cancelledStr);
                            } catch(err) {
                                cancelledArray = [];
                            }

                            var html = '';
                            $.each(slots, function(idx, slot) {
                                var isChecked = cancelledArray.indexOf(slot.start) !== -1 ? 'checked' : '';
                                var isCancelledStatus = slot.status === 'cancelled';
                                var style = isCancelledStatus ? 'text-decoration: line-through; opacity: 0.6;' : '';

                                html += '<label style="display: block; margin: 4px 0; cursor: pointer; ' + style + '">';
                                html += '<input type="checkbox" class="zbp-free-flow-slot-checkbox" value="' + slot.start + '" ' + isChecked + ' style="margin-right: 8px; vertical-align: middle;" />';
                                html += '<span style="vertical-align: middle;">' + slot.label + '</span>';
                                html += '</label>';
                            });

                            $container.html(html).show();
                            $helper.text('Check the slots you wish to cancel. Remember to click the main WooCommerce Update button to save.');
                        } else {
                            $container.html('<p style="margin: 0; color: #c00;">Failed to retrieve slots.</p>').show();
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Load Slots');
                        $container.html('<p style="margin: 0; color: #c00;">Error contacting server.</p>').show();
                    }
                });
            });

            $(document).on('change', '.zbp-free-flow-slot-checkbox', function() {
                var val = $(this).val();
                var isChecked = $(this).is(':checked');
                var cancelledStr = $('#_zbp_cancelled_slots').val() || '[]';
                var cancelledArray = [];
                try {
                    cancelledArray = JSON.parse(cancelledStr);
                } catch(err) {
                    cancelledArray = [];
                }

                if (isChecked) {
                    if (cancelledArray.indexOf(val) === -1) {
                        cancelledArray.push(val);
                    }
                    $(this).closest('label').css({
                        'text-decoration': 'line-through',
                        'opacity': '0.6'
                    });
                } else {
                    var idx = cancelledArray.indexOf(val);
                    if (idx !== -1) {
                        cancelledArray.splice(idx, 1);
                    }
                    $(this).closest('label').css({
                        'text-decoration': 'none',
                        'opacity': '1.0'
                    });
                }

                $('#_zbp_cancelled_slots').val(JSON.stringify(cancelledArray));
            });
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

            // Clear free flow meta if in event mode
            $product->update_meta_data( '_zbp_cancelled_slots', array() );
        } elseif ( 'free_flow' === $mode ) {
            $old_cancelled_slots = $product->get_meta( '_zbp_cancelled_slots' );
            if ( ! is_array( $old_cancelled_slots ) ) {
                $old_cancelled_slots = array();
            }

            $new_raw = isset( $_POST['_zbp_cancelled_slots'] ) ? wp_unslash( $_POST['_zbp_cancelled_slots'] ) : '[]';
            $new_cancelled_slots = json_decode( $new_raw, true );
            if ( ! is_array( $new_cancelled_slots ) ) {
                $new_cancelled_slots = array();
            }

            // Timezone-aware auto-pruning: Keep only future or active slots
            $timezone = wp_timezone();
            $now = new DateTime( 'now', $timezone );
            $now_timestamp = $now->getTimestamp();

            $pruned_slots = array();
            foreach ( $new_cancelled_slots as $slot_time ) {
                try {
                    $slot_dt = new DateTime( $slot_time, $timezone );
                    $slot_timestamp = $slot_dt->getTimestamp();
                } catch ( Exception $e ) {
                    $slot_timestamp = 0;
                }

                if ( $slot_timestamp >= $now_timestamp ) {
                    $pruned_slots[] = $slot_time;
                }
            }

            // 1. Save metadata
            $product->update_meta_data( '_zbp_cancelled_slots', array_values( $pruned_slots ) );

            // 2. Handle newly cancelled slots (trigger booking updates)
            $newly_added_slots = array_diff( $pruned_slots, $old_cancelled_slots );
            if ( ! empty( $newly_added_slots ) ) {
                $cancellation_service = new ZBP_Cancellation_Service();
                foreach ( $newly_added_slots as $slot_start_time ) {
                    $cancellation_service->cancel_slot( $product->get_id(), $slot_start_time );
                }
            }

            // Clear event meta if in free flow mode
            $product->update_meta_data( '_zbp_cancelled_dates', array() );
        } else {
            // Clear cancelled dates and slots if mode changes to something else
            $product->update_meta_data( '_zbp_cancelled_dates', array() );
            $product->update_meta_data( '_zbp_cancelled_slots', array() );
        }
    }

    /**
     * AJAX handler for retrieving slots of a specific product and date for the admin checklist.
     */
    public function ajax_admin_get_slots() {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $security = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
        if ( ! wp_verify_nonce( $security, 'zbp_admin_nonce' ) ) {
            wp_send_json_error( 'Invalid nonce', 400 );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $date       = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';

        if ( ! $product_id || ! $date ) {
            wp_send_json_error( 'Missing parameters', 400 );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'booking' ) ) {
            wp_send_json_error( 'Invalid product', 400 );
        }

        if ( ! class_exists( 'ZBP_Slot_Service' ) ) {
            require_once dirname( __FILE__ ) . '/services/class-zbp-slot-service.php';
        }

        $slot_service = new ZBP_Slot_Service();
        $slot_result  = $slot_service->get_slots_for_product( $product, $date, 'free_flow', true );
        $slots        = isset( $slot_result['slots'] ) ? $slot_result['slots'] : array();

        wp_send_json_success( array( 'slots' => $slots ) );
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
            foreach ( $blocks as $key => $val ) {
                $timestamp = 0;
                if ( is_numeric( $key ) && (int) $key > 100000000 ) {
                    $timestamp = (int) $key;
                } elseif ( is_numeric( $val ) && (int) $val > 100000000 ) {
                    $timestamp = (int) $val;
                }

                if ( $timestamp > 0 ) {
                    $dates[] = wp_date( 'Y-m-d', $timestamp );
                }
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
