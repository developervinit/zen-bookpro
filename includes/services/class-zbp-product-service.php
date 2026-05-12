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

        $this->debug_log(
            array(
                'stage'          => 'initial_fetch',
                'total_products' => count( $all_products ),
                'product_ids'    => $this->collect_product_ids( $all_products ),
            )
        );

        $booking_products = array_values(
            array_filter(
                $all_products,
                array( $this, 'is_booking_product' )
            )
        );

        $this->debug_log(
            array(
                'stage'          => 'booking_filter',
                'total_products' => count( $booking_products ),
                'product_ids'    => $this->collect_product_ids( $booking_products ),
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

        $this->debug_log(
            array(
                'stage'          => 'admin_filter',
                'selected_ids'   => $selected_ids,
                'total_products' => count( $booking_products ),
                'product_ids'    => $this->collect_product_ids( $booking_products ),
            )
        );

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

        $this->debug_log(
            array(
                'stage'            => 'taxonomy_filter',
                'experience_terms' => $experience_category_id > 0 ? array( $experience_category_id ) : array(),
                'activity_terms'   => $activity_type_id > 0 ? array( $activity_type_id ) : array(),
                'total_products'   => count( $taxonomy_filtered ),
                'product_ids'      => $this->collect_product_ids( $taxonomy_filtered ),
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
            $slots        = $this->slot_service->get_slots_for_product( $product, $selected_date, $mode );

            $image_id  = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
            if ( ! $image_url ) {
                $image_url = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
            }
            
            $all_meta = get_post_meta( $product_id );

            $max_spots = 1;
            $booked_spots = 0;
            
            if ( class_exists( 'WC_Product_Booking' ) && function_exists( 'wc_bookings_get_time_slots' ) ) {
                $booking_product = new WC_Product_Booking( $product_id );
                
                // Extract timestamps from slots
                $blocks_to_check = array();
                if ( is_array( $slots ) && ! empty( $slots ) ) {
                    foreach ( $slots as $slot ) {
                        if ( ! empty( $slot['timestamp'] ) ) {
                            $blocks_to_check[ $slot['timestamp'] ] = 0;
                        }
                    }
                }
                
                // Fetch native availability using the exact timestamps
                if ( ! empty( $blocks_to_check ) ) {
                    $available_slots = wc_bookings_get_time_slots( $booking_product, $blocks_to_check, array(), 0, 0, 0, true );
                    
                    // Since it's Event (Single Slot), we just look at the first slot's timestamp
                    $first_timestamp = $slots[0]['timestamp'];
                    
                    if ( isset( $available_slots[ $first_timestamp ] ) ) {
                        $booked_spots = (int) $available_slots[ $first_timestamp ]['booked'];
                        $available = (int) $available_slots[ $first_timestamp ]['available'];
                        $max_spots = $booked_spots + $available;
                    }
                    
                    echo "<!-- ZBP Debug: Product ID: $product_id | Max: $max_spots | Booked: $booked_spots | Qty: " . $booking_product->get_qty() . " -->";
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        echo "<script>console.log('ZBP PHP Debug: Product $product_id', " . wp_json_encode([
                            'max' => $max_spots,
                            'booked' => $booked_spots,
                            'qty' => $booking_product->get_qty(),
                            'has_res' => $booking_product->has_resources(),
                            'slots_count' => count($available_slots)
                        ]) . ");</script>";
                    }
                } else {
                    // Fallback to get_qty if no slots are parsed yet
                    if ( method_exists( $booking_product, 'get_qty' ) ) {
                        $max_spots = (int) $booking_product->get_qty();
                    }
                }
            }
            
            if ( $max_spots <= 0 ) {
                $max_spots = 1;
            }

            $mapped[] = array(
                'id'                => $product_id,
                'title'             => $product->get_name(),
                'mode'              => $mode,
                'image'             => $image_url ? $image_url : '',
                'price_html'        => $product->get_price_html(),
                'duration'          => $this->get_duration_label( $booking_data ),
                'zen_duration'      => get_post_meta( $product_id, '_zen_duration', true ),
                'zen_coins'         => get_post_meta( $product_id, '_zen_coins', true ),
                'zen_instructor'    => get_post_meta( $product_id, '_zen_instructor', true ),
                'availability_data' => isset( $booking_data['availability'] ) ? $booking_data['availability'] : array(),
                'has_booking_data'  => ! empty( $booking_data ),
                'is_slot_based'     => 'free_flow' === $mode,
                'slots'             => $slots,
                'max_spots'         => $max_spots,
                'booked_spots'      => $booked_spots,
                'debug_info'        => array(
                    'qty' => isset($booking_product) ? $booking_product->get_qty() : 'N/A',
                    'has_res' => isset($booking_product) ? $booking_product->has_resources() : 'N/A',
                    'first_ts' => isset($first_timestamp) ? $first_timestamp : 'N/A',
                )
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

    /**
     * Temporary debug logs to trace query behavior.
     *
     * @param array $payload Debug data.
     *
     * @return void
     */
    private function debug_log( $payload ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        error_log( 'ZBP Debug: ' . wp_json_encode( $payload ) );
    }
}
