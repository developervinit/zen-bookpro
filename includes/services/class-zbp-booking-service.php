<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Booking_Service {
    /**
     * Retrieve all active bookings for a given product ID.
     *
     * @param int   $product_id WooCommerce Product ID.
     * @param array $statuses   Optional. Array of booking statuses to filter by.
     * @return array Array of structured booking data.
     */
    public function get_affected_bookings( $product_id, $statuses = array() ) {
        if ( empty( $statuses ) ) {
            $statuses = array( 'confirmed', 'paid', 'complete', 'unpaid', 'pending-confirmation', 'on-hold' );
        }

        $bookings = array();

        if ( class_exists( 'WC_Booking_Data_Store' ) && method_exists( 'WC_Booking_Data_Store', 'get_bookings_for_objects' ) ) {
            $bookings = WC_Booking_Data_Store::get_bookings_for_objects(
                array( $product_id ),
                $statuses
            );
        } elseif ( class_exists( 'WC_Bookings_Controller' ) && method_exists( 'WC_Bookings_Controller', 'get_bookings_for_objects' ) ) {
            $bookings = WC_Bookings_Controller::get_bookings_for_objects(
                array( $product_id ),
                $statuses
            );
        }

        $structured_bookings = array();

        foreach ( $bookings as $booking ) {
            if ( ! $booking || ! is_a( $booking, 'WC_Booking' ) ) {
                continue;
            }

            // Get customer details
            $customer = $booking->get_customer();
            $customer_name  = '';
            $customer_email = '';

            if ( $customer ) {
                $customer_name  = ! empty( $customer->name ) ? $customer->name : '';
                $customer_email = ! empty( $customer->email ) ? $customer->email : '';
            }

            // Fallback to order details if available
            $order = $booking->get_order();
            if ( $order ) {
                if ( empty( $customer_name ) ) {
                    $customer_name = $order->get_formatted_billing_full_name();
                }
                if ( empty( $customer_email ) ) {
                    $customer_email = $order->get_billing_email();
                }
            }

            // Fallback to WP User if available
            if ( empty( $customer_email ) && $booking->get_customer_id() ) {
                $user = get_userdata( $booking->get_customer_id() );
                if ( $user ) {
                    if ( empty( $customer_name ) ) {
                        $customer_name = $user->display_name;
                    }
                    $customer_email = $user->user_email;
                }
            }

            $structured_bookings[] = array(
                'booking_id'     => $booking->get_id(),
                'order_id'       => $booking->get_order_id(),
                'customer_id'    => $booking->get_customer_id(),
                'customer_name'  => $customer_name,
                'customer_email' => $customer_email,
                'start_time'     => $booking->get_start(),
                'end_time'       => $booking->get_end(),
                'formatted_date' => $booking->get_start_date(),
                'qty'            => method_exists( $booking, 'get_qty' ) ? $booking->get_qty() : 1,
                'status'         => $booking->get_status(),
            );
        }

        return $structured_bookings;
    }
}
