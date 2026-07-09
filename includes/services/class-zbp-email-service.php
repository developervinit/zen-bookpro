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

    /**
     * Send waitlist invitation email to a customer.
     *
     * @param int $entry_id Waitlist entry ID.
     * @return void
     */
    public function send_waitlist_invitation( $entry_id ) {
        $customer_email = get_post_meta( $entry_id, '_customer_email', true );
        $customer_name  = get_post_meta( $entry_id, '_customer_name', true );
        $log_file = ZBP_PLUGIN_PATH . 'debug_log.txt';
        file_put_contents( $log_file, sprintf( "[%s] send_waitlist_invitation called for entry_id=%d, email=%s, name=%s\n", date('Y-m-d H:i:s'), $entry_id, $customer_email, $customer_name ), FILE_APPEND );
        $product_id     = get_post_meta( $entry_id, '_product_id', true );
        $event_date     = get_post_meta( $entry_id, '_event_date', true );
        $token          = get_post_meta( $entry_id, '_waitlist_token', true );
        $expires_at     = get_post_meta( $entry_id, '_expires_at', true );
        $invited_at     = get_post_meta( $entry_id, '_invited_at', true );

        $product    = wc_get_product( $product_id );
        $event_name = $product ? $product->get_name() : __( 'Event', 'zen-bookpro' );

        // Extract event time label
        $event_time   = 'N/A';
        $slot_service = new ZBP_Slot_Service();
        $slot_data    = $slot_service->get_slots_for_product( $product_id, $event_date, 'event', true );
        if ( ! empty( $slot_data['slots'] ) ) {
            $first_slot = $slot_data['slots'][0];
            $event_time = ! empty( $first_slot['label'] ) ? $first_slot['label'] : 'N/A';
        }

        // Build checkout link
        $checkout_url = add_query_arg(
            array(
                'zbp_waitlist_invite' => $entry_id,
                'zbp_token'           => $token,
            ),
            function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout' )
        );

        $formatted_expiry = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $expires_at ) );

        // Convert duration to localized label
        $duration_seconds = intval( $expires_at - $invited_at );
        if ( $duration_seconds <= 0 ) {
            $duration_seconds = 20 * MINUTE_IN_SECONDS; // Fallback
        }

        if ( $duration_seconds % DAY_IN_SECONDS === 0 ) {
            $value = $duration_seconds / DAY_IN_SECONDS;
            $unit_label = _n( 'day', 'days', $value, 'zen-bookpro' );
            $duration_label = sprintf( '%d %s', $value, $unit_label );
        } elseif ( $duration_seconds % HOUR_IN_SECONDS === 0 ) {
            $value = $duration_seconds / HOUR_IN_SECONDS;
            $unit_label = _n( 'hour', 'hours', $value, 'zen-bookpro' );
            $duration_label = sprintf( '%d %s', $value, $unit_label );
        } else {
            $value = round( $duration_seconds / MINUTE_IN_SECONDS );
            $unit_label = _n( 'minute', 'minutes', $value, 'zen-bookpro' );
            $duration_label = sprintf( '%d %s', $value, $unit_label );
        }

        $to      = $customer_email;
        $subject = sprintf( __( 'Invitation: Spot available for %s', 'zen-bookpro' ), $event_name );

        $message  = sprintf( __( 'Hi %s,', 'zen-bookpro' ), $customer_name ) . "\r\n\r\n";
        $message .= sprintf( __( 'Good news! A seat has become available for "%s".', 'zen-bookpro' ), $event_name ) . "\r\n\r\n";
        $message .= __( 'Event Details:', 'zen-bookpro' ) . "\r\n";
        $message .= sprintf( __( '- Event: %s', 'zen-bookpro' ), $event_name ) . "\r\n";
        $message .= sprintf( __( '- Date: %s', 'zen-bookpro' ), $event_date ) . "\r\n";
        $message .= sprintf( __( '- Time: %s', 'zen-bookpro' ), $event_time ) . "\r\n\r\n";
        $message .= sprintf( __( 'Please note that you have a %s reservation window to complete your booking. After this time, your invitation will expire, and the seat will be offered to the next person in line.', 'zen-bookpro' ), $duration_label ) . "\r\n\r\n";
        $message .= sprintf( __( 'Invitation Expires: %s', 'zen-bookpro' ), $formatted_expiry ) . "\r\n\r\n";
        $message .= __( 'Click the link below to complete your booking (1-click checkout):', 'zen-bookpro' ) . "\r\n";
        $message .= $checkout_url . "\r\n\r\n";
        $message .= __( 'Thank you,', 'zen-bookpro' ) . "\r\n";
        $message .= get_bloginfo( 'name' );

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        $sent_status = wp_mail( $to, $subject, $message, $headers );
        file_put_contents( $log_file, sprintf( "[%s] wp_mail result: %s\n", date('Y-m-d H:i:s'), $sent_status ? 'success' : 'failed' ), FILE_APPEND );
    }
}
