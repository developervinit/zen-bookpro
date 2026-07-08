<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Waitlist_Service {

    /**
     * Cache to prevent duplicate processing of cancellations in the same request thread.
     *
     * @var array
     */
    private static $processed_bookings = array();

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register() {
        // CPT Registration
        add_action( 'init', array( $this, 'register_post_type' ) );

        // AJAX Actions
        add_action( 'wp_ajax_zbp_join_waitlist', array( $this, 'ajax_join_waitlist' ) );
        add_action( 'wp_ajax_zbp_leave_waitlist', array( $this, 'ajax_leave_waitlist' ) );

        // Admin Custom columns for waitlist management
        add_filter( 'manage_zbp_waitlist_posts_columns', array( $this, 'manage_waitlist_columns' ) );
        add_action( 'manage_zbp_waitlist_posts_custom_column', array( $this, 'manage_waitlist_custom_column' ), 10, 2 );

        // Seat availability listeners
        add_action( 'woocommerce_booking_status_changed', array( $this, 'handle_booking_status_change' ), 10, 3 );
        add_action( 'trashed_post', array( $this, 'handle_booking_trashed' ) );
        add_action( 'zbp_waitlist_prepare_invitations', array( $this, 'process_invitations' ), 10, 4 );

        // Cart / Checkout validation
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 10, 2 );
        add_action( 'template_redirect', array( $this, 'handle_book_now_redirect' ) );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
        add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_items' ) );

        // Booking completion listeners
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );
        add_action( 'woocommerce_new_booking', array( $this, 'handle_new_booking' ) );
    }

    /**
     * Register the Custom Post Type for Waitlist.
     *
     * @return void
     */
    public function register_post_type() {
        $labels = array(
            'name'               => _x( 'Waitlists', 'post type general name', 'zen-bookpro' ),
            'singular_name'      => _x( 'Waitlist Entry', 'post type singular name', 'zen-bookpro' ),
            'menu_name'          => _x( 'Waitlists', 'admin menu', 'zen-bookpro' ),
            'name_admin_bar'     => _x( 'Waitlist Entry', 'add new on admin bar', 'zen-bookpro' ),
            'add_new'            => _x( 'Add New', 'waitlist', 'zen-bookpro' ),
            'add_new_item'       => __( 'Add New Waitlist Entry', 'zen-bookpro' ),
            'new_item'           => __( 'New Waitlist Entry', 'zen-bookpro' ),
            'edit_item'          => __( 'Edit Waitlist Entry', 'zen-bookpro' ),
            'view_item'          => __( 'View Waitlist Entry', 'zen-bookpro' ),
            'all_items'          => __( 'All Waitlist Entries', 'zen-bookpro' ),
            'search_items'       => __( 'Search Waitlist Entries', 'zen-bookpro' ),
            'not_found'          => __( 'No waitlist entries found.', 'zen-bookpro' ),
            'not_found_in_trash' => __( 'No waitlist entries found in Trash.', 'zen-bookpro' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=wc_booking', // Nest under WooCommerce Bookings menu
            'query_var'          => true,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array( 'title' ),
        );

        register_post_type( 'zbp_waitlist', $args );
    }

    /**
     * Check if a user is currently active on the waitlist for a specific product and date.
     *
     * @param int    $user_id    WordPress user ID.
     * @param int    $product_id Product ID.
     * @param string $date       Event date (Y-m-d).
     * @return bool
     */
    public function is_user_on_waitlist( $user_id, $product_id, $date ) {
        $entry = $this->get_active_waitlist_entry( $user_id, $product_id, $date );
        return ! empty( $entry );
    }

    /**
     * Get active waitlist entry for user/product/date.
     * Active means status is 'waiting' or 'invited'.
     *
     * @param int    $user_id    WordPress user ID.
     * @param int    $product_id Product ID.
     * @param string $date       Event date (Y-m-d).
     * @return WP_Post|null
     */
    public function get_active_waitlist_entry( $user_id, $product_id, $date ) {
        $args = array(
            'post_type'      => 'zbp_waitlist',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => '_customer_id',
                    'value' => $user_id,
                ),
                array(
                    'key'   => '_product_id',
                    'value' => $product_id,
                ),
                array(
                    'key'   => '_event_date',
                    'value' => $date,
                ),
                array(
                    'key'     => '_waitlist_status',
                    'value'   => array( 'waiting', 'invited' ),
                    'compare' => 'IN',
                ),
            ),
        );

        $posts = get_posts( $args );
        return ! empty( $posts ) ? $posts[0] : null;
    }

    /**
     * Add user to waitlist.
     *
     * @param int    $user_id    User ID.
     * @param int    $product_id Product ID.
     * @param string $date       Event Date (Y-m-d).
     * @return int|WP_Error Entry Post ID or error.
     */
    public function add_to_waitlist( $user_id, $product_id, $date ) {
        if ( $this->is_user_on_waitlist( $user_id, $product_id, $date ) ) {
            return new WP_Error( 'duplicate_entry', __( 'You are already on the waitlist for this event date.', 'zen-bookpro' ) );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return new WP_Error( 'invalid_user', __( 'Invalid user ID.', 'zen-bookpro' ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'invalid_product', __( 'Invalid product ID.', 'zen-bookpro' ) );
        }

        // Count existing waiting entries to assign priority
        $existing_waiting = $this->get_waiting_entries_count( $product_id, $date );
        $priority = $existing_waiting + 1;

        $post_title = sprintf(
            '%s - %s (%s)',
            $user->display_name,
            $product->get_name(),
            $date
        );

        $post_id = wp_insert_post( array(
            'post_title'  => $post_title,
            'post_status' => 'publish',
            'post_type'   => 'zbp_waitlist',
        ) );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, '_customer_id', $user_id );
        update_post_meta( $post_id, '_customer_name', $user->display_name );
        update_post_meta( $post_id, '_customer_email', $user->user_email );
        update_post_meta( $post_id, '_product_id', $product_id );
        update_post_meta( $post_id, '_event_date', $date );
        update_post_meta( $post_id, '_waitlist_status', 'waiting' );
        update_post_meta( $post_id, '_waitlist_priority', $priority );
        update_post_meta( $post_id, '_joined_at', time() );

        return $post_id;
    }

    /**
     * Leave the waitlist.
     *
     * @param int    $user_id    User ID.
     * @param int    $product_id Product ID.
     * @param string $date       Event Date (Y-m-d).
     * @return bool|WP_Error
     */
    public function leave_waitlist( $user_id, $product_id, $date ) {
        $entry = $this->get_active_waitlist_entry( $user_id, $product_id, $date );

        if ( ! $entry ) {
            return new WP_Error( 'not_on_waitlist', __( 'You do not have an active waitlist entry for this event date.', 'zen-bookpro' ) );
        }

        // Update status to 'left'
        update_post_meta( $entry->ID, '_waitlist_status', 'left' );
        delete_post_meta( $entry->ID, '_waitlist_priority' );

        // Recalculate priorities for remaining waiting entries
        $this->recalculate_priorities( $product_id, $date );

        return true;
    }

    /**
     * Recalculate priorities for all waiting entries of a product and date.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Event Date (Y-m-d).
     * @return void
     */
    public function recalculate_priorities( $product_id, $date ) {
        $args = array(
            'post_type'      => 'zbp_waitlist',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => '_product_id',
                    'value' => $product_id,
                ),
                array(
                    'key'   => '_event_date',
                    'value' => $date,
                ),
                array(
                    'key'   => '_waitlist_status',
                    'value' => 'waiting',
                ),
            ),
            'meta_key'       => '_joined_at',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
        );

        $posts = get_posts( $args );

        if ( ! empty( $posts ) ) {
            $priority = 1;
            foreach ( $posts as $post ) {
                update_post_meta( $post->ID, '_waitlist_priority', $priority );
                $priority++;
            }
        }
    }

    /**
     * Get count of waiting entries for product and date.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Date.
     * @return int
     */
    private function get_waiting_entries_count( $product_id, $date ) {
        $args = array(
            'post_type'      => 'zbp_waitlist',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => '_product_id',
                    'value' => $product_id,
                ),
                array(
                    'key'   => '_event_date',
                    'value' => $date,
                ),
                array(
                    'key'   => '_waitlist_status',
                    'value' => 'waiting',
                ),
            ),
        );

        $posts = get_posts( $args );
        return count( $posts );
    }

    /**
     * AJAX Action Handler for joining waitlist.
     *
     * @return void
     */
    public function ajax_join_waitlist() {
        check_ajax_referer( 'zbp_get_slots', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in to join the waitlist.', 'zen-bookpro' ) ) );
        }

        $user_id    = get_current_user_id();
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $date       = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';

        if ( ! $product_id || empty( $date ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid parameters provided.', 'zen-bookpro' ) ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'zen-bookpro' ) ) );
        }

        $mode = get_post_meta( $product_id, '_zbp_booking_mode', true );
        if ( 'event' !== $mode ) {
            wp_send_json_error( array( 'message' => __( 'Waitlist is only supported for Event products.', 'zen-bookpro' ) ) );
        }

        $result = $this->add_to_waitlist( $user_id, $product_id, $date );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'  => __( 'You have successfully joined the waitlist.', 'zen-bookpro' ),
            'entry_id' => $result,
        ) );
    }

    /**
     * AJAX Action Handler for leaving waitlist.
     *
     * @return void
     */
    public function ajax_leave_waitlist() {
        check_ajax_referer( 'zbp_get_slots', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zen-bookpro' ) ) );
        }

        $user_id    = get_current_user_id();
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $date       = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';

        if ( ! $product_id || empty( $date ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'zen-bookpro' ) ) );
        }

        $result = $this->leave_waitlist( $user_id, $product_id, $date );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'You have successfully left the waitlist.', 'zen-bookpro' ),
        ) );
    }

    /**
     * Define custom columns for Admin waitlist list view.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function manage_waitlist_columns( $columns ) {
        $new_columns = array(
            'cb'                => $columns['cb'],
            'title'             => $columns['title'],
            'customer'          => __( 'Customer', 'zen-bookpro' ),
            'product'           => __( 'Event Product', 'zen-bookpro' ),
            'date'              => __( 'Event Date', 'zen-bookpro' ),
            'waitlist_status'   => __( 'Status', 'zen-bookpro' ),
            'waitlist_priority' => __( 'Priority', 'zen-bookpro' ),
            'joined_at'         => __( 'Joined At', 'zen-bookpro' ),
        );
        return $new_columns;
    }

    /**
     * Render values for custom columns in Admin waitlist list view.
     *
     * @param string $column  Column key.
     * @param int    $post_id Post ID.
     * @return void
     */
    public function manage_waitlist_custom_column( $column, $post_id ) {
        switch ( $column ) {
            case 'customer':
                $name  = get_post_meta( $post_id, '_customer_name', true );
                $email = get_post_meta( $post_id, '_customer_email', true );
                echo '<strong>' . esc_html( $name ) . '</strong><br><small>' . esc_html( $email ) . '</small>';
                break;
            case 'product':
                $product_id = get_post_meta( $post_id, '_product_id', true );
                $product    = wc_get_product( $product_id );
                if ( $product ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $product_id ) ) . '">' . esc_html( $product->get_name() ) . '</a>';
                } else {
                    echo '#' . esc_html( $product_id );
                }
                break;
            case 'date':
                echo esc_html( get_post_meta( $post_id, '_event_date', true ) );
                break;
            case 'waitlist_priority':
                $status = get_post_meta( $post_id, '_waitlist_status', true );
                if ( 'waiting' === $status ) {
                    $priority = get_post_meta( $post_id, '_waitlist_priority', true );
                    echo '#' . esc_html( $priority ? $priority : '—' );
                } else {
                    echo '—';
                }
                break;
            case 'waitlist_status':
                $status       = get_post_meta( $post_id, '_waitlist_status', true );
                $status_label = ucwords( $status );
                $badge_style  = 'display: inline-block; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 11px;';

                if ( 'waiting' === $status ) {
                    echo '<span style="' . $badge_style . ' background: #e3f2fd; color: #1565c0;">' . esc_html( $status_label ) . '</span>';
                } elseif ( 'left' === $status ) {
                    echo '<span style="' . $badge_style . ' background: #ffebee; color: #b71c1c;">' . esc_html( $status_label ) . '</span>';
                } else {
                    echo '<span style="' . $badge_style . ' background: #eee; color: #555;">' . esc_html( $status_label ) . '</span>';
                }
                break;
            case 'joined_at':
                $joined = get_post_meta( $post_id, '_joined_at', true );
                echo esc_html( $joined ? wp_date( get_option( 'date_format' ) . ' H:i', intval( $joined ) ) : '—' );
                break;
        }
    }

    /**
     * Listen to booking status changes to detect cancellations and find next waitlist entries.
     *
     * @param int    $booking_id Booking ID.
     * @param string $old_status Old status.
     * @param string $new_status New status.
     * @return void
     */
    public function handle_booking_status_change( $booking_id, $old_status, $new_status ) {
        $cancelled_statuses = array( 'cancelled', 'trash' );
        if ( ! in_array( $new_status, $cancelled_statuses, true ) ) {
            return;
        }

        $booking = get_wc_booking( $booking_id );
        if ( ! $booking || ! is_a( $booking, 'WC_Booking' ) ) {
            return;
        }

        $this->process_cancellation( $booking );
    }

    /**
     * Handle trashing a booking post.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function handle_booking_trashed( $post_id ) {
        if ( 'wc_booking' !== get_post_type( $post_id ) ) {
            return;
        }

        $booking = get_wc_booking( $post_id );
        if ( ! $booking || ! is_a( $booking, 'WC_Booking' ) ) {
            return;
        }

        $this->process_cancellation( $booking );
    }

    /**
     * Process invitations for the next eligible waitlist entries.
     *
     * @param array  $eligible_entries Array of eligible waitlist WP_Post objects.
     * @param int    $product_id       Product ID.
     * @param string $event_date       Event date (Y-m-d).
     * @param int    $available_seats  Available seats count.
     * @return void
     */
    public function process_invitations( $eligible_entries, $product_id, $event_date, $available_seats ) {
        if ( empty( $eligible_entries ) ) {
            return;
        }

        $email_service = new ZBP_Email_Service();

        foreach ( $eligible_entries as $entry ) {
            if ( ! $entry || ! is_a( $entry, 'WP_Post' ) ) {
                continue;
            }

            $entry_id = $entry->ID;

            // Double check status to prevent race conditions
            $status = get_post_meta( $entry_id, '_waitlist_status', true );
            if ( 'waiting' !== $status ) {
                continue;
            }

            // Generate secure token
            $token = wp_generate_password( 32, false );

            // Compute timestamps
            $invited_at = time();
            $expires_at = $invited_at + ( 20 * MINUTE_IN_SECONDS );

            // Update meta-data
            update_post_meta( $entry_id, '_waitlist_status', 'invited' );
            update_post_meta( $entry_id, '_invited_at', $invited_at );
            update_post_meta( $entry_id, '_expires_at', $expires_at );
            update_post_meta( $entry_id, '_waitlist_token', $token );

            // Dispatch invitation email
            $email_service->send_waitlist_invitation( $entry_id );
        }
    }

    /**
     * Process cancellation for a booking object.
     *
     * @param WC_Booking $booking Booking object.
     * @return void
     */
    private function process_cancellation( $booking ) {
        $booking_id = $booking->get_id();
        if ( in_array( $booking_id, self::$processed_bookings, true ) ) {
            return;
        }
        self::$processed_bookings[] = $booking_id;

        $product_id = $booking->get_product_id();
        $product    = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        // Check if the product is in 'event' booking mode
        $mode = get_post_meta( $product_id, '_zbp_booking_mode', true );
        if ( 'event' !== $mode ) {
            return;
        }

        // Extract the event date in Y-m-d format
        $start_timestamp = $booking->get_start();
        if ( ! $start_timestamp ) {
            return;
        }
        $event_date = wp_date( 'Y-m-d', $start_timestamp );

        // 1. Calculate how many seats are now available
        $available_seats = $this->calculate_available_seats( $product_id, $event_date );

        if ( $available_seats <= 0 ) {
            return;
        }

        // 2. Identify the next eligible customers from the waitlist
        $eligible_entries = $this->get_next_eligible_waitlist_entries( $product_id, $event_date, $available_seats );

        if ( empty( $eligible_entries ) ) {
            return;
        }

        // 3. Fire custom WordPress action to prepare these entries (modular hook for step 3)
        do_action( 'zbp_waitlist_prepare_invitations', $eligible_entries, $product_id, $event_date, $available_seats );
    }

    /**
     * Calculate the number of conceptually available seats for an Event product on a specific date.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Date in Y-m-d format.
     * @return int Available seats.
     */
    public function calculate_available_seats( $product_id, $date ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return 0;
        }

        // Get max spots
        $max_spots = (int) $product->get_meta( '_zbp_max_spots' );
        if ( $max_spots <= 0 ) {
            // Fallback to WooCommerce Bookings capacity if meta is not set
            $max_spots = method_exists( $product, 'get_qty' ) ? (int) $product->get_qty() : 1;
        }

        // Get active booked seats on this date
        $booked_spots = $this->get_active_bookings_count( $product_id, $date );

        // Get reserved waitlist spots (invited status)
        $reserved_spots = $this->get_reserved_waitlist_count( $product_id, $date );

        $available = $max_spots - ( $booked_spots + $reserved_spots );

        return max( 0, $available );
    }

    /**
     * Count active bookings for product on date.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Date in Y-m-d format.
     * @return int
     */
    private function get_active_bookings_count( $product_id, $date ) {
        $date_from = strtotime( $date . ' 00:00:00' );
        $date_to   = strtotime( $date . ' 23:59:59' );

        // Active booking statuses
        $active_statuses = array( 'confirmed', 'paid', 'complete', 'unpaid', 'pending-confirmation', 'on-hold' );
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

        // Since it's Event (Single Slot), we count total qty of slots booked
        $count = 0;
        foreach ( $bookings as $booking ) {
            if ( $booking && is_a( $booking, 'WC_Booking' ) ) {
                if ( in_array( $booking->get_status(), array( 'cancelled', 'trash' ), true ) ) {
                    continue;
                }
                $qty = method_exists( $booking, 'get_qty' ) ? (int) $booking->get_qty() : 1;
                $count += $qty;
            }
        }

        return $count;
    }

    /**
     * Count reserved waitlist spots (invited) for product on date.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Date in Y-m-d format.
     * @return int
     */
    private function get_reserved_waitlist_count( $product_id, $date ) {
        $args = array(
            'post_type'      => 'zbp_waitlist',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => '_product_id',
                    'value' => $product_id,
                ),
                array(
                    'key'   => '_event_date',
                    'value' => $date,
                ),
                array(
                    'key'   => '_waitlist_status',
                    'value' => 'invited',
                ),
            ),
        );

        $posts = get_posts( $args );
        return count( $posts );
    }

    /**
     * Query next N eligible waitlist entries.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Date in Y-m-d format.
     * @param int    $limit      Max number of entries to retrieve.
     * @return array Array of WP_Post objects.
     */
    public function get_next_eligible_waitlist_entries( $product_id, $date, $limit ) {
        if ( $limit <= 0 ) {
            return array();
        }

        $args = array(
            'post_type'      => 'zbp_waitlist',
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => '_product_id',
                    'value' => $product_id,
                ),
                array(
                    'key'   => '_event_date',
                    'value' => $date,
                ),
                array(
                    'key'   => '_waitlist_status',
                    'value' => 'waiting',
                ),
            ),
            'meta_key'       => '_waitlist_priority',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
        );

        return get_posts( $args );
    }

    /**
     * Add waitlist invite ID to cart item data.
     *
     * @param array $cart_item_data Cart item data.
     * @param int   $product_id     Product ID.
     * @param int   $variation_id   Variation ID.
     * @return array
     */
    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( isset( $_GET['zbp_waitlist_invite'] ) ) {
            $cart_item_data['zbp_waitlist_invite_id'] = absint( $_GET['zbp_waitlist_invite'] );
        } elseif ( isset( $_POST['zbp_waitlist_invite'] ) ) {
            $cart_item_data['zbp_waitlist_invite_id'] = absint( $_POST['zbp_waitlist_invite'] );
        }
        return $cart_item_data;
    }

    /**
     * Get cart item from session.
     *
     * @param array $cart_item Cart item.
     * @param array $values    Values from session.
     * @return array
     */
    public function get_cart_item_from_session( $cart_item, $values ) {
        if ( isset( $values['zbp_waitlist_invite_id'] ) ) {
            $cart_item['zbp_waitlist_invite_id'] = $values['zbp_waitlist_invite_id'];
        }
        return $cart_item;
    }

    /**
     * Handle the secure Book Now checkout redirection.
     *
     * @return void
     */
    public function handle_book_now_redirect() {
        if ( is_admin() ) {
            return;
        }

        $entry_id = isset( $_GET['zbp_waitlist_invite'] ) ? absint( $_GET['zbp_waitlist_invite'] ) : 0;
        $token    = isset( $_GET['zbp_token'] ) ? sanitize_text_field( $_GET['zbp_token'] ) : '';

        if ( ! $entry_id || empty( $token ) ) {
            return;
        }

        // Run validation
        $validation = $this->validate_invitation( $entry_id, $token );
        if ( is_wp_error( $validation ) ) {
            wc_add_notice( $validation->get_error_message(), 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $product_id = get_post_meta( $entry_id, '_product_id', true );
        $event_date = get_post_meta( $entry_id, '_event_date', true );

        // Extract slot date parameters
        $date_timestamp = strtotime( $event_date );
        if ( ! $date_timestamp ) {
            wc_add_notice( __( 'Invalid event date.', 'zen-bookpro' ), 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $year  = date( 'Y', $date_timestamp );
        $month = date( 'm', $date_timestamp );
        $day   = date( 'd', $date_timestamp );

        // Query the first slot start time (e.g. "09:00")
        $slot_time    = '00:00';
        $slot_service = new ZBP_Slot_Service();
        $slot_data    = $slot_service->get_slots_for_product( $product_id, $event_date, 'event', true );
        if ( ! empty( $slot_data['slots'] ) ) {
            $first_slot = $slot_data['slots'][0];
            if ( ! empty( $first_slot['timestamp'] ) ) {
                $slot_time = wp_date( 'H:i', $first_slot['timestamp'] );
            }
        }

        // Empty existing cart
        WC()->cart->empty_cart();

        // Build WooCommerce Bookings fields for cart
        $cart_item_data = array(
            'zbp_waitlist_invite_id' => $entry_id,
            'booking' => array(
                '_year'  => $year,
                '_month' => $month,
                '_day'   => $day,
                '_date'  => $event_date,
                '_time'  => $slot_time,
            )
        );

        // Add to cart programmatically
        $added = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );

        if ( ! $added ) {
            wc_add_notice( __( 'Failed to add the booking to your cart. Please try again.', 'zen-bookpro' ), 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Redirect directly to checkout
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    /**
     * Validate a waitlist invitation.
     *
     * @param int    $entry_id Waitlist entry ID.
     * @param string $token    Secure token.
     * @return true|WP_Error
     */
    public function validate_invitation( $entry_id, $token ) {
        $post = get_post( $entry_id );
        if ( ! $post || 'zbp_waitlist' !== $post->post_type ) {
            return new WP_Error( 'invalid_waitlist', __( 'Invalid invitation link.', 'zen-bookpro' ) );
        }

        $status = get_post_meta( $entry_id, '_waitlist_status', true );
        if ( 'invited' !== $status ) {
            return new WP_Error( 'invalid_status', __( 'This invitation is no longer active.', 'zen-bookpro' ) );
        }

        $stored_token = get_post_meta( $entry_id, '_waitlist_token', true );
        if ( empty( $stored_token ) || $stored_token !== $token ) {
            return new WP_Error( 'invalid_token', __( 'Invalid or tampered invitation link.', 'zen-bookpro' ) );
        }

        $customer_id = (int) get_post_meta( $entry_id, '_customer_id', true );
        if ( get_current_user_id() !== $customer_id ) {
            return new WP_Error( 'invalid_owner', __( 'This invitation does not belong to you.', 'zen-bookpro' ) );
        }

        $expires_at = (int) get_post_meta( $entry_id, '_expires_at', true );
        if ( time() >= $expires_at ) {
            return new WP_Error( 'expired_invitation', __( 'This invitation has expired.', 'zen-bookpro' ) );
        }

        // Verify if a seat is still physically available
        $product_id = get_post_meta( $entry_id, '_product_id', true );
        $event_date = get_post_meta( $entry_id, '_event_date', true );

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'invalid_product', __( 'Product not found.', 'zen-bookpro' ) );
        }

        $max_spots = (int) $product->get_meta( '_zbp_max_spots' );
        if ( $max_spots <= 0 ) {
            $max_spots = method_exists( $product, 'get_qty' ) ? (int) $product->get_qty() : 1;
        }

        $booked_spots = $this->get_active_bookings_count( $product_id, $event_date );
        if ( $booked_spots >= $max_spots ) {
            return new WP_Error( 'no_seats', __( 'This event has already been filled.', 'zen-bookpro' ) );
        }

        return true;
    }

    /**
     * Validate product addition to cart.
     * Block other users if the only available seats are reserved for waitlist invitations.
     *
     * @param bool $passed     Whether validation passed.
     * @param int  $product_id Product ID.
     * @param int  $quantity   Quantity.
     * @return bool
     */
    public function validate_add_to_cart( $passed, $product_id, $quantity ) {
        if ( ! $passed ) {
            return $passed;
        }

        $mode = get_post_meta( $product_id, '_zbp_booking_mode', true );
        if ( 'event' !== $mode ) {
            return $passed;
        }

        // Get the booking date from POST parameters
        $year  = isset( $_POST['wc_bookings_field_start_date_year'] ) ? absint( $_POST['wc_bookings_field_start_date_year'] ) : 0;
        $month = isset( $_POST['wc_bookings_field_start_date_month'] ) ? absint( $_POST['wc_bookings_field_start_date_month'] ) : 0;
        $day   = isset( $_POST['wc_bookings_field_start_date_day'] ) ? absint( $_POST['wc_bookings_field_start_date_day'] ) : 0;

        if ( ! $year || ! $month || ! $day ) {
            return $passed;
        }

        $event_date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

        // If the current user has a valid waitlist invite ID in their session/URL, let them pass
        $invite_id = isset( $_GET['zbp_waitlist_invite'] ) ? absint( $_GET['zbp_waitlist_invite'] ) : 0;
        if ( ! $invite_id && isset( $_POST['zbp_waitlist_invite'] ) ) {
            $invite_id = absint( $_POST['zbp_waitlist_invite'] );
        }

        // If they are validating an invite, check if it's correct
        if ( $invite_id ) {
            $invite_product_id = get_post_meta( $invite_id, '_product_id', true );
            $invite_event_date = get_post_meta( $invite_id, '_event_date', true );
            $invite_customer   = (int) get_post_meta( $invite_id, '_customer_id', true );

            if ( $invite_product_id === $product_id && $invite_event_date === $event_date && get_current_user_id() === $invite_customer ) {
                return $passed; // Let the invited user book
            }
        }

        // For all other users, check if waitlist invitations are active
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return $passed;
        }

        $max_spots = (int) $product->get_meta( '_zbp_max_spots' );
        if ( $max_spots <= 0 ) {
            $max_spots = method_exists( $product, 'get_qty' ) ? (int) $product->get_qty() : 1;
        }

        $booked_spots = $this->get_active_bookings_count( $product_id, $event_date );

        // Reserved waitlist spots
        $reserved_spots = $this->get_reserved_waitlist_count( $product_id, $event_date );

        if ( ( $booked_spots + $reserved_spots ) >= $max_spots ) {
            // Block addition because the vacancy is reserved for the waitlist invitation
            wc_add_notice( __( 'This seat is currently reserved for a waitlist invitation.', 'zen-bookpro' ), 'error' );
            return false;
        }

        return $passed;
    }

    /**
     * Perform final validation checks on cart items.
     *
     * @return void
     */
    public function validate_cart_items() {
        if ( is_admin() || ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['zbp_waitlist_invite_id'] ) ) {
                $invite_id = $cart_item['zbp_waitlist_invite_id'];
                $token     = get_post_meta( $invite_id, '_waitlist_token', true );

                $validation = $this->validate_invitation( $invite_id, $token );
                if ( is_wp_error( $validation ) ) {
                    // Remove from cart and show error notice
                    WC()->cart->remove_cart_item( $cart_item_key );
                    wc_add_notice( $validation->get_error_message(), 'error' );
                }
            }
        }
    }

    /**
     * Add waitlist invite ID to order line item meta.
     *
     * @param WC_Order_Item_Product $item          Order item.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values        Cart item values.
     * @param WC_Order              $order         Order object.
     * @return void
     */
    public function add_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['zbp_waitlist_invite_id'] ) ) {
            $item->add_meta_data( '_zbp_waitlist_invite_id', absint( $values['zbp_waitlist_invite_id'] ), true );
        }
    }

    /**
     * Handle successful booking creation.
     *
     * @param int $booking_id Booking ID.
     * @return void
     */
    public function handle_new_booking( $booking_id ) {
        $booking = get_wc_booking( $booking_id );
        if ( ! $booking || ! is_a( $booking, 'WC_Booking' ) ) {
            return;
        }

        // Get the order item associated with the booking
        $order_item_id = $booking->get_order_item_id();
        if ( ! $order_item_id ) {
            return;
        }

        $invite_id = wc_get_order_item_meta( $order_item_id, '_zbp_waitlist_invite_id', true );
        if ( ! $invite_id ) {
            return;
        }

        $this->complete_waitlist_booking( $invite_id, $booking_id );
    }

    /**
     * Complete the waitlist booking process.
     *
     * @param int $invite_id  Waitlist entry ID.
     * @param int $booking_id Booking ID.
     * @return void
     */
    private function complete_waitlist_booking( $invite_id, $booking_id ) {
        $status = get_post_meta( $invite_id, '_waitlist_status', true );
        if ( 'invited' !== $status ) {
            return;
        }

        // Get product and date before clearing priorities
        $product_id = (int) get_post_meta( $invite_id, '_product_id', true );
        $event_date = get_post_meta( $invite_id, '_event_date', true );

        // Update status, link booking ID, and clear priority
        update_post_meta( $invite_id, '_waitlist_status', 'booked' );
        update_post_meta( $invite_id, '_booking_id', $booking_id );
        delete_post_meta( $invite_id, '_waitlist_priority' );

        // Recalculate priorities of the remaining waiting queue
        if ( $product_id && $event_date ) {
            $this->recalculate_priorities( $product_id, $event_date );
        }
    }
}
