<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Waitlist_Service {

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
}
