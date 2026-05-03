<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Product_Service {
    /**
     * Fetch booking products with optional taxonomy filters.
     *
     * @param array $filters Filter values.
     *
     * @return array
     */
    public function get_products( $filters = array() ) {
        $filters = wp_parse_args(
            $filters,
            array(
                'product_id'          => 0,
                'experience_category' => 0,
                'activity_type'       => 0,
            )
        );

        $product_id             = absint( $filters['product_id'] );
        $experience_category_id = absint( $filters['experience_category'] );
        $activity_type_id       = absint( $filters['activity_type'] );

        $base_args = array(
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'objects',
        );

        if ( $product_id > 0 ) {
            $base_args['include'] = array( $product_id );
        }

        // Step 5: status-only query (no type/tax filters) for diagnostics.
        $all_published_products = wc_get_products( $base_args );

        $all_product_debug = array();
        foreach ( $all_published_products as $product ) {
            $all_product_debug[] = $this->build_product_debug_row( $product );
        }

        $this->debug_log(
            array(
                'stage'                => 'status_only',
                'selected_term_ids'    => array(
                    'experience_category' => $experience_category_id,
                    'activity_type'       => $activity_type_id,
                ),
                'query_args'           => $base_args,
                'total_products'       => count( $all_published_products ),
                'products'             => $all_product_debug,
            )
        );

        // Step 2: booking type query via WooCommerce API.
        $booking_args         = $base_args;
        $booking_args['type'] = 'booking';
        $booking_type_products = wc_get_products( $booking_args );

        $booking_type_debug = array();
        foreach ( $booking_type_products as $product ) {
            $booking_type_debug[] = $this->build_product_debug_row( $product );
        }

        $this->debug_log(
            array(
                'stage'                => 'booking_type_only',
                'query_args'           => $booking_args,
                'total_products'       => count( $booking_type_products ),
                'products'             => $booking_type_debug,
            )
        );

        // Case B fallback: if no booking type products, detect booking-capable products from published set.
        $candidate_products = $booking_type_products;
        $candidate_source   = 'booking_type';

        if ( empty( $candidate_products ) ) {
            $candidate_products = array_values(
                array_filter(
                    $all_published_products,
                    array( $this, 'is_booking_product_object' )
                )
            );
            $candidate_source = 'booking_object_detection';
        }

        $this->debug_log(
            array(
                'stage'            => 'candidate_selection',
                'source'           => $candidate_source,
                'candidate_count'  => count( $candidate_products ),
                'candidate_ids'    => array_values(
                    array_map(
                        static function ( $product ) {
                            return method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
                        },
                        $candidate_products
                    )
                ),
            )
        );

        if ( empty( $candidate_products ) ) {
            return array();
        }

        $products = array();

        foreach ( $candidate_products as $wc_product ) {
            if ( ! $wc_product || ! method_exists( $wc_product, 'get_id' ) ) {
                continue;
            }

            $product_post_id = $wc_product->get_id();

            $taxonomy_debug = $this->get_taxonomy_debug_data( $product_post_id );

            if ( $experience_category_id > 0 && ! has_term( $experience_category_id, 'experience_category', $product_post_id ) ) {
                $this->debug_log(
                    array(
                        'stage'         => 'experience_filter_miss',
                        'product_id'    => $product_post_id,
                        'required_term' => $experience_category_id,
                        'assigned'      => $taxonomy_debug,
                    )
                );
                continue;
            }

            if ( $activity_type_id > 0 && ! has_term( $activity_type_id, 'activity_type', $product_post_id ) ) {
                $this->debug_log(
                    array(
                        'stage'         => 'activity_filter_miss',
                        'product_id'    => $product_post_id,
                        'required_term' => $activity_type_id,
                        'assigned'      => $taxonomy_debug,
                    )
                );
                continue;
            }

            $booking_data = $this->get_booking_data( $wc_product );
            $duration     = $this->get_duration_label( $booking_data );

            $products[] = array(
                'id'                => $product_post_id,
                'title'             => $wc_product->get_name(),
                'image'             => get_the_post_thumbnail_url( $product_post_id, 'medium' ),
                'price_html'        => $wc_product->get_price_html(),
                'duration'          => $duration,
                'availability_data' => isset( $booking_data['availability'] ) ? $booking_data['availability'] : array(),
                'has_booking_data'  => ! empty( $booking_data ),
                'is_slot_based'     => ! empty( $booking_data ),
            );
        }

        $this->debug_log(
            array(
                'stage'                          => 'final_after_tax_filters',
                'returned_products_count'        => count( $products ),
                'returned_product_ids'           => array_values(
                    array_map(
                        static function ( $product ) {
                            return (int) $product['id'];
                        },
                        $products
                    )
                ),
            )
        );

        return $products;
    }

    /**
     * Determine if product is booking-capable using Woo native object signals.
     *
     * @param WC_Product $product Product object.
     *
     * @return bool
     */
    private function is_booking_product_object( $product ) {
        if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
            return false;
        }

        if ( 'booking' === $product->get_type() ) {
            return true;
        }

        if ( class_exists( 'WC_Product_Booking' ) && $product instanceof WC_Product_Booking ) {
            return true;
        }

        if ( method_exists( $product, 'is_type' ) && $product->is_type( 'booking' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Build debug details for one product.
     *
     * @param WC_Product $product Product object.
     *
     * @return array
     */
    private function build_product_debug_row( $product ) {
        if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
            return array();
        }

        $product_id = (int) $product->get_id();

        return array(
            'id'              => $product_id,
            'type'            => method_exists( $product, 'get_type' ) ? $product->get_type() : 'unknown',
            'taxonomy_terms'  => $this->get_taxonomy_debug_data( $product_id ),
        );
    }

    /**
     * Get taxonomy debug data for custom filters.
     *
     * @param int $product_id Product ID.
     *
     * @return array
     */
    private function get_taxonomy_debug_data( $product_id ) {
        $experience_terms = get_the_terms( $product_id, 'experience_category' );
        $activity_terms   = get_the_terms( $product_id, 'activity_type' );

        return array(
            'experience_category' => array(
                'ids'   => $this->extract_term_ids( $experience_terms ),
                'slugs' => $this->extract_term_slugs( $experience_terms ),
            ),
            'activity_type'       => array(
                'ids'   => $this->extract_term_ids( $activity_terms ),
                'slugs' => $this->extract_term_slugs( $activity_terms ),
            ),
        );
    }

    /**
     * Extract term IDs.
     *
     * @param array|WP_Error|false $terms Terms result.
     *
     * @return array
     */
    private function extract_term_ids( $terms ) {
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        return array_values(
            array_map(
                static function ( $term ) {
                    return (int) $term->term_id;
                },
                $terms
            )
        );
    }

    /**
     * Extract term slugs.
     *
     * @param array|WP_Error|false $terms Terms result.
     *
     * @return array
     */
    private function extract_term_slugs( $terms ) {
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        return array_values(
            array_map(
                static function ( $term ) {
                    return (string) $term->slug;
                },
                $terms
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
        if ( ! $this->is_booking_product_object( $product ) ) {
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
