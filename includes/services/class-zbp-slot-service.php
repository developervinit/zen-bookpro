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
            return array();
        }

        $date_context = $this->normalize_date( $selected_date );
        $form_payload = $this->build_native_booking_form_payload( $product, $date_context );
        $form_encoded = http_build_query( $form_payload, '', '&' );

        $this->debug_pre_dump( 'zbp_wc_native_payload', $form_payload );
        echo '<pre>';
        print_r(
            array(
                'debug_label' => 'zbp_wc_native_payload',
                'payload'     => $form_payload,
            )
        );
        echo '</pre>';

        $slot_html = $this->request_native_woo_slots_html( $form_encoded );
        $this->debug_pre_dump( 'zbp_wc_native_html', $slot_html );
        echo '<pre>';
        print_r(
            array(
                'debug_label' => 'zbp_wc_native_html',
                'html'        => $slot_html,
            )
        );
        echo '</pre>';

        $slots = $this->parse_slots_from_woo_html( $slot_html, $date_context['date'] );
        $this->debug_pre_dump( 'zbp_wc_parsed_slots', $slots );
        echo '<pre>';
        print_r(
            array(
                'debug_label' => 'zbp_wc_parsed_slots',
                'slots'       => $slots,
            )
        );
        echo '</pre>';

        return $this->apply_mode_logic( $slots, $mode );
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
            if ( is_array( $resources ) && 1 === count( $resources ) ) {
                $resource = reset( $resources );
                if ( is_object( $resource ) && isset( $resource->ID ) ) {
                    $payload['wc_bookings_field_resource'] = (int) $resource->ID;
                }
            }
        }

        return $payload;
    }

    /**
     * Reuse native Woo endpoint by POSTing action=wc_bookings_get_blocks.
     *
     * @param string $encoded_form Serialized booking form payload.
     *
     * @return string
     */
    private function request_native_woo_slots_html( $encoded_form ) {
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
            $this->debug_log(
                array(
                    'stage'   => 'native_woo_request_error',
                    'message' => $response->get_error_message(),
                )
            );
            return '';
        }

        $body = wp_remote_retrieve_body( $response );

        return is_string( $body ) ? $body : '';
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

        $timestamp = strtotime( substr( $value, 0, 19 ) );
        if ( false === $timestamp ) {
            $timestamp = strtotime( $target_date . ' ' . $label );
        }
        if ( false === $timestamp ) {
            $timestamp = 0;
        }

        $status = ( $timestamp > 0 && $timestamp < current_time( 'timestamp' ) ) ? 'expired' : 'available';

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

        return $slots;
    }

    /**
     * Optional development debug dumps.
     *
     * @param string $label Debug label.
     * @param mixed  $data  Debug payload.
     *
     * @return void
     */
    private function debug_pre_dump( $label, $data ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $debug_buffer  = '<pre>';
        $debug_buffer .= $label . "\n";
        $debug_buffer .= print_r( $data, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
        $debug_buffer .= '</pre>';

        error_log( wp_strip_all_tags( $debug_buffer ) );
    }

    /**
     * Structured debug logger.
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
