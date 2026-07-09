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
        add_action( 'wp_ajax_nopriv_zbp_join_waitlist', array( $this, 'ajax_login_required' ) );
        add_action( 'wp_ajax_zbp_leave_waitlist', array( $this, 'ajax_leave_waitlist' ) );

        // Admin Custom columns for waitlist management
        add_filter( 'manage_zbp_waitlist_posts_columns', array( $this, 'manage_waitlist_columns' ) );
        add_action( 'manage_zbp_waitlist_posts_custom_column', array( $this, 'manage_waitlist_custom_column' ), 10, 2 );
        add_action( 'admin_post_zbp_clear_class_waitlist', array( $this, 'handle_clear_class_waitlist' ) );
        add_action( 'admin_notices', array( $this, 'render_waitlist_admin_notices' ) );

        // Seat availability listeners
        add_action( 'woocommerce_booking_status_changed', array( $this, 'handle_booking_status_change' ), 10, 3 );
        add_action( 'trashed_post', array( $this, 'handle_booking_trashed' ) );
        add_action( 'zbp_waitlist_prepare_invitations', array( $this, 'process_invitations' ), 10, 4 );

        // Cart / Checkout validation
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 10, 2 );
        add_action( 'template_redirect', array( $this, 'handle_book_now_redirect' ) );
        add_action( 'template_redirect', array( $this, 'handle_decline_waitlist_invitation' ) );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
        add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_items' ) );

        // Booking completion listeners
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );
        add_action( 'woocommerce_new_booking', array( $this, 'handle_new_booking' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_checkout_order_processed' ), 50, 3 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'handle_paid_order_status' ), 50, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'handle_paid_order_status' ), 50, 1 );

        // Action Scheduler Expiry Action
        add_action( 'zbp_waitlist_check_expiry', array( $this, 'handle_waitlist_expiry' ) );

        // Admin Read-only and Filter/Search handlers
        add_filter( 'post_row_actions', array( $this, 'remove_row_actions' ), 10, 2 );
        add_filter( 'bulk_actions-edit-zbp_waitlist', array( $this, 'remove_bulk_actions' ) );
        add_action( 'admin_init', array( $this, 'restrict_waitlist_editing' ) );
        add_action( 'restrict_manage_posts', array( $this, 'add_admin_filters' ) );
        add_action( 'pre_get_posts', array( $this, 'apply_admin_filters' ) );
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
            'capabilities'       => array(
                'create_posts' => 'do_not_allow', // Disable "Add New" button
            ),
            'map_meta_cap'       => true,
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

        if ( ! $this->is_session_full( $product_id, $date ) ) {
            return new WP_Error( 'session_not_full', __( 'Waitlist is only available when the session is full.', 'zen-bookpro' ) );
        }

        if ( $this->is_event_cancelled( $product_id, $date ) ) {
            return new WP_Error( 'event_cancelled', __( 'This event has been cancelled.', 'zen-bookpro' ) );
        }

        if ( $this->has_event_started( $product_id, $date ) ) {
            return new WP_Error( 'event_started', __( 'Waitlist is closed for this event.', 'zen-bookpro' ) );
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

        $was_invited = 'invited' === get_post_meta( $entry->ID, '_waitlist_status', true );

        // Update status to 'left'
        update_post_meta( $entry->ID, '_waitlist_status', 'left' );
        delete_post_meta( $entry->ID, '_waitlist_priority' );

        if ( $was_invited && function_exists( 'as_unschedule_action' ) ) {
            as_unschedule_action( 'zbp_waitlist_check_expiry', array( 'entry_id' => $entry->ID ) );
        }

        // Recalculate priorities for remaining waiting entries
        $this->recalculate_priorities( $product_id, $date );

        if ( $was_invited ) {
            $this->process_next_waitlist_invitation( $product_id, $date );
        }

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
     * AJAX response for logged-out waitlist requests.
     *
     * @return void
     */
    public function ajax_login_required() {
        check_ajax_referer( 'zbp_get_slots', 'nonce' );

        wp_send_json_error(
            array(
                'message'   => __( 'Please log in to join the waitlist.', 'zen-bookpro' ),
                'loggedOut' => true,
            ),
            401
        );
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

        $mode = get_post_meta( $product_id, '_zbp_product_mode', true );
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
            'cb'                 => $columns['cb'],
            'title'              => $columns['title'],
            'customer'           => __( 'Customer', 'zen-bookpro' ),
            'product'            => __( 'Event Product', 'zen-bookpro' ),
            'date'               => __( 'Event Date', 'zen-bookpro' ),
            'waitlist_status'    => __( 'Status', 'zen-bookpro' ),
            'waitlist_priority'  => __( 'Priority', 'zen-bookpro' ),
            'joined_at'          => __( 'Joined At', 'zen-bookpro' ),
            'invitation_sent_at' => __( 'Invitation Sent At', 'zen-bookpro' ),
            'invitation_expires' => __( 'Invitation Expires At', 'zen-bookpro' ),
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
                } elseif ( 'invited' === $status ) {
                    echo '<span style="' . $badge_style . ' background: #fff3e0; color: #e65100;">' . esc_html( $status_label ) . '</span>';
                } elseif ( 'booked' === $status ) {
                    echo '<span style="' . $badge_style . ' background: #e8f5e9; color: #2e7d32;">' . esc_html( $status_label ) . '</span>';
                } elseif ( 'expired' === $status ) {
                    echo '<span style="' . $badge_style . ' background: #eceff1; color: #37474f;">' . esc_html( $status_label ) . '</span>';
                } elseif ( 'left' === $status ) {
                    echo '<span style="' . $badge_style . ' background: #ffebee; color: #b71c1c;">' . esc_html( $status_label ) . '</span>';
                } elseif ( 'cleared' === $status ) {
                    echo '<span style="' . $badge_style . ' background: #f3e5f5; color: #6a1b9a;">' . esc_html( $status_label ) . '</span>';
                } else {
                    echo '<span style="' . $badge_style . ' background: #eee; color: #555;">' . esc_html( $status_label ) . '</span>';
                }
                break;
            case 'joined_at':
                $joined = get_post_meta( $post_id, '_joined_at', true );
                echo esc_html( $joined ? wp_date( get_option( 'date_format' ) . ' H:i', intval( $joined ) ) : '—' );
                break;
            case 'invitation_sent_at':
                $sent = get_post_meta( $post_id, '_invited_at', true );
                echo esc_html( $sent ? wp_date( get_option( 'date_format' ) . ' H:i', intval( $sent ) ) : '—' );
                break;
            case 'invitation_expires':
                $expires = get_post_meta( $post_id, '_expires_at', true );
                echo esc_html( $expires ? wp_date( get_option( 'date_format' ) . ' H:i', intval( $expires ) ) : '—' );
                break;
        }
    }

    /**
     * Listen to booking status changes to detect cancellations and find next waitlist entries.
     *
     * @param string $old_status Old status.
     * @param string $new_status New status.
     * @param int    $booking_id Booking ID.
     * @return void
     */
    public function handle_booking_status_change( $old_status, $new_status, $booking_id ) {
        $this->log( sprintf( "handle_booking_status_change called: old_status=%s, new_status=%s, booking_id=%d", $old_status, $new_status, $booking_id ) );
        $cancelled_statuses = array( 'cancelled', 'trash' );
        if ( ! in_array( $new_status, $cancelled_statuses, true ) ) {
            $this->log( "Exiting handle_booking_status_change: new_status not cancelled or trash" );
            return;
        }

        $booking = get_wc_booking( $booking_id );
        if ( ! $booking || ! is_a( $booking, 'WC_Booking' ) ) {
            $this->log( "Exiting handle_booking_status_change: booking not found or not WC_Booking instance" );
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
        $this->log( sprintf( "handle_booking_trashed called: post_id=%d", $post_id ) );
        if ( 'wc_booking' !== get_post_type( $post_id ) ) {
            $this->log( "Exiting handle_booking_trashed: post_type is not wc_booking" );
            return;
        }

        $booking = get_wc_booking( $post_id );
        if ( ! $booking || ! is_a( $booking, 'WC_Booking' ) ) {
            $this->log( "Exiting handle_booking_trashed: booking not found or not WC_Booking instance" );
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
        if ( $eligible_entries instanceof WP_Post || is_numeric( $eligible_entries ) ) {
            $eligible_entries = array( $eligible_entries );
        } elseif ( ! is_array( $eligible_entries ) ) {
            $eligible_entries = array();
        }

        $this->log( sprintf( "process_invitations called: product_id=%d, event_date=%s, available_seats=%d, eligible_entries count=%d", $product_id, $event_date, $available_seats, count( $eligible_entries ) ) );
        if ( empty( $eligible_entries ) ) {
            $this->log( "process_invitations early exit: eligible_entries is empty" );
            return;
        }

        if ( $this->is_event_cancelled( $product_id, $event_date ) || $this->has_event_started( $product_id, $event_date ) ) {
            $this->log( "process_invitations early exit: event cancelled or started" );
            return;
        }

        $email_service = new ZBP_Email_Service();

        foreach ( $eligible_entries as $entry ) {
            if ( is_numeric( $entry ) ) {
                $entry = get_post( $entry );
            }

            if ( ! $entry || ! is_a( $entry, 'WP_Post' ) ) {
                $this->log( sprintf( "process_invitations: entry %s is not a valid WP_Post object", var_export( $entry, true ) ) );
                continue;
            }

            $entry_id = $entry->ID;
            $this->log( sprintf( "process_invitations: processing entry ID=%d", $entry_id ) );

            // Double check status to prevent race conditions
            $status = get_post_meta( $entry_id, '_waitlist_status', true );
            if ( 'waiting' !== $status ) {
                $this->log( sprintf( "process_invitations: entry ID=%d status is '%s', expected 'waiting'", $entry_id, $status ) );
                continue;
            }

            // Generate secure token
            $token = wp_generate_password( 32, false );

            // Compute timestamps
            $invited_at = time();

            $expiry_value = (int) get_option( 'zbp_waitlist_expiry_value', 20 );
            $expiry_unit  = get_option( 'zbp_waitlist_expiry_unit', 'minutes' );
            switch ( $expiry_unit ) {
                case 'hours':
                    $seconds = $expiry_value * HOUR_IN_SECONDS;
                    break;
                case 'days':
                    $seconds = $expiry_value * DAY_IN_SECONDS;
                    break;
                case 'minutes':
                default:
                    $seconds = $expiry_value * MINUTE_IN_SECONDS;
                    break;
            }
            $expires_at = $invited_at + $seconds;

            $this->log( sprintf( "process_invitations: updating entry ID=%d to status 'invited', expires_at=%d", $entry_id, $expires_at ) );

            // Update meta-data
            update_post_meta( $entry_id, '_waitlist_status', 'invited' );
            update_post_meta( $entry_id, '_invited_at', $invited_at );
            update_post_meta( $entry_id, '_expires_at', $expires_at );
            update_post_meta( $entry_id, '_waitlist_token', $token );

            // Schedule single expiry action via Action Scheduler
            if ( function_exists( 'as_schedule_single_action' ) ) {
                $this->log( sprintf( "process_invitations: scheduling expiry for entry ID=%d via Action Scheduler", $entry_id ) );
                as_schedule_single_action( $expires_at, 'zbp_waitlist_check_expiry', array( 'entry_id' => $entry_id ) );
            } else {
                $this->log( "process_invitations WARNING: as_schedule_single_action function does not exist" );
            }

            // Dispatch invitation email
            $this->log( sprintf( "process_invitations: sending invitation email to entry ID=%d", $entry_id ) );
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
        $this->log( sprintf( "process_cancellation: booking_id=%d", $booking_id ) );
        if ( in_array( $booking_id, self::$processed_bookings, true ) ) {
            $this->log( "process_cancellation early exit: booking already processed" );
            return;
        }
        self::$processed_bookings[] = $booking_id;

        $product_id = $booking->get_product_id();
        $product    = wc_get_product( $product_id );
        if ( ! $product ) {
            $this->log( "process_cancellation early exit: product not found" );
            return;
        }

        // Check if the product is in 'event' booking mode
        $mode = get_post_meta( $product_id, '_zbp_product_mode', true );
        $this->log( sprintf( "process_cancellation: product_id=%d, mode=%s", $product_id, $mode ) );
        if ( 'event' !== $mode ) {
            $this->log( "process_cancellation early exit: mode is not event" );
            return;
        }

        // Extract the event date in Y-m-d format
        $start_timestamp = $booking->get_start();
        $this->log( sprintf( "process_cancellation: start_timestamp=%s", var_export( $start_timestamp, true ) ) );
        if ( ! $start_timestamp ) {
            $this->log( "process_cancellation early exit: no start timestamp" );
            return;
        }
        $event_date = wp_date( 'Y-m-d', $start_timestamp );
        $this->log( sprintf( "process_cancellation: event_date=%s", $event_date ) );

        if ( $this->is_event_cancelled( $product_id, $event_date ) || $this->has_event_started( $product_id, $event_date ) ) {
            $this->log( "process_cancellation early exit: event cancelled or started" );
            return;
        }

        // 1. Calculate how many seats are now available
        $available_seats = $this->calculate_available_seats( $product_id, $event_date );
        $this->log( sprintf( "process_cancellation: available_seats calculated=%d", $available_seats ) );

        if ( $available_seats <= 0 ) {
            $this->log( "process_cancellation early exit: available_seats <= 0" );
            return;
        }

        // 2. Identify the next eligible customers from the waitlist
        $eligible_entries = $this->get_next_eligible_waitlist_entries( $product_id, $event_date, 1 );
        $this->log( sprintf( "process_cancellation: eligible_entries count=%d", count( $eligible_entries ) ) );

        if ( empty( $eligible_entries ) ) {
            $this->log( "process_cancellation early exit: eligible_entries is empty" );
            return;
        }

        // 3. Fire custom WordPress action to prepare these entries (modular hook for step 3)
        $this->log( "process_cancellation: firing zbp_waitlist_prepare_invitations action" );
        do_action( 'zbp_waitlist_prepare_invitations', $eligible_entries, $product_id, $event_date, $available_seats );
    }

    /**
     * Determine whether an Event session is full for waitlist eligibility.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Date in Y-m-d format.
     * @return bool
     */
    private function is_session_full( $product_id, $date ) {
        return $this->calculate_available_seats( $product_id, $date ) <= 0;
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
            $this->log( sprintf( "calculate_available_seats: product_id=%d not found", $product_id ) );
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
        $this->log( sprintf( "calculate_available_seats: product_id=%d, date=%s, max_spots=%d, booked_spots=%d, reserved_spots=%d, calculated_available=%d", $product_id, $date, $max_spots, $booked_spots, $reserved_spots, $available ) );

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
        $this->log( sprintf( "get_next_eligible_waitlist_entries: product_id=%d, date=%s, limit=%d", $product_id, $date, $limit ) );
        if ( $limit <= 0 ) {
            $this->log( "get_next_eligible_waitlist_entries early exit: limit <= 0" );
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

        $results = get_posts( $args );
        $this->log( sprintf( "get_next_eligible_waitlist_entries: query returned %d posts", count( $results ) ) );
        foreach ( $results as $r ) {
            $this->log( sprintf( " - eligible post ID=%d, customer_email=%s", $r->ID, get_post_meta( $r->ID, '_customer_email', true ) ) );
        }
        return $results;
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

        if ( $this->is_event_cancelled( $product_id, $event_date ) || $this->has_event_started( $product_id, $event_date ) ) {
            wc_add_notice( __( 'This waitlist invitation is no longer available.', 'zen-bookpro' ), 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Extract slot date parameters
        $date_timestamp = strtotime( $event_date );
        if ( ! $date_timestamp ) {
            wc_add_notice( __( 'Invalid event date.', 'zen-bookpro' ), 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $booking_posted_data = $this->build_booking_posted_data( $product_id, $event_date );
        if ( empty( $booking_posted_data ) ) {
            wc_add_notice( __( 'Unable to prepare this booking. Please contact the studio.', 'zen-bookpro' ), 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        foreach ( $booking_posted_data as $key => $value ) {
            $_POST[ $key ] = $value;
        }

        // Empty existing cart
        WC()->cart->empty_cart();

        // Let WooCommerce Bookings build the actual booking cart data from $_POST.
        $cart_item_data = array(
            'zbp_waitlist_invite_id' => $entry_id,
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
     * Handle secure decline links from waitlist invitation emails.
     *
     * @return void
     */
    public function handle_decline_waitlist_invitation() {
        if ( is_admin() || ! isset( $_GET['zbp_waitlist_decline'] ) ) {
            return;
        }

        $entry_id = absint( $_GET['zbp_waitlist_decline'] );
        $token    = isset( $_GET['zbp_token'] ) ? sanitize_text_field( wp_unslash( $_GET['zbp_token'] ) ) : '';

        $redirect_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );

        if ( ! $entry_id || empty( $token ) ) {
            wc_add_notice( __( 'Invalid waitlist decline link.', 'zen-bookpro' ), 'error' );
            wp_safe_redirect( $redirect_url );
            exit;
        }

        $post = get_post( $entry_id );
        if ( ! $post || 'zbp_waitlist' !== $post->post_type ) {
            wc_add_notice( __( 'Invalid waitlist decline link.', 'zen-bookpro' ), 'error' );
            wp_safe_redirect( $redirect_url );
            exit;
        }

        $status       = get_post_meta( $entry_id, '_waitlist_status', true );
        $stored_token = get_post_meta( $entry_id, '_waitlist_token', true );

        if ( 'invited' !== $status || empty( $stored_token ) || ! hash_equals( (string) $stored_token, (string) $token ) ) {
            wc_add_notice( __( 'This waitlist invitation is no longer active.', 'zen-bookpro' ), 'error' );
            wp_safe_redirect( $redirect_url );
            exit;
        }

        $product_id = (int) get_post_meta( $entry_id, '_product_id', true );
        $event_date = get_post_meta( $entry_id, '_event_date', true );

        update_post_meta( $entry_id, '_waitlist_status', 'left' );
        delete_post_meta( $entry_id, '_waitlist_priority' );

        if ( function_exists( 'as_unschedule_action' ) ) {
            as_unschedule_action( 'zbp_waitlist_check_expiry', array( 'entry_id' => $entry_id ) );
        }

        if ( $product_id && ! empty( $event_date ) ) {
            $this->recalculate_priorities( $product_id, $event_date );
            $this->process_next_waitlist_invitation( $product_id, $event_date );
        }

        wc_add_notice( __( 'Your waitlist invitation has been released.', 'zen-bookpro' ), 'success' );
        wp_safe_redirect( $redirect_url );
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

        if ( $this->is_event_cancelled( $product_id, $event_date ) ) {
            return new WP_Error( 'event_cancelled', __( 'This event has been cancelled.', 'zen-bookpro' ) );
        }

        if ( $this->has_event_started( $product_id, $event_date ) ) {
            return new WP_Error( 'event_started', __( 'This invitation is no longer available.', 'zen-bookpro' ) );
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

        $mode = get_post_meta( $product_id, '_zbp_product_mode', true );
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

            if ( (int) $invite_product_id === (int) $product_id && $invite_event_date === $event_date && get_current_user_id() === $invite_customer ) {
                $token = isset( $_GET['zbp_token'] ) ? sanitize_text_field( $_GET['zbp_token'] ) : get_post_meta( $invite_id, '_waitlist_token', true );
                if ( ! is_wp_error( $this->validate_invitation( $invite_id, $token ) ) ) {
                    return $passed; // Let the invited user book
                }
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
     * Handle booking creation.
     *
     * WooCommerce Bookings fires this for temporary in-cart bookings too, so this
     * only completes an invite when the booking already has a real order item.
     *
     * @param int $booking_id Booking ID.
     * @return void
     */
    public function handle_new_booking( $booking_id ) {
        $booking = get_wc_booking( $booking_id );
        if ( ! $booking || ! is_a( $booking, 'WC_Booking' ) || $booking->has_status( array( 'in-cart', 'was-in-cart' ) ) ) {
            return;
        }

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
     * Complete waitlist entries after checkout processing.
     *
     * @param int      $order_id Order ID.
     * @param array    $posted_data Posted data.
     * @param WC_Order $order Order object.
     * @return void
     */
    public function handle_checkout_order_processed( $order_id, $posted_data = array(), $order = null ) {
        $this->complete_waitlist_bookings_for_order( $order_id );
    }

    /**
     * Complete waitlist entries when an order reaches a paid status.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function handle_paid_order_status( $order_id ) {
        $this->complete_waitlist_bookings_for_order( $order_id );
    }

    /**
     * Complete waitlist entries attached to an order once CBB/checkout has finalized.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    private function complete_waitlist_bookings_for_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $invite_id = wc_get_order_item_meta( $item_id, '_zbp_waitlist_invite_id', true );
            if ( ! $invite_id ) {
                continue;
            }

            $coin_cost = (float) wc_get_order_item_meta( $item_id, '_cbb_coin_item_cost', true );
            if ( $coin_cost > 0 && ! $order->get_meta( '_cbb_coins_debited_transaction_id', true ) ) {
                continue;
            }

            $booking_ids = array();
            if ( class_exists( 'WC_Booking_Data_Store' ) && method_exists( 'WC_Booking_Data_Store', 'get_booking_ids_from_order_item_id' ) ) {
                $booking_ids = WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id );
            }

            foreach ( (array) $booking_ids as $booking_id ) {
                $this->complete_waitlist_booking( $invite_id, $booking_id );
            }
        }
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

        $booking = get_wc_booking( $booking_id );
        if ( $booking && is_a( $booking, 'WC_Booking' ) && $booking->has_status( array( 'in-cart', 'was-in-cart' ) ) ) {
            return;
        }

        // Get product and date before clearing priorities
        $product_id = (int) get_post_meta( $invite_id, '_product_id', true );
        $event_date = get_post_meta( $invite_id, '_event_date', true );

        // Update status, link booking ID, and clear priority
        update_post_meta( $invite_id, '_waitlist_status', 'booked' );
        update_post_meta( $invite_id, '_booking_id', $booking_id );
        delete_post_meta( $invite_id, '_waitlist_priority' );

        // Unschedule Action Scheduler expiry check
        if ( function_exists( 'as_unschedule_action' ) ) {
            as_unschedule_action( 'zbp_waitlist_check_expiry', array( 'entry_id' => $invite_id ) );
        }

        // Recalculate priorities of the remaining waiting queue
        if ( $product_id && $event_date ) {
            $this->recalculate_priorities( $product_id, $event_date );
        }
    }

    /**
     * Notify the next eligible waitlisted user when a reserved invitation is released.
     *
     * @param int    $product_id Product ID.
     * @param string $event_date Event date.
     * @return void
     */
    private function process_next_waitlist_invitation( $product_id, $event_date ) {
        if ( ! $product_id || empty( $event_date ) ) {
            return;
        }

        if ( $this->is_event_cancelled( $product_id, $event_date ) || $this->has_event_started( $product_id, $event_date ) ) {
            return;
        }

        $available_seats = $this->calculate_available_seats( $product_id, $event_date );
        if ( $available_seats <= 0 ) {
            return;
        }

        $eligible_entries = $this->get_next_eligible_waitlist_entries( $product_id, $event_date, 1 );
        if ( empty( $eligible_entries ) ) {
            return;
        }

        do_action( 'zbp_waitlist_prepare_invitations', $eligible_entries, $product_id, $event_date, $available_seats );
    }

    /**
     * Handle waitlist invitation expiration check via Action Scheduler.
     *
     * @param int $entry_id Waitlist entry ID.
     * @return void
     */
    public function handle_waitlist_expiry( $entry_id ) {
        $entry_id = absint( $entry_id );
        if ( ! $entry_id ) {
            return;
        }

        $status = get_post_meta( $entry_id, '_waitlist_status', true );
        if ( 'invited' !== $status ) {
            return; // Already booked, left, or expired
        }

        // Get product ID and date before we change the status
        $product_id = (int) get_post_meta( $entry_id, '_product_id', true );
        $event_date = get_post_meta( $entry_id, '_event_date', true );

        // Update status to expired and delete priority
        update_post_meta( $entry_id, '_waitlist_status', 'expired' );
        delete_post_meta( $entry_id, '_waitlist_priority' );

        if ( ! $product_id || empty( $event_date ) ) {
            return;
        }

        // Recalculate priorities of the remaining waiting queue
        $this->recalculate_priorities( $product_id, $event_date );

        if ( $this->is_event_cancelled( $product_id, $event_date ) || $this->has_event_started( $product_id, $event_date ) ) {
            return;
        }

        // Calculate available seats
        $available_seats = $this->calculate_available_seats( $product_id, $event_date );
        if ( $available_seats <= 0 ) {
            return;
        }

        // Identify the next eligible customers from the waitlist
        $eligible_entries = $this->get_next_eligible_waitlist_entries( $product_id, $event_date, 1 );
        if ( empty( $eligible_entries ) ) {
            return;
        }

        // Fire custom WordPress action to prepare these entries (modular hook for step 3)
        do_action( 'zbp_waitlist_prepare_invitations', $eligible_entries, $product_id, $event_date, $available_seats );
    }

    /**
     * Determine whether an event date has been cancelled by the studio.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Event date.
     * @return bool
     */
    private function is_event_cancelled( $product_id, $date ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $cancelled_dates = $product->get_meta( '_zbp_cancelled_dates' );
        return is_array( $cancelled_dates ) && in_array( $date, $cancelled_dates, true );
    }

    /**
     * Determine whether the event has already started.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Event date.
     * @return bool
     */
    private function has_event_started( $product_id, $date ) {
        $start_timestamp = $this->get_event_start_timestamp( $product_id, $date );
        return $start_timestamp > 0 && time() >= $start_timestamp;
    }

    /**
     * Resolve the event start timestamp from the first Woo Bookings slot.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Event date.
     * @return int
     */
    private function get_event_start_timestamp( $product_id, $date ) {
        $slot_service = new ZBP_Slot_Service();
        $slot_data    = $slot_service->get_slots_for_product( $product_id, $date, 'event', true );

        if ( ! empty( $slot_data['slots'][0]['timestamp'] ) ) {
            return (int) $slot_data['slots'][0]['timestamp'];
        }

        return 0;
    }

    /**
     * Build native WooCommerce Bookings POST fields for a waitlist claim.
     *
     * @param int    $product_id Product ID.
     * @param string $event_date Event date.
     * @return array
     */
    private function build_booking_posted_data( $product_id, $event_date ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'booking' ) ) {
            return array();
        }

        if ( class_exists( 'WC_Product_Booking' ) && ! ( $product instanceof WC_Product_Booking ) ) {
            $booking_product = new WC_Product_Booking( $product_id );
            if ( $booking_product && method_exists( $booking_product, 'is_type' ) && $booking_product->is_type( 'booking' ) ) {
                $product = $booking_product;
            }
        }

        $date_timestamp = strtotime( $event_date );
        if ( ! $date_timestamp ) {
            return array();
        }

        $payload = array(
            'add-to-cart'                        => (int) $product_id,
            'wc_bookings_field_start_date_year'  => wp_date( 'Y', $date_timestamp ),
            'wc_bookings_field_start_date_month' => wp_date( 'n', $date_timestamp ),
            'wc_bookings_field_start_date_day'   => wp_date( 'j', $date_timestamp ),
            'wc_bookings_field_duration'         => 1,
            'wc_bookings_field_qty'              => 1,
        );

        $start_timestamp = $this->get_event_start_timestamp( $product_id, $event_date );
        if ( $start_timestamp > 0 ) {
            $payload['wc_bookings_field_start_date_time'] = wp_date( 'Y-m-d H:i:s', $start_timestamp );
        }

        if ( method_exists( $product, 'has_persons' ) && $product->has_persons() ) {
            $min_persons = method_exists( $product, 'get_min_persons' ) ? absint( $product->get_min_persons() ) : 1;
            $payload['wc_bookings_field_persons'] = max( 1, $min_persons );
        }

        if ( method_exists( $product, 'has_resources' ) && $product->has_resources() && method_exists( $product, 'is_resource_assignment_type' ) && $product->is_resource_assignment_type( 'customer' ) ) {
            $resource_id = $this->resolve_first_valid_resource_id( method_exists( $product, 'get_resources' ) ? $product->get_resources() : array() );
            if ( $resource_id > 0 ) {
                $payload['wc_bookings_field_resource'] = $resource_id;
            }
        }

        return $payload;
    }

    /**
     * Pick the first usable resource ID for customer-assigned resources.
     *
     * @param array $resources Product resources.
     * @return int
     */
    private function resolve_first_valid_resource_id( $resources ) {
        if ( ! is_array( $resources ) || empty( $resources ) ) {
            return 0;
        }

        foreach ( $resources as $resource ) {
            if ( ! is_object( $resource ) ) {
                continue;
            }

            $resource_id = isset( $resource->ID ) ? (int) $resource->ID : ( method_exists( $resource, 'get_id' ) ? (int) $resource->get_id() : 0 );
            if ( $resource_id <= 0 ) {
                continue;
            }

            if ( method_exists( $resource, 'get_qty' ) && (int) $resource->get_qty() <= 0 ) {
                continue;
            }

            return $resource_id;
        }

        return 0;
    }
    /**
     * Clear active waitlist entries for the same product/date as a selected waitlist entry.
     *
     * @return void
     */
    public function handle_clear_class_waitlist() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to clear waitlists.', 'zen-bookpro' ) );
        }

        $entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;
        if ( ! $entry_id || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'zbp_clear_class_waitlist_' . $entry_id ) ) {
            wp_die( esc_html__( 'Invalid waitlist clear request.', 'zen-bookpro' ) );
        }

        $product_id = (int) get_post_meta( $entry_id, '_product_id', true );
        $event_date = get_post_meta( $entry_id, '_event_date', true );
        $cleared    = $this->clear_class_waitlist_entries( $product_id, $event_date );

        $redirect_url = add_query_arg(
            array(
                'post_type'            => 'zbp_waitlist',
                'zbp_waitlist_cleared' => $cleared,
            ),
            admin_url( 'edit.php' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Mark active waitlist entries for one class/date as cleared.
     *
     * @param int    $product_id Product ID.
     * @param string $event_date Event date.
     * @return int Number of entries cleared.
     */
    private function clear_class_waitlist_entries( $product_id, $event_date ) {
        if ( ! $product_id || empty( $event_date ) ) {
            return 0;
        }

        $entries = get_posts( array(
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
                    'value' => $event_date,
                ),
                array(
                    'key'     => '_waitlist_status',
                    'value'   => array( 'waiting', 'invited' ),
                    'compare' => 'IN',
                ),
            ),
        ) );

        foreach ( $entries as $clear_entry_id ) {
            update_post_meta( $clear_entry_id, '_waitlist_status', 'cleared' );
            update_post_meta( $clear_entry_id, '_cleared_at', time() );
            update_post_meta( $clear_entry_id, '_cleared_by', get_current_user_id() );
            delete_post_meta( $clear_entry_id, '_waitlist_priority' );

            if ( function_exists( 'as_unschedule_action' ) ) {
                as_unschedule_action( 'zbp_waitlist_check_expiry', array( 'entry_id' => (int) $clear_entry_id ) );
            }
        }

        $this->recalculate_priorities( $product_id, $event_date );

        return count( $entries );
    }

    /**
     * Render admin notices for waitlist actions.
     *
     * @return void
     */
    public function render_waitlist_admin_notices() {
        if ( ! isset( $_GET['zbp_waitlist_cleared'] ) ) {
            return;
        }

        $count = absint( $_GET['zbp_waitlist_cleared'] );
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html( sprintf( _n( '%d waitlist entry cleared.', '%d waitlist entries cleared.', $count, 'zen-bookpro' ), $count ) )
        );
    }

    /**
     * Remove row actions for read-only waitlist posts.
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    Post object.
     * @return array
     */
    public function remove_row_actions( $actions, $post ) {
        if ( 'zbp_waitlist' !== $post->post_type ) {
            return $actions;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return array();
        }

        $product_id = (int) get_post_meta( $post->ID, '_product_id', true );
        $event_date = get_post_meta( $post->ID, '_event_date', true );
        if ( ! $product_id || empty( $event_date ) ) {
            return array();
        }

        $clear_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'   => 'zbp_clear_class_waitlist',
                    'entry_id' => $post->ID,
                ),
                admin_url( 'admin-post.php' )
            ),
            'zbp_clear_class_waitlist_' . $post->ID
        );

        return array(
            'zbp_clear_class_waitlist' => sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
                esc_url( $clear_url ),
                esc_attr( wp_json_encode( __( 'Clear all active waitlist entries for this class/date? This cannot be undone.', 'zen-bookpro' ) ) ),
                esc_html__( 'Clear class waitlist', 'zen-bookpro' )
            ),
        );
    }

    /**
     * Remove bulk actions for waitlist posts.
     *
     * @param array $actions Bulk actions.
     * @return array
     */
    public function remove_bulk_actions( $actions ) {
        return array();
    }

    /**
     * Block direct access to single post editing page.
     *
     * @return void
     */
    public function restrict_waitlist_editing() {
        global $pagenow;
        if ( 'post.php' === $pagenow && isset( $_GET['post'] ) ) {
            $post_id = absint( $_GET['post'] );
            if ( 'zbp_waitlist' === get_post_type( $post_id ) ) {
                wp_die( esc_html__( 'Waitlist entries are read-only.', 'zen-bookpro' ) );
            }
        }
    }

    /**
     * Render custom filters on the waitlist CPT list table.
     *
     * @param string $post_type Custom Post Type key.
     * @return void
     */
    public function add_admin_filters( $post_type ) {
        if ( 'zbp_waitlist' !== $post_type ) {
            return;
        }

        // 1. Filter by Product (Event Mode Only)
        $selected_product = isset( $_GET['zbp_filter_product'] ) ? absint( $_GET['zbp_filter_product'] ) : 0;
        $products = wc_get_products( array(
            'status' => 'publish',
            'type'   => 'booking',
            'limit'  => -1,
        ) );

        $event_products = array();
        foreach ( $products as $prod ) {
            if ( 'event' === get_post_meta( $prod->get_id(), '_zbp_product_mode', true ) ) {
                $event_products[] = $prod;
            }
        }

        echo '<select name="zbp_filter_product" id="zbp_filter_product">';
        echo '<option value="">' . esc_html__( 'All Event Products', 'zen-bookpro' ) . '</option>';
        foreach ( $event_products as $prod ) {
            printf(
                '<option value="%d" %s>%s</option>',
                $prod->get_id(),
                selected( $selected_product, $prod->get_id(), false ),
                esc_html( $prod->get_name() )
            );
        }
        echo '</select>';

        // 2. Filter by Waitlist Status
        $selected_status = isset( $_GET['zbp_filter_status'] ) ? sanitize_text_field( $_GET['zbp_filter_status'] ) : '';
        $statuses = array(
            'waiting' => __( 'Waiting', 'zen-bookpro' ),
            'invited' => __( 'Invited', 'zen-bookpro' ),
            'booked'  => __( 'Booked', 'zen-bookpro' ),
            'expired' => __( 'Expired', 'zen-bookpro' ),
            'left'    => __( 'Left', 'zen-bookpro' ),
            'cleared' => __( 'Cleared', 'zen-bookpro' ),
        );

        echo '<select name="zbp_filter_status" id="zbp_filter_status">';
        echo '<option value="">' . esc_html__( 'All Statuses', 'zen-bookpro' ) . '</option>';
        foreach ( $statuses as $key => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                $key,
                selected( $selected_status, $key, false ),
                esc_html( $label )
            );
        }
        echo '</select>';

        // 3. Filter by Event Date
        $selected_date = isset( $_GET['zbp_filter_date'] ) ? sanitize_text_field( $_GET['zbp_filter_date'] ) : '';
        echo '<input type="date" name="zbp_filter_date" value="' . esc_attr( $selected_date ) . '" style="vertical-align: middle; height: 30px;" />';
    }

    /**
     * Apply admin filters and extend search to customer fields.
     *
     * @param WP_Query $query The WP_Query instance.
     * @return void
     */
    public function apply_admin_filters( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $GLOBALS['pagenow'] || 'zbp_waitlist' !== $query->get( 'post_type' ) ) {
            return;
        }

        $meta_query = array();

        // Handle Product filter
        if ( isset( $_GET['zbp_filter_product'] ) && '' !== $_GET['zbp_filter_product'] ) {
            $meta_query[] = array(
                'key'   => '_product_id',
                'value' => absint( $_GET['zbp_filter_product'] ),
            );
        }

        // Handle Status filter
        if ( isset( $_GET['zbp_filter_status'] ) && '' !== $_GET['zbp_filter_status'] ) {
            $meta_query[] = array(
                'key'   => '_waitlist_status',
                'value' => sanitize_text_field( $_GET['zbp_filter_status'] ),
            );
        }

        // Handle Date filter
        if ( isset( $_GET['zbp_filter_date'] ) && '' !== $_GET['zbp_filter_date'] ) {
            $meta_query[] = array(
                'key'   => '_event_date',
                'value' => sanitize_text_field( $_GET['zbp_filter_date'] ),
            );
        }

        // Handle search keyword extension
        $search_term = $query->get( 's' );
        if ( ! empty( $search_term ) ) {
            // Unset standard post title search so we search meta properties purely
            $query->set( 's', '' );
            
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_customer_name',
                    'value'   => $search_term,
                    'compare' => 'LIKE',
                ),
                array(
                    'key'     => '_customer_email',
                    'value'   => $search_term,
                    'compare' => 'LIKE',
                ),
            );
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Query waitlist entries based on filters.
     *
     * @param array $filters Query filters.
     * @return array Array of WP_Post objects.
     */
    public function query_waitlist_entries( $filters = array() ) {
        $query_args = array(
            'post_type'      => 'zbp_waitlist',
            'posts_per_page' => isset( $filters['limit'] ) ? intval( $filters['limit'] ) : -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                'relation' => 'AND',
            ),
        );

        if ( ! empty( $filters['product_id'] ) ) {
            $query_args['meta_query'][] = array(
                'key'   => '_product_id',
                'value' => absint( $filters['product_id'] ),
            );
        }

        if ( ! empty( $filters['status'] ) ) {
            $query_args['meta_query'][] = array(
                'key'   => '_waitlist_status',
                'value' => sanitize_text_field( $filters['status'] ),
            );
        }

        if ( ! empty( $filters['event_date'] ) ) {
            $query_args['meta_query'][] = array(
                'key'   => '_event_date',
                'value' => sanitize_text_field( $filters['event_date'] ),
            );
        }

        if ( ! empty( $filters['search'] ) ) {
            $query_args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_customer_name',
                    'value'   => sanitize_text_field( $filters['search'] ),
                    'compare' => 'LIKE',
                ),
                array(
                    'key'     => '_customer_email',
                    'value'   => sanitize_text_field( $filters['search'] ),
                    'compare' => 'LIKE',
                ),
            );
        }

        // Order by joined_at descending by default (newest first)
        $query_args['meta_key'] = '_joined_at';
        $query_args['orderby']  = 'meta_value_num';
        $query_args['order']    = 'DESC';

        return get_posts( $query_args );
    }

    public function log( $message ) {
        $log_file = ZBP_PLUGIN_PATH . 'debug_log.txt';
        $formatted = sprintf( "[%s] %s\n", date('Y-m-d H:i:s'), $message );
        file_put_contents( $log_file, $formatted, FILE_APPEND );
    }
}

