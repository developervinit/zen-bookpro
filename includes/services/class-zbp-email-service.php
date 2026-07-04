<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Email_Service {
    /**
     * Register hook listener.
     *
     * @return void
     */
    public function register() {
        add_action( 'zbp_event_cancelled', array( $this, 'handle_event_cancellation' ), 10, 3 );
    }

    /**
     * Handle event cancellation email dispatch.
     *
     * @param int    $product_id            Product ID.
     * @param string $date                  Date of the event.
     * @param array  $cancelled_booking_ids Array of cancelled booking IDs.
     * @return void
     */
    public function handle_event_cancellation( $product_id, $date, $cancelled_booking_ids ) {
        if ( empty( $cancelled_booking_ids ) ) {
            return;
        }

        $booking_service = new ZBP_Booking_Service();
        $product         = wc_get_product( $product_id );
        $product_name    = $product ? $product->get_name() : __( 'Event', 'zen-bookpro' );

        $admin_bookings_info = array();

        // 1. Loop and send email to each customer
        foreach ( $cancelled_booking_ids as $booking_id ) {
            $booking_data = $booking_service->get_booking_by_id( $booking_id );
            if ( ! $booking_data ) {
                continue;
            }

            $admin_bookings_info[] = $booking_data;

            if ( ! empty( $booking_data['customer_email'] ) ) {
                $this->send_customer_email( $booking_data, $product_name );
            }
        }

        // 2. Send summary email to site administrator
        $this->send_admin_email( $product_name, $date, $admin_bookings_info );
    }

    /**
     * Send email to a customer notifying them of the cancellation.
     *
     * @param array  $booking_data Booking information.
     * @param string $product_name Product/Event name.
     * @return void
     */
    private function send_customer_email( $booking_data, $product_name ) {
        $to      = $booking_data['customer_email'];
        $subject = sprintf( __( 'Cancelled: %s', 'zen-bookpro' ), $product_name );

        $message  = sprintf( __( 'Hi %s,', 'zen-bookpro' ), $booking_data['customer_name'] ) . "\r\n\r\n";
        $message .= sprintf( __( 'We regret to inform you that the event "%s" on %s has been cancelled by the administrator.', 'zen-bookpro' ), $product_name, $booking_data['formatted_date'] ) . "\r\n\r\n";
        $message .= sprintf( __( 'Booking Details:', 'zen-bookpro' ) ) . "\r\n";
        $message .= sprintf( __( '- Booking ID: #%d', 'zen-bookpro' ), $booking_data['booking_id'] ) . "\r\n";
        $message .= sprintf( __( '- Event: %s', 'zen-bookpro' ), $product_name ) . "\r\n";
        $message .= sprintf( __( '- Date/Time: %s', 'zen-bookpro' ), $booking_data['formatted_date'] ) . "\r\n\r\n";
        $message .= __( 'If any refunds or credits apply, they will be processed shortly.', 'zen-bookpro' ) . "\r\n\r\n";
        $message .= __( 'Thank you for your understanding.', 'zen-bookpro' ) . "\r\n";
        $message .= get_bloginfo( 'name' );

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        wp_mail( $to, $subject, $message, $headers );
    }

    /**
     * Send cancellation summary email to the site administrator.
     *
     * @param string $product_name Product/Event name.
     * @param string $date         Date of the event.
     * @param array  $bookings     Array of booking data.
     * @return void
     */
    private function send_admin_email( $product_name, $date, $bookings ) {
        $to      = get_option( 'admin_email' );
        $subject = sprintf( __( 'Event Cancelled: %s (%s)', 'zen-bookpro' ), $product_name, $date );

        $message  = sprintf( __( 'The event "%s" scheduled for %s has been cancelled.', 'zen-bookpro' ), $product_name, $date ) . "\r\n\r\n";
        $message .= sprintf( __( 'Total bookings affected: %d', 'zen-bookpro' ), count( $bookings ) ) . "\r\n\r\n";

        if ( ! empty( $bookings ) ) {
            $message .= __( 'Affected Bookings List:', 'zen-bookpro' ) . "\r\n";
            $message .= str_repeat( '-', 40 ) . "\r\n";
            foreach ( $bookings as $booking ) {
                $message .= sprintf( __( 'Booking ID: #%d', 'zen-bookpro' ), $booking['booking_id'] ) . "\r\n";
                $message .= sprintf( __( 'Customer: %s (%s)', 'zen-bookpro' ), $booking['customer_name'], $booking['customer_email'] ) . "\r\n";
                $message .= sprintf( __( 'Spots Booked: %d', 'zen-bookpro' ), $booking['qty'] ) . "\r\n";
                $message .= str_repeat( '-', 40 ) . "\r\n";
            }
        }

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        wp_mail( $to, $subject, $message, $headers );
    }
}
