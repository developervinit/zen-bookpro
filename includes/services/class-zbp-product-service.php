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
            $slot_result  = $this->slot_service->get_slots_for_product( $product, $selected_date, $mode );
            $slots        = isset( $slot_result['slots'] ) ? $slot_result['slots'] : array();
            $slot_debug   = isset( $slot_result['debug'] ) ? $slot_result['debug'] : '';

            $image_id  = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
            if ( ! $image_url ) {
                $image_url = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
            }
            
            $all_meta = get_post_meta( $product_id );

            $max_spots = 1;
            $booked_spots = 0;
            
            if ( class_exists( 'WC_Product_Booking' ) ) {
                $booking_product = new WC_Product_Booking( $product_id );
                
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
                        $first_timestamp = $slots[0]['timestamp'];

                        if ( isset( $available_slots[ $first_timestamp ] ) ) {
                            $booked_spots = (int) $available_slots[ $first_timestamp ]['booked'];
                            // Update max_spots dynamically if it differs (e.g. resource based)
                            $available_count = (int) $available_slots[ $first_timestamp ]['available'];
                            $max_spots = $booked_spots + $available_count;
                        }
                    }
                } 
                
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
                        $total_booked = 0;
                        $first_booking_time = '';
                        $first_booking_ts = 0;

                        foreach ( $existing_bookings as $booking ) {
                            $total_booked += method_exists( $booking, 'get_persons_total' ) ? $booking->get_persons_total() : 1;

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
                if ( $booked_spots >= $max_spots ) {
                    $event_status = 'waitlist';
                }
            }

            $mapped[] = array(
                'id'                => $product_id,
                'title'             => $product->get_name(),
                'mode'              => $mode,
                'image'             => $image_url ? $image_url : '',
                'price_html'        => $product->get_price_html(),
                'duration'          => $this->get_duration_label( $booking_data ),
                'zen_duration'      => $product->get_meta( '_zen_duration' ),
                'zen_coins'         => $product->get_meta( '_zen_coins' ),
                'zen_instructor'    => $product->get_meta( '_zen_instructor_name' ),
                'availability_data' => isset( $booking_data['availability'] ) ? $booking_data['availability'] : array(),
                'has_booking_data'  => ! empty( $booking_data ),
                'is_slot_based'     => 'free_flow' === $mode,
                'slots'             => $slots,
                'max_spots'         => $max_spots,
                'booked_spots'      => $booked_spots,
                'event_status'      => $event_status,
                'slot_debug'        => $slot_debug,
            );
        }

        return $mapped;
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
        $mode = get_post_meta( $product_id, '_zbp_product_mode', true );
        $mode = sanitize_key( $mode );

        if ( ! in_array( $mode, array( 'free_flow', 'event' ), true ) ) {
            return 'free_flow';
        }

        return $mode;
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

}
