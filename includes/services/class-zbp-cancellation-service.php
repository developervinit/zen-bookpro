<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Cancellation_Service {
    /**
     * Cancel an event on a specific date.
     *
     * @param int    $product_id WooCommerce Product ID.
     * @param string $date       Date in Y-m-d format.
     * @return bool|WP_Error
     */
    public function cancel_event( $product_id, $date ) {
        // Validate product exists and is a booking type
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'booking' ) ) {
            return new WP_Error( 'invalid_product', __( 'Invalid product or product is not a booking.', 'zen-bookpro' ) );
        }

        // 1. Persist the cancellation date to the product meta `_zbp_cancelled_dates`
        $cancelled_dates = $product->get_meta( '_zbp_cancelled_dates' );
        if ( ! is_array( $cancelled_dates ) ) {
            $cancelled_dates = array();
        }

        if ( ! in_array( $date, $cancelled_dates, true ) ) {
            $cancelled_dates[] = $date;
            $product->update_meta_data( '_zbp_cancelled_dates', $cancelled_dates );
            $product->save();
        }

        // 2. Query all active bookings for this product on the date
        $date_from = strtotime( $date . ' 00:00:00' );
        $date_to   = strtotime( $date . ' 23:59:59' );

        $active_statuses = array( 'confirmed', 'paid', 'complete', 'unpaid', 'pending-confirmation', 'in-cart', 'on-hold' );
        $bookings = array();

        if ( class_exists( 'WC_Booking_Data_Store' ) && method_exists( 'WC_Booking_Data_Store', 'get_bookings_for_objects' ) ) {
            $bookings = WC_Booking_Data_Store::get_bookings_for_objects(
                array( $product_id ),
                $active_statuses,
                $date_from,
                $date_to
            );
        } elseif ( class_exists( 'WC_Bookings_Controller' ) && method_exists( 'WC_Bookings_Controller', 'get_bookings_for_objects' ) ) {
            $bookings = WC_Bookings_Controller::get_bookings_for_objects(
                array( $product_id ),
                $active_statuses,
                $date_from,
                $date_to
            );
        }

        // 3. Update each booking's status to 'cancelled'
        $cancelled_booking_ids = array();
        foreach ( $bookings as $booking ) {
            if ( $booking && is_a( $booking, 'WC_Booking' ) ) {
                $booking->update_status( 'cancelled' );
                $cancelled_booking_ids[] = $booking->get_id();
            }
        }

        // 4. Fire the modular custom WordPress action
        do_action( 'zbp_event_cancelled', $product_id, $date, $cancelled_booking_ids );

        return true;
    }
}
