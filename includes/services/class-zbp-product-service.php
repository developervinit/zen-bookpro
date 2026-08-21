<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Product_Service {
    /**
     * Slot service.
     *
     * @var ZBP_Slot_Service
     */
    private $slot_service;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->slot_service = new ZBP_Slot_Service();
    }

    /**
     * Fetch booking products with optional taxonomy filters.
     *
     * @param array $filters Filter values.
     *
     * @return array
     */
    public function get_products( $filters = array() ) {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return array();
        }

        $filters = wp_parse_args(
            $filters,
            array(
                'experience_category' => 0,
                'activity_type'       => 0,
                'selected_date'       => '',
            )
        );

        $experience_category_id = absint( $filters['experience_category'] );
        $activity_type_id       = absint( $filters['activity_type'] );
        $selected_date          = $this->normalize_selected_date( $filters['selected_date'] );

        $all_products = wc_get_products(
            array(
                'status' => 'publish',
                'type'   => 'booking',
                'limit'  => -1,
                'return' => 'objects',
            )
        );



        $booking_products = array_values(
            array_filter(
                $all_products,
                array( $this, 'is_booking_product' )
            )
        );



        $selected_ids = $this->get_selected_product_ids();

        if ( ! empty( $selected_ids ) ) {
            $booking_products = array_values(
                array_filter(
                    $booking_products,
                    static function ( $product ) use ( $selected_ids ) {
                        return in_array( (int) $product->get_id(), $selected_ids, true );
                    }
                )
            );
        }



        $taxonomy_filtered = array_values(
            array_filter(
                $booking_products,
                function ( $product ) use ( $experience_category_id, $activity_type_id ) {
                    $product_id = (int) $product->get_id();

                    if ( $experience_category_id > 0 && ! has_term( $experience_category_id, 'experience_category', $product_id ) ) {
                        return false;
                    }

                    if ( $activity_type_id > 0 && ! has_term( $activity_type_id, 'activity_type', $product_id ) ) {
                        return false;
                    }

                    return true;
                }
            )
        );



        return $this->map_products_for_template( $taxonomy_filtered, $selected_date );
    }

    /**
     * Get all booking products for admin selection UI.
     *
     * @return array
     */
    public function get_all_bookable_products() {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return array();
        }

        $all_products = wc_get_products(
            array(
                'status' => 'publish',
                'type'   => 'booking',
                'limit'  => -1,
                'return' => 'objects',
            )
        );

        return array_values(
            array_filter(
                $all_products,
                array( $this, 'is_booking_product' )
            )
        );
    }

    /**
     * Get selected product IDs from settings.
     *
     * @return array
     */
    public function get_selected_product_ids() {
        $saved = get_option( 'zbp_selected_product_ids', array() );

        if ( ! is_array( $saved ) ) {
            return array();
        }

        return array_values(
            array_filter(
                array_map( 'absint', $saved )
            )
        );
    }

    /**
     * Get comma-separated term names for a product and taxonomy.
     *
     * @param int    $product_id Product ID.
     * @param string $taxonomy   Taxonomy slug.
     *
     * @return string
     */
    public function get_term_names_for_product( $product_id, $taxonomy ) {
        $terms = get_the_terms( $product_id, $taxonomy );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return __( 'None', 'zen-bookpro' );
        }

        $names = wp_list_pluck( $terms, 'name' );

        return implode( ', ', $names );
    }

    /**
     * Map product objects to template-ready arrays.
     *
     * @param array  $products      Product objects.
     * @param string $selected_date Date string.
     *
     * @return array
     */
    private function map_products_for_template( $products, $selected_date ) {
        $mapped = array();

        foreach ( $products as $product ) {
            if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
                continue;
            }

            $product_id   = (int) $product->get_id();
            $mode         = $this->get_product_mode( $product_id );
            $booking_data = $this->get_booking_data( $product );
            $zen_duration = (string) $product->get_meta( '_zen_duration' );
            $slot_result  = $this->slot_service->get_slots_for_product( $product, $selected_date, $mode );
            $slots        = isset( $slot_result['slots'] ) ? $slot_result['slots'] : array();
            $slot_debug   = isset( $slot_result['debug'] ) ? $slot_result['debug'] : '';

            if ( 'free_flow' === $mode && empty( $slots ) ) {
                continue;
            }

            $image_url = '';
            $terms = get_the_terms( $product_id, 'experience_category' );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $term = reset( $terms );
                $term_image_id = get_term_meta( $term->term_id, '_zen_experience_category_image_id', true );
                if ( $term_image_id ) {
                    $image_url = wp_get_attachment_image_url( $term_image_id, 'thumbnail' );
                }
            }

            if ( ! $image_url ) {
                $image_id  = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
                if ( ! $image_url ) {
                    $image_url = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
                }
            }
            $prod_image_id  = $product->get_image_id();
            $prod_image_url = $prod_image_id ? wp_get_attachment_image_url( $prod_image_id, 'large' ) : '';
            if ( ! $prod_image_url ) {
                $prod_image_url = get_the_post_thumbnail_url( $product_id, 'large' );
            }
            $gallery_urls = $this->get_product_gallery_urls( $product );
            
            $all_meta = get_post_meta( $product_id );

            $max_spots = 1;
            $booked_spots = 0;
            $woo_duration_value = 0;
            $woo_duration_unit  = '';
            
            if ( class_exists( 'WC_Product_Booking' ) ) {
                $booking_product = new WC_Product_Booking( $product_id );

                if ( method_exists( $booking_product, 'get_duration' ) ) {
                    $woo_duration_value = (int) $booking_product->get_duration();
                }
                if ( method_exists( $booking_product, 'get_duration_unit' ) ) {
                    $woo_duration_unit = (string) $booking_product->get_duration_unit();
                }
                
                // Fallback: Always get theoretical max capacity from product
                if ( method_exists( $booking_product, 'get_qty' ) ) {
                    $max_spots = (int) $booking_product->get_qty();
                }

                // If we have available slots, get occupancy from them
                if ( ! empty( $slots ) && function_exists( 'wc_bookings_get_time_slots' ) ) {
                    $blocks_to_check = array();
                    foreach ( $slots as $slot ) {
                        if ( ! empty( $slot['timestamp'] ) ) {
                            $blocks_to_check[ $slot['timestamp'] ] = 0;
                        }
                    }

                    if ( ! empty( $blocks_to_check ) ) {
                        $available_slots = wc_bookings_get_time_slots( $booking_product, $blocks_to_check, array(), 0, 0, 0, true );

                        foreach ( $slots as &$slot_item ) {
                            $ts = isset( $slot_item['timestamp'] ) ? (int) $slot_item['timestamp'] : 0;
                            if ( $ts > 0 && isset( $available_slots[ $ts ] ) ) {
                                $slot_item['booked_spots']    = (int) $available_slots[ $ts ]['booked'];
                                $slot_item['available_spots'] = (int) $available_slots[ $ts ]['available'];
                                $slot_item['max_spots']       = $slot_item['booked_spots'] + $slot_item['available_spots'];
                            } else {
                                $slot_item['booked_spots']    = 0;
                                $slot_item['available_spots'] = $max_spots;
                                $slot_item['max_spots']       = $max_spots;
                            }
                        }
                        unset( $slot_item );

                        $first_timestamp = $slots[0]['timestamp'];

                        if ( isset( $available_slots[ $first_timestamp ] ) ) {
                            $booked_spots = (int) $available_slots[ $first_timestamp ]['booked'];
                            // Update max_spots dynamically if it differs (e.g. resource based)
                            $available_count = (int) $available_slots[ $first_timestamp ]['available'];
                            $max_spots = $booked_spots + $available_count;
                        }
                    }
                }

                foreach ( $slots as &$slot_item ) {
                    if ( ! isset( $slot_item['booked_spots'] ) ) {
                        $slot_item['booked_spots'] = 0;
                    }
                    if ( ! isset( $slot_item['max_spots'] ) ) {
                        $slot_item['max_spots'] = $max_spots;
                    }
                    if ( ! isset( $slot_item['available_spots'] ) ) {
                        $slot_item['available_spots'] = max( 0, $slot_item['max_spots'] - $slot_item['booked_spots'] );
                    }
                }
                unset( $slot_item ); 
                
                // CRITICAL FIX: If slots are empty or booked_spots is 0 but it's an event, 
                // fetch actual bookings to see if it's just full.
                if ( 'event' === $mode && ( empty( $slots ) || $booked_spots === 0 ) ) {
                    // Use date strings for more reliable querying
                    $date_from = $selected_date . ' 00:00:00';
                    $date_to   = $selected_date . ' 23:59:59';

                    $status_filter = array( 'confirmed', 'paid', 'complete', 'unpaid', 'pending-confirmation', 'in-cart', 'on-hold' );
                    $existing_bookings = array();

                    if ( class_exists( 'WC_Booking_Data_Store' ) && method_exists( 'WC_Booking_Data_Store', 'get_bookings_for_objects' ) ) {
                        $existing_bookings = WC_Booking_Data_Store::get_bookings_for_objects(
                            array( $product_id ),
                            $status_filter,
                            strtotime( $date_from ),
                            strtotime( $date_to )
                        );
                    } elseif ( class_exists( 'WC_Bookings_Controller' ) && method_exists( 'WC_Bookings_Controller', 'get_bookings_for_objects' ) ) {
                        // Backward-compatible fallback for older bookings versions.
                        $existing_bookings = WC_Bookings_Controller::get_bookings_for_objects(
                            array( $product_id ),
                            $status_filter,
                            strtotime( $date_from ),
                            strtotime( $date_to )
                        );
                    }

                    if ( ! empty( $existing_bookings ) ) {
                        // Event mode requires seat usage as booking count, not persons sum.
                        $total_booked = 0;
                        $first_booking_time = '';
                        $first_booking_ts = 0;

                        foreach ( $existing_bookings as $booking ) {
                            if ( ! $booking || ! is_a( $booking, 'WC_Booking' ) ) {
                                continue;
                            }
                            $total_booked += 1;

                            if ( ! $first_booking_time && method_exists( $booking, 'get_start' ) ) {
                                $first_booking_ts = $booking->get_start();
                                $first_booking_time = wp_date( 'H:i', $first_booking_ts );
                            }
                        }

                        $booked_spots = $total_booked;

                        // If slots are missing but we found bookings, reconstruct the slot
                        if ( empty( $slots ) && $first_booking_time ) {
                            $slots = array( array(
                                'start'     => wp_date( 'Y-m-d H:i:s', $first_booking_ts ),
                                'label'     => $first_booking_time,
                                'timestamp' => $first_booking_ts,
                                'status'    => 'available',
                                'value'     => wp_date( 'c', $first_booking_ts ),
                            ) );
                        }
                    }
                }
            }
            
            if ( $max_spots <= 0 ) {
                $max_spots = 1;
            }

            $event_status = 'join';
            if ( 'event' === $mode ) {
                $cancelled_dates = $product->get_meta( '_zbp_cancelled_dates' );
                $is_cancelled    = is_array( $cancelled_dates ) && in_array( $selected_date, $cancelled_dates, true );

                if ( $is_cancelled ) {
                    $event_status = 'cancelled';
                } else {
                    $event_has_ended  = false;
                    $duration_seconds = $this->get_duration_seconds( $booking_data, $zen_duration, $woo_duration_value, $woo_duration_unit );
                    $slot_end_timestamp = $this->resolve_event_slot_end_timestamp( $slots, $selected_date, $duration_seconds );
                    $now_timestamp = time();

                    if ( $slot_end_timestamp > 0 ) {
                        $event_has_ended = $now_timestamp >= $slot_end_timestamp;
                    }

                    // Check if there is a configured hide time before class start limit
                    $hide_value = (int) $product->get_meta( '_zbp_hide_before_value' );
                    $hide_unit  = $product->get_meta( '_zbp_hide_before_unit' );

                    if ( $hide_value > 0 ) {
                        $slot_start_timestamp = $this->resolve_event_slot_start_timestamp( $slots, $selected_date );
                        if ( $slot_start_timestamp > 0 ) {
                            $hide_seconds = 0;
                            switch ( $hide_unit ) {
                                case 'minutes':
                                    $hide_seconds = $hide_value * MINUTE_IN_SECONDS;
                                    break;
                                case 'hours':
                                    $hide_seconds = $hide_value * HOUR_IN_SECONDS;
                                    break;
                                case 'days':
                                    $hide_seconds = $hide_value * DAY_IN_SECONDS;
                                    break;
                            }
                            $hide_threshold = $slot_start_timestamp - $hide_seconds;
                            if ( $now_timestamp >= $hide_threshold ) {
                                $event_has_ended = true;
                            }
                        }
                    }

                    if ( $event_has_ended ) {
                        continue;
                    } elseif ( $booked_spots >= $max_spots ) {
                        $event_status = 'waitlist';
                    }

                    if ( is_user_logged_in() ) {
                        $user_id = get_current_user_id();
                        $waitlist_service = new ZBP_Waitlist_Service();
                        if ( $waitlist_service->is_user_on_waitlist( $user_id, $product_id, $selected_date ) ) {
                            $event_status = 'on_waitlist';
                        }
                    }
                }
            }

            $booking_duration_minutes = $this->get_booking_duration_minutes( $woo_duration_value, $woo_duration_unit, $booking_data );
            $experience_category      = $this->get_term_names_for_product( $product_id, 'experience_category' );
            $booking_coin_cost        = $product->get_meta( '_cbb_booking_coin_cost' );

            $hide_before_value = (int) $product->get_meta( '_zbp_hide_before_value' );
            $hide_before_unit  = $product->get_meta( '_zbp_hide_before_unit' );
            if ( ! $hide_before_unit ) {
                $hide_before_unit = 'minutes';
            }

            $mapped[] = array(
                'id'                => $product_id,
                'title'             => $product->get_name(),
                'description'       => wp_strip_all_tags( (string) $product->get_description() ),
                'cancellation_policy' => sanitize_text_field( (string) get_post_meta( $product_id, '_zbp_cancellation_policy', true ) ),
                'location'          => sanitize_text_field( (string) get_post_meta( $product_id, '_zbp_location', true ) ),
                'mode'              => $mode,
                'image'             => $image_url ? $image_url : '',
                'product_featured_image' => $prod_image_url ? $prod_image_url : '',
                'gallery'           => $gallery_urls,
                'price_html'        => $product->get_price_html(),
                'duration'          => $this->get_duration_label( $booking_data ),
                'zen_duration'      => $zen_duration,
                'booking_duration_minutes' => $booking_duration_minutes,
                'experience_category' => $experience_category,
                'booking_coin_cost' => $booking_coin_cost,
                'zen_coins'         => $booking_coin_cost,
                'zen_instructor'    => $product->get_meta( '_zen_instructor_name' ),
                'availability_data' => isset( $booking_data['availability'] ) ? $booking_data['availability'] : array(),
                'has_booking_data'  => ! empty( $booking_data ),
                'is_slot_based'     => 'free_flow' === $mode,
                'slots'             => $slots,
                'max_spots'         => $max_spots,
                'booked_spots'      => $booked_spots,
                'event_status'      => $event_status,
                'hide_before_value' => $hide_before_value,
                'hide_before_unit'  => $hide_before_unit,
                'slot_debug'        => $slot_debug,
            );
        }

        return $mapped;
    }

    /**
     * Get product gallery image URLs from WooCommerce gallery IDs.
     *
     * @param WC_Product $product Product object.
     *
     * @return array
     */
    private function get_product_gallery_urls( $product ) {
        if ( ! $product || ! method_exists( $product, 'get_gallery_image_ids' ) ) {
            return array();
        }

        $gallery_ids = $product->get_gallery_image_ids();

        if ( empty( $gallery_ids ) || ! is_array( $gallery_ids ) ) {
            return array();
        }

        $urls = array();
        foreach ( $gallery_ids as $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            if ( $attachment_id <= 0 ) {
                continue;
            }

            $url = wp_get_attachment_image_url( $attachment_id, 'large' );
            if ( $url ) {
                $urls[] = $url;
            }
        }

        return array_values( array_unique( $urls ) );
    }

    /**
     * Get booking duration in minutes from WooCommerce booking duration fields.
     *
     * @param int   $woo_duration_value Booking duration value.
     * @param string $woo_duration_unit Booking duration unit.
     * @param array $booking_data Booking data fallback.
     *
     * @return int
     */
    private function get_booking_duration_minutes( $woo_duration_value, $woo_duration_unit, $booking_data ) {
        $value = absint( $woo_duration_value );
        $unit  = sanitize_key( (string) $woo_duration_unit );

        if ( $value <= 0 ) {
            $value = isset( $booking_data['duration'] ) ? absint( $booking_data['duration'] ) : 0;
            $unit  = isset( $booking_data['duration_unit'] ) ? sanitize_key( (string) $booking_data['duration_unit'] ) : $unit;
        }

        if ( $value <= 0 ) {
            return 0;
        }

        switch ( $unit ) {
            case 'hour':
                return $value * 60;
            case 'day':
                return $value * 1440;
            case 'minute':
            default:
                return $value;
        }
    }

    /**
     * Normalize selected date input to Y-m-d.
     *
     * @param string $selected_date Date string.
     *
     * @return string
     */
    private function normalize_selected_date( $selected_date ) {
        $selected_date = sanitize_text_field( (string) $selected_date );

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $selected_date ) ) {
            return $selected_date;
        }

        return wp_date( 'Y-m-d' );
    }

    /**
     * Get product mode value.
     *
     * @param int $product_id Product ID.
     *
     * @return string
     */
    private function get_product_mode( $product_id ) {
        $raw_mode = (string) get_post_meta( $product_id, '_zbp_product_mode', true );
        $mode     = sanitize_key( $raw_mode );

        if ( in_array( $mode, array( 'event', 'event_single_slot', 'single_slot_event' ), true ) ) {
            return 'event';
        }

        if ( in_array( $mode, array( 'free_flow', 'freeflow' ), true ) ) {
            return 'free_flow';
        }

        return 'free_flow';
    }

    /**
     * Check whether a Woo product object is booking type.
     *
     * @param WC_Product $product Product object.
     *
     * @return bool
     */
    private function is_booking_product( $product ) {
        return $product && method_exists( $product, 'is_type' ) && $product->is_type( 'booking' );
    }

    /**
     * Collect product IDs for logging.
     *
     * @param array $products Product objects.
     *
     * @return array
     */
    private function collect_product_ids( $products ) {
        return array_values(
            array_map(
                static function ( $product ) {
                    return method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
                },
                $products
            )
        );
    }

    /**
     * Get booking-related metadata.
     *
     * @param WC_Product $product Product object.
     *
     * @return array
     */
    private function get_booking_data( $product ) {
        if ( ! $this->is_booking_product( $product ) ) {
            return array();
        }

        $booking_data = array(
            'duration'      => get_post_meta( $product->get_id(), '_wc_booking_duration', true ),
            'duration_unit' => get_post_meta( $product->get_id(), '_wc_booking_duration_unit', true ),
            'availability'  => get_post_meta( $product->get_id(), '_wc_booking_availability', true ),
        );

        return array_filter(
            $booking_data,
            static function ( $value ) {
                return '' !== $value && null !== $value;
            }
        );
    }

    /**
     * Build UI duration label.
     *
     * @param array $booking_data Booking metadata.
     *
     * @return string
     */
    private function get_duration_label( $booking_data ) {
        if ( empty( $booking_data['duration'] ) ) {
            return __( 'Duration N/A', 'zen-bookpro' );
        }

        $duration = absint( $booking_data['duration'] );
        $unit     = ! empty( $booking_data['duration_unit'] ) ? sanitize_text_field( $booking_data['duration_unit'] ) : __( 'minute', 'zen-bookpro' );

        return sprintf( '%d %s', $duration, $unit );
    }

    /**
     * Convert booking duration metadata into seconds.
     *
     * @param array $booking_data Booking metadata.
     *
     * @return int
     */
    private function get_duration_seconds( $booking_data, $zen_duration = '', $woo_duration_value = 0, $woo_duration_unit = '' ) {
        $woo_duration_value = absint( $woo_duration_value );
        $woo_duration_unit  = sanitize_key( (string) $woo_duration_unit );
        if ( $woo_duration_value > 0 && '' !== $woo_duration_unit ) {
            switch ( $woo_duration_unit ) {
                case 'hour':
                    return $woo_duration_value * HOUR_IN_SECONDS;
                case 'day':
                    return $woo_duration_value * DAY_IN_SECONDS;
                case 'minute':
                default:
                    return $woo_duration_value * MINUTE_IN_SECONDS;
            }
        }

        $zen_duration = strtolower( trim( (string) $zen_duration ) );
        if ( '' !== $zen_duration && preg_match( '/(\d+(?:\.\d+)?)/', $zen_duration, $match ) ) {
            $value = (float) $match[1];
            if ( $value > 0 ) {
                if ( false !== strpos( $zen_duration, 'hour' ) || false !== strpos( $zen_duration, 'hr' ) ) {
                    return (int) round( $value * HOUR_IN_SECONDS );
                }
                if ( false !== strpos( $zen_duration, 'min' ) ) {
                    return (int) round( $value * MINUTE_IN_SECONDS );
                }
            }
        }

        $duration = isset( $booking_data['duration'] ) ? absint( $booking_data['duration'] ) : 0;
        if ( $duration <= 0 ) {
            return 0;
        }

        $unit = isset( $booking_data['duration_unit'] ) ? sanitize_key( $booking_data['duration_unit'] ) : 'minute';

        switch ( $unit ) {
            case 'hour':
                return $duration * HOUR_IN_SECONDS;
            case 'day':
                return $duration * DAY_IN_SECONDS;
            case 'minute':
            default:
                return $duration * MINUTE_IN_SECONDS;
        }
    }

    /**
     * Resolve event slot end timestamp from slot label/time range or fallback duration.
     *
     * @param array  $slots            Slots payload.
     * @param string $selected_date    Date in Y-m-d.
     * @param int    $duration_seconds Duration fallback.
     *
     * @return int
     */
    private function resolve_event_slot_end_timestamp( $slots, $selected_date, $duration_seconds ) {
        if ( empty( $slots ) || empty( $slots[0] ) || ! is_array( $slots[0] ) ) {
            return 0;
        }

        $slot = $slots[0];

        if ( ! empty( $slot['label'] ) && preg_match( '/([0-9]{1,2}:[0-9]{2}(?:\s*[ap]m)?)\s*-\s*([0-9]{1,2}:[0-9]{2}(?:\s*[ap]m)?)/i', (string) $slot['label'], $m ) ) {
            $start_ts = $this->parse_time_on_date( $selected_date, $m[1] );
            $end_ts   = $this->parse_time_on_date( $selected_date, $m[2] );
            if ( $start_ts > 0 && $end_ts > 0 ) {
                if ( $end_ts <= $start_ts ) {
                    $end_ts += DAY_IN_SECONDS;
                }
                return $end_ts;
            }
        }

        $slot_timestamp = ! empty( $slot['timestamp'] ) ? (int) $slot['timestamp'] : 0;
        if ( $slot_timestamp > 0 && $duration_seconds > 0 ) {
            return $slot_timestamp + $duration_seconds;
        }

        return 0;
    }

    /**
     * Parse a time value for a specific date into timestamp using WP timezone.
     *
     * @param string $date Date in Y-m-d.
     * @param string $time Time string.
     *
     * @return int
     */
    private function parse_time_on_date( $date, $time ) {
        $date = sanitize_text_field( (string) $date );
        $time = strtolower( trim( (string) $time ) );
        $time = preg_replace( '/\s+/', '', $time );

        if ( '' === $date || '' === $time ) {
            return 0;
        }

        $timezone = wp_timezone();
        $formats  = array( 'Y-m-d g:ia', 'Y-m-d H:i' );

        foreach ( $formats as $format ) {
            $dt = DateTimeImmutable::createFromFormat( $format, $date . ' ' . $time, $timezone );
            if ( $dt instanceof DateTimeImmutable ) {
                return $dt->getTimestamp();
            }
        }

        return 0;
    }

    /**
     * Resolve event slot start timestamp from slot label or timestamp.
     *
     * @param array  $slots         Slots payload.
     * @param string $selected_date Date in Y-m-d.
     *
     * @return int
     */
    private function resolve_event_slot_start_timestamp( $slots, $selected_date ) {
        if ( empty( $slots ) || empty( $slots[0] ) || ! is_array( $slots[0] ) ) {
            return 0;
        }

        $slot = $slots[0];

        if ( ! empty( $slot['label'] ) && preg_match( '/([0-9]{1,2}:[0-9]{2}(?:\s*[ap]m)?)\s*-\s*([0-9]{1,2}:[0-9]{2}(?:\s*[ap]m)?)/i', (string) $slot['label'], $m ) ) {
            $start_ts = $this->parse_time_on_date( $selected_date, $m[1] );
            if ( $start_ts > 0 ) {
                return $start_ts;
            }
        }

        $slot_timestamp = ! empty( $slot['timestamp'] ) ? (int) $slot['timestamp'] : 0;
        if ( $slot_timestamp > 0 ) {
            return $slot_timestamp;
        }

        return 0;
    }

}

