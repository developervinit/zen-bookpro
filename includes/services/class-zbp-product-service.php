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
                'product_id'           => 0,
                'experience_category'  => 0,
                'activity_type'        => 0,
            )
        );

        $product_id             = absint( $filters['product_id'] );
        $experience_category_id = absint( $filters['experience_category'] );
        $activity_type_id       = absint( $filters['activity_type'] );

        $query_args = array(
            'status' => 'publish',
            'limit'  => -1,
            'type'   => 'booking',
            'return' => 'objects',
        );

        if ( $product_id > 0 ) {
            $query_args['include'] = array( $product_id );
        }

        $this->debug_log(
            array(
                'selected_term_ids' => array(
                    'experience_category' => $experience_category_id,
                    'activity_type'       => $activity_type_id,
                ),
                'wc_get_products_args' => $query_args,
            )
        );

        $wc_products = wc_get_products( $query_args );

        $this->debug_log(
            array(
                'wc_products_count_before_tax_filters' => is_array( $wc_products ) ? count( $wc_products ) : 0,
            )
        );

        if ( empty( $wc_products ) ) {
            return array();
        }

        $products = array();

        foreach ( $wc_products as $wc_product ) {
            if ( ! $wc_product || ! method_exists( $wc_product, 'get_id' ) ) {
                continue;
            }

            $product_post_id = $wc_product->get_id();

            if ( $experience_category_id > 0 && ! has_term( $experience_category_id, 'experience_category', $product_post_id ) ) {
                continue;
            }

            if ( $activity_type_id > 0 && ! has_term( $activity_type_id, 'activity_type', $product_post_id ) ) {
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
                'returned_products_count_after_tax_filters' => count( $products ),
            )
        );

        return $products;
    }

    /**
     * Get booking-related metadata.
     *
     * @param WC_Product $product Product object.
     *
     * @return array
     */
    private function get_booking_data( $product ) {
        if ( ! method_exists( $product, 'get_type' ) || 'booking' !== $product->get_type() ) {
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
