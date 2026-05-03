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

        $product_id            = absint( $filters['product_id'] );
        $experience_category_id = absint( $filters['experience_category'] );
        $activity_type_id       = absint( $filters['activity_type'] );

        $query_args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_type',
                    'field'    => 'slug',
                    'terms'    => array( 'booking' ),
                ),
            ),
        );

        if ( $product_id > 0 ) {
            $query_args['post__in'] = array( $product_id );
        }

        if ( $experience_category_id > 0 ) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'experience_category',
                'field'    => 'term_id',
                'terms'    => array( $experience_category_id ),
            );
        }

        if ( $activity_type_id > 0 ) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'activity_type',
                'field'    => 'term_id',
                'terms'    => array( $activity_type_id ),
            );
        }

        if ( count( $query_args['tax_query'] ) > 1 ) {
            $query_args['tax_query']['relation'] = 'AND';
        }

        $this->debug_log(
            array(
                'selected_term_ids' => array(
                    'experience_category' => $experience_category_id,
                    'activity_type'       => $activity_type_id,
                ),
                'query_args'         => $query_args,
            )
        );

        $query = new WP_Query( $query_args );

        $this->debug_log(
            array(
                'returned_posts_count' => (int) $query->found_posts,
            )
        );

        if ( ! $query->have_posts() ) {
            return array();
        }

        $products = array();

        foreach ( $query->posts as $post ) {
            $wc_product = wc_get_product( $post->ID );

            if ( ! $wc_product ) {
                continue;
            }

            $booking_data = $this->get_booking_data( $wc_product );
            $duration     = $this->get_duration_label( $booking_data );

            $products[] = array(
                'id'                => $wc_product->get_id(),
                'title'             => $wc_product->get_name(),
                'image'             => get_the_post_thumbnail_url( $wc_product->get_id(), 'medium' ),
                'price_html'        => $wc_product->get_price_html(),
                'duration'          => $duration,
                'availability_data' => isset( $booking_data['availability'] ) ? $booking_data['availability'] : array(),
                'has_booking_data'  => ! empty( $booking_data ),
                'is_slot_based'     => ! empty( $booking_data ),
            );
        }

        wp_reset_postdata();

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
