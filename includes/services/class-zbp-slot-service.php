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
        if ( ! $product ) {
            return array( 'slots' => array(), 'debug' => 'No product object' );
        }

        $date_context = $this->normalize_date( $selected_date );
        $form_payload = $this->build_native_booking_form_payload( $product, $date_context );
        $form_encoded = http_build_query( $form_payload, '', '&' );

        $slot_html = $this->request_native_woo_slots_html( $form_encoded );
        $slots     = $this->parse_slots_from_woo_html( $slot_html, $date_context['date'] );

        return array(
            'slots' => $this->apply_mode_logic( $slots, $mode ),
            'debug' => array(
                'payload' => $form_payload,
                'html'    => substr($slot_html, 0, 500) . (strlen($slot_html) > 500 ? '...' : '')
            )
        );
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
            'date' => $date->format( 'Y-m-d'),
            'from' => $date->getTimestamp(),
            'to'   => $date->modify( '+1 day' )->getTimestamp(),
        );
    }

    /**
     * Build payload in native Woo booking-form field format.
     *
     * @param WC_Product_Booking $product      Booking product.
     * @param array              $date_context Normalized date payload.
     *
     * @return array
     */
    private function build_native_booking_form_payload( $product, $date_context ) {
        $date_ts = (int) $date_context['from'];

        $payload = array(
            'add-to-cart'                        => (int) $product->get_id(),
            'wc_bookings_field_start_date_year'  => wp_date( 'Y', $date_ts ),
            'wc_bookings_field_start_date_month' => wp_date( 'n', $date_ts ),
            'wc_bookings_field_start_date_day'   => wp_date( 'j', $date_ts ),
            'wc_bookings_field_duration'         => 1,
            'wc_bookings_field_qty'              => 1,
        );

        if ( method_exists( $product, 'has_persons' ) && $product->has_persons() ) {
            $min_persons = method_exists( $product, 'get_min_persons' ) ? absint( $product->get_min_persons() ) : 1;
            $payload['wc_bookings_field_persons'] = max( 1, $min_persons );
        }

        if ( method_exists( $product, 'has_resources' ) && $product->has_resources() && method_exists( $product, 'is_resource_assignment_type' ) && $product->is_resource_assignment_type( 'customer' ) ) {
            $resources = method_exists( $product, 'get_resources' ) ? $product->get_resources() : array();
            $resource_id = $this->resolve_first_valid_resource_id( $resources );

            if ( $resource_id > 0 ) {
                $payload['wc_bookings_field_resource'] = $resource_id;
            }
        }

        return $payload;
    }

    /**
     * Pick first valid resource ID from customer-selectable resources.
     *
     * @param array $resources Product resources.
     *
     * @return int
     */
    private function resolve_first_valid_resource_id( $resources ) {
        if ( ! is_array( $resources ) || empty( $resources ) ) {
            return 0;
        }

        $fallback_id = 0;

        foreach ( $resources as $resource ) {
            if ( ! is_object( $resource ) ) {
                continue;
            }

            $resource_id = 0;
            if ( isset( $resource->ID ) ) {
                $resource_id = (int) $resource->ID;
            } elseif ( method_exists( $resource, 'get_id' ) ) {
                $resource_id = (int) $resource->get_id();
            }

            if ( $resource_id <= 0 ) {
                continue;
            }

            if ( 0 === $fallback_id ) {
                $fallback_id = $resource_id;
            }

            if ( method_exists( $resource, 'get_qty' ) ) {
                $qty = (int) $resource->get_qty();
                if ( $qty > 0 ) {
                    return $resource_id;
                }
                continue;
            }

            return $resource_id;
        }

        return $fallback_id;
    }

    /**
     * Compact resource debug formatter.
     *
     * @param array $resources Product resources.
     *
     * @return array
     */
    private function format_resource_debug( $resources ) {
        if ( ! is_array( $resources ) ) {
            return array();
        }

        $formatted = array();
        foreach ( $resources as $resource ) {
            if ( ! is_object( $resource ) ) {
                continue;
            }

            $id = 0;
            if ( isset( $resource->ID ) ) {
                $id = (int) $resource->ID;
            } elseif ( method_exists( $resource, 'get_id' ) ) {
                $id = (int) $resource->get_id();
            }

            $name = method_exists( $resource, 'get_name' ) ? $resource->get_name() : '';
            $qty  = method_exists( $resource, 'get_qty' ) ? (int) $resource->get_qty() : null;

            $formatted[] = array(
                'id'   => $id,
                'name' => $name,
                'qty'  => $qty,
            );
        }

        return $formatted;
    }

    /**
     * Reuse Woo Bookings slot rendering without making a nested HTTP request.
     *
     * @param string $encoded_form Serialized booking form payload.
     *
     * @return string
     */
    private function request_native_woo_slots_html( $encoded_form ) {
        $direct_html = $this->render_native_woo_slots_html( $encoded_form );
        if ( null !== $direct_html ) {
            return $direct_html;
        }

        $response = wp_remote_post(
            admin_url( 'admin-ajax.php' ),
            array(
                'timeout' => 20,
                'body'    => array(
                    'action' => 'wc_bookings_get_blocks',
                    'form'   => $encoded_form,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = wp_remote_retrieve_body( $response );
        return is_string( $body ) ? $body : '';
    }

    /**
     * Render the same slot HTML as WC_Bookings_Ajax::get_time_blocks_for_date().
     *
     * @param string $encoded_form Serialized booking form payload.
     *
     * @return string|null HTML string, or null when Woo internals are unavailable.
     */
    private function render_native_woo_slots_html( $encoded_form ) {
        if ( ! class_exists( 'WC_Booking_Form' ) || ! function_exists( 'wc_get_product' ) || ! function_exists( 'get_wc_product_booking' ) ) {
            return null;
        }

        $posted = array();
        parse_str( (string) $encoded_form, $posted );

        if ( empty( $posted['add-to-cart'] ) ) {
            return '';
        }

        $booking_id = absint( $posted['add-to-cart'] );
        $product    = get_wc_product_booking( wc_get_product( $booking_id ) );
        if ( ! $product ) {
            return '';
        }

        $timestamp = 0;
        if ( ! empty( $posted['wc_bookings_field_start_date_year'] ) && ! empty( $posted['wc_bookings_field_start_date_month'] ) && ! empty( $posted['wc_bookings_field_start_date_day'] ) ) {
            $year      = max( date( 'Y' ), absint( $posted['wc_bookings_field_start_date_year'] ) );
            $month     = absint( $posted['wc_bookings_field_start_date_month'] );
            $day       = absint( $posted['wc_bookings_field_start_date_day'] );
            $timestamp = strtotime( "{$year}-{$month}-{$day}" );
        }

        if ( empty( $timestamp ) ) {
            return '<li>' . esc_html__( 'Please enter a valid date.', 'woocommerce-bookings' ) . '</li>';
        }

        if ( ! empty( $posted['wc_bookings_field_duration'] ) ) {
            $interval = (int) $posted['wc_bookings_field_duration'] * $product->get_duration();
        } else {
            $interval = $product->get_duration();
        }

        $base_interval = $product->get_duration();

        if ( 'hour' === $product->get_duration_unit() ) {
            $interval      *= 60;
            $base_interval *= 60;
        }

        $first_block_time = $product->get_first_block_time();
        $from             = strtotime( $first_block_time ? $first_block_time : 'midnight', $timestamp );
        $standard_from    = $from;

        if ( isset( $posted['get_prev_day'] ) ) {
            $from = strtotime( '- 1 day', $from );
        }

        $to = strtotime( '+ 1 day', $standard_from ) + $interval;
        if ( isset( $posted['get_next_day'] ) ) {
            $to = strtotime( '+ 1 day', $to );
        }

        $to = strtotime( 'midnight', $to ) - 1;

        $resource_id_to_check = ! empty( $posted['wc_bookings_field_resource'] ) ? (int) $posted['wc_bookings_field_resource'] : 0;
        $resource             = $product->get_resource( absint( $resource_id_to_check ) );
        $resources            = $product->get_resources();

        if ( $resource_id_to_check && $resource ) {
            $resource_id_to_check = $resource->ID;
        } elseif ( $product->has_resources() && $resources && 1 === count( $resources ) ) {
            $resource_id_to_check = current( $resources )->ID;
        } else {
            $resource_id_to_check = 0;
        }

        $booking_form = new WC_Booking_Form( $product );
        $blocks       = $product->get_blocks_in_range( $from, $to, array( $interval, $base_interval ), $resource_id_to_check );
        $block_html   = $booking_form->get_time_slots_html( $blocks, array( $interval, $base_interval ), $resource_id_to_check, $from, $to );

        if ( empty( $block_html ) ) {
            $block_html = '<li>' . esc_html__( 'No blocks available.', 'woocommerce-bookings' ) . '</li>';
        }

        return $block_html;
    }

    /**
     * Parse WooCommerce returned HTML into structured slot rows.
     *
     * @param string $html       Returned HTML from wc_bookings_get_blocks.
     * @param string $target_date Selected date in Y-m-d.
     *
     * @return array
     */
    private function parse_slots_from_woo_html( $html, $target_date ) {
        if ( '' === trim( (string) $html ) ) {
            return array();
        }

        $slots = array();

        if ( class_exists( 'DOMDocument' ) ) {
            $dom = new DOMDocument();
            libxml_use_internal_errors( true );
            $dom->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
            libxml_clear_errors();

            $xpath = new DOMXPath( $dom );

            // Non-customer durations: <li class="block"><a data-value="ISO">Label</a></li>
            $anchors = $xpath->query( "//li[contains(concat(' ', normalize-space(@class), ' '), ' block ')]/a[@data-value]" );
            if ( $anchors instanceof DOMNodeList ) {
                foreach ( $anchors as $anchor ) {
                    $label = trim( preg_replace( '/\s+/', ' ', $anchor->textContent ) );
                    $value = trim( $anchor->getAttribute( 'data-value' ) );
                    $slot  = $this->build_slot_row( $label, $value, $target_date );
                    if ( ! empty( $slot ) ) {
                        $slots[] = $slot;
                    }
                }
            }

            // Customer durations: select start-time options.
            if ( empty( $slots ) ) {
                $options = $xpath->query( "//select[@id='wc-bookings-form-start-time']/option[@value!='0']" );
                if ( $options instanceof DOMNodeList ) {
                    foreach ( $options as $option ) {
                        $label = trim( preg_replace( '/\s+/', ' ', $option->textContent ) );
                        $value = trim( $option->getAttribute( 'value' ) );
                        $slot  = $this->build_slot_row( $label, $value, $target_date );
                        if ( ! empty( $slot ) ) {
                            $slots[] = $slot;
                        }
                    }
                }
            }
        }

        // Final fallback: parse list items by regex.
        if ( empty( $slots ) && preg_match_all( '/<li[^>]*class="[^"]*block[^"]*"[^>]*>\s*<a[^>]*data-value="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $value = isset( $match[1] ) ? html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) : '';
                $label = isset( $match[2] ) ? trim( wp_strip_all_tags( html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ) ) ) : '';
                $slot  = $this->build_slot_row( $label, $value, $target_date );
                if ( ! empty( $slot ) ) {
                    $slots[] = $slot;
                }
            }
        }

        $slots = $this->unique_slots( $slots );

        usort(
            $slots,
            static function( $a, $b ) {
                return (int) $a['timestamp'] <=> (int) $b['timestamp'];
            }
        );

        return $slots;
    }

    /**
     * Normalize label/value into slot object.
     *
     * @param string $label       Slot label from Woo HTML.
     * @param string $value       ISO value from Woo HTML.
     * @param string $target_date Selected date.
     *
     * @return array
     */
    private function build_slot_row( $label, $value, $target_date ) {
        $label = trim( (string) $label );
        $value = trim( (string) $value );

        if ( '' === $label ) {
            return array();
        }

        // Extract start time part from label (e.g. "17:00" from "17:00 - 18:00")
        $start_time = $label;
        $parts = preg_split( '/\s*[-\x{2013}]\s*/u', $label );
        if ( isset( $parts[0] ) && '' !== trim( $parts[0] ) ) {
            $start_time = trim( $parts[0] );
        }

        // Try to parse the time in the WordPress local timezone.
        $timestamp = 0;
        try {
            $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
            $date = new DateTime( $target_date . ' ' . $start_time, $timezone );
            $timestamp = $date->getTimestamp();
        } catch ( Exception $e ) {
            $timestamp = 0;
        }

        if ( 0 === $timestamp ) {
            // Parse full ISO-8601 value from Woo (including timezone offset).
            $timestamp = strtotime( $value );
            if ( false === $timestamp ) {
                $timestamp = strtotime( $target_date . ' ' . $label );
            }
            if ( false === $timestamp ) {
                $timestamp = 0;
            }
        }

        $status = ( $timestamp > 0 && $timestamp < time() ) ? 'expired' : 'available';

        return array(
            'start'     => $timestamp > 0 ? wp_date( 'Y-m-d H:i:s', $timestamp ) : '',
            'end'       => '',
            'label'     => $label,
            'timestamp' => (int) $timestamp,
            'status'    => $status,
            'value'     => $value,
        );
    }

    /**
     * Remove duplicate slots.
     *
     * @param array $slots Slots list.
     *
     * @return array
     */
    private function unique_slots( $slots ) {
        $seen   = array();
        $unique = array();

        foreach ( $slots as $slot ) {
            $key = (string) ( $slot['value'] ?? '' ) . '|' . (string) ( $slot['label'] ?? '' );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $unique[]     = $slot;
        }

        return $unique;
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

        if ( 'free_flow' === $mode ) {
            $filtered = array();
            $now      = time();
            foreach ( $slots as $slot ) {
                if ( isset( $slot['timestamp'] ) && (int) $slot['timestamp'] > 0 ) {
                    // Filter out slots that have already passed according to native time
                    if ( (int) $slot['timestamp'] < $now ) {
                        continue;
                    }
                }
                $filtered[] = $slot;
            }
            return $filtered;
        }

        return $slots;
    }

}
