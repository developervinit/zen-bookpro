<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Slot_Service {
    /**
     * Get processed slots for a booking product and selected date.
     *
     * @param WC_Product $product       Product object.
     * @param string     $selected_date Date in Y-m-d format.
     * @param string     $mode          Product mode.
     *
     * @return array
     */
    public function get_slots_for_product( $product, $selected_date, $mode ) {
        $product = $this->get_booking_product( $product );
// var_dump("Here 1");
        if ( ! $product ) {
            return array();
        }

        $date_context = $this->normalize_date( $selected_date );
        $from         = $date_context['from'];
        $to           = $date_context['to'];
//var_dump($date_context);
        if ( ! method_exists( $product, 'get_blocks_in_range' ) ) {
            return array();
        }
// var_dump("Here 2");
        $this->debug_log(
            array(
                'stage'        => 'booking_product_loaded',
                'product_id'   => (int) $product->get_id(),
                'product_type' => 'booking',
            )
        );

        $availability_rules = method_exists( $product, 'get_availability' ) ? $product->get_availability() : array();
        if ( ! is_array( $availability_rules ) ) {
            $availability_rules = array();
        }
// var_dump("Here 3");
        $this->debug_log(
            array(
                'stage'              => 'availability_detected',
                'product_id'         => (int) $product->get_id(),
                'selected_date'      => $date_context['date'],
                'availability_rules' => $availability_rules,
            )
        );

        $blocks = $product->get_blocks_in_range( $from, $to );
//var_dump($blocks);
        if ( method_exists( $product, 'get_available_blocks' ) ) {
            $blocks = $product->get_available_blocks(
                array(
                    'blocks' => $blocks,
                    'from'   => $from,
                    'to'     => $to,
                )
            );
        }
// var_dump('here4');
// var_dump($blocks);
        if ( ! is_array( $blocks ) ) {
            $blocks = array();
        }

        $this->debug_log(
            array(
                'stage'                  => 'blocks_generated',
                'product_id'             => (int) $product->get_id(),
                'generated_blocks_count' => count( $blocks ),
                'generated_blocks'       => $blocks,
            )
        );

        $slots = $this->map_blocks_to_slots( $product, $blocks, $date_context['date'] );

        $final_slots = $this->apply_mode_logic( $slots, $mode );

        $this->debug_log(
            array(
                'stage'          => 'slots_finalized',
                'product_id'     => (int) $product->get_id(),
                'mode'           => $mode,
                'slots_returned' => $final_slots,
            )
        );

        return $final_slots;
    }

    /**
     * Resolve a WC_Product_Booking instance from mixed product input.
     *
     * @param mixed $product Product object or ID.
     *
     * @return WC_Product_Booking|null
     */
    private function get_booking_product( $product ) {
        $product_id = 0;

        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            $product_id = (int) $product->get_id();
        } elseif ( is_numeric( $product ) ) {
            $product_id = absint( $product );
        }

        if ( $product_id <= 0 ) {
            return null;
        }

        $resolved_product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

        if ( ! $resolved_product || ! method_exists( $resolved_product, 'is_type' ) || ! $resolved_product->is_type( 'booking' ) ) {
            return null;
        }

        if ( class_exists( 'WC_Product_Booking' ) && ! ( $resolved_product instanceof WC_Product_Booking ) ) {
            $booking_product = new WC_Product_Booking( $product_id );
            if ( $booking_product && method_exists( $booking_product, 'is_type' ) && $booking_product->is_type( 'booking' ) ) {
                return $booking_product;
            }
        }

        return $resolved_product;
    }

    /**
     * Normalize selected date and return timestamp range.
     *
     * @param string $selected_date Date string.
     *
     * @return array
     */
    private function normalize_date( $selected_date ) {
        $timezone = wp_timezone();
        $date     = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $selected_date, $timezone );

        if ( ! $date ) {
            $date = new DateTimeImmutable( 'now', $timezone );
        }

        $date = $date->setTime( 0, 0, 0 );

        return array(
            'date' => $date->format( 'Y-m-d' ),
            'from' => $date->getTimestamp(),
            'to'   => $date->setTime( 23, 59, 59 )->getTimestamp(),
        );
    }

    /**
     * Convert booking blocks to slot payload.
     *
     * @param WC_Product $product    Product object.
     * @param mixed      $blocks     Blocks payload.
     * @param string     $date_match Selected date.
     *
     * @return array
     */
    private function map_blocks_to_slots( $product, $blocks, $date_match ) {
        if ( empty( $blocks ) || ! is_array( $blocks ) ) {
            return array();
        }

        $duration      = method_exists( $product, 'get_duration' ) ? max( 1, absint( $product->get_duration() ) ) : 1;
        $duration_unit = method_exists( $product, 'get_duration_unit' ) ? $product->get_duration_unit() : 'minute';
        $now           = current_time( 'timestamp' );

        $slots = array();

        foreach ( $blocks as $block_key => $block ) {
            $start = $this->extract_block_start( $block );

            // WooCommerce Bookings can return block timestamps as array keys:
            // [1778068800 => 0, 1778072400 => 0, ...]
            if ( $start <= 0 && is_numeric( $block_key ) ) {
                $start = (int) $block_key;
            }

            if ( $start <= 0 ) {
                continue;
            }

            if ( wp_date( 'Y-m-d', $start ) !== $date_match ) {
                continue;
            }

            $end = strtotime( sprintf( '+%d %s', $duration, $duration_unit ), $start );
            if ( false === $end ) {
                $end = $start;
            }

            $status = 'available';

            if ( $end < $now ) {
                $status = 'expired';
            } elseif ( $this->is_slot_full( $product, $start, $end ) ) {
                $status = 'full';
            }

            $slots[] = array(
                'start'     => wp_date( 'Y-m-d H:i:s', $start ),
                'end'       => wp_date( 'Y-m-d H:i:s', $end ),
                'label'     => wp_date( 'g:i A', $start ) . ' - ' . wp_date( 'g:i A', $end ),
                'timestamp' => $start,
                'status'    => $status,
            );
        }

        usort(
            $slots,
            static function ( $a, $b ) {
                return $a['timestamp'] <=> $b['timestamp'];
            }
        );
// var_dump($slots);
        return $slots;
    }

    /**
     * Extract block start timestamp from mixed block payload.
     *
     * @param mixed $block Block payload.
     *
     * @return int
     */
    private function extract_block_start( $block ) {
        if ( is_numeric( $block ) ) {
            return (int) $block;
        }

        if ( is_array( $block ) ) {
            if ( isset( $block['start'] ) && is_numeric( $block['start'] ) ) {
                return (int) $block['start'];
            }

            if ( isset( $block[0] ) && is_numeric( $block[0] ) ) {
                return (int) $block[0];
            }
        }

        return 0;
    }

    /**
     * Determine slot-full status using WooCommerce Bookings native helper.
     *
     * @param WC_Product $product Product object.
     * @param int        $start   Slot start timestamp.
     * @param int        $end     Slot end timestamp.
     *
     * @return bool
     */
    private function is_slot_full( $product, $start, $end ) {
        if ( function_exists( 'wc_bookings_get_total_available_bookings_for_range' ) ) {
            $available = wc_bookings_get_total_available_bookings_for_range( $product, $start, $end, null, 1 );

            if ( is_wp_error( $available ) || false === $available ) {
                return true;
            }

            if ( is_numeric( $available ) && (int) $available <= 0 ) {
                return true;
            }

            if ( is_array( $available ) && empty( $available ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply mode-specific slot output logic.
     *
     * @param array  $slots Slots array.
     * @param string $mode  Product mode.
     *
     * @return array
     */
    private function apply_mode_logic( $slots, $mode ) {
        if ( 'event' === $mode ) {
            return empty( $slots ) ? array() : array( reset( $slots ) );
        }

        return $slots;
    }

    /**
     * Debug logger.
     *
     * @param array $payload Debug payload.
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


