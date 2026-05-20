<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Shortcode {
    /**
     * Product service instance.
     *
     * @var ZBP_Product_Service
     */
    private $product_service;

    /**
     * Constructor.
     *
     * @param ZBP_Product_Service $product_service Product service.
     */
    public function __construct( $product_service ) {
        $this->product_service = $product_service;
    }

    /**
     * Register shortcode and front-end assets.
     *
     * @return void
     */
    public function register() {
        add_shortcode( 'zen_bookpro', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'wp_ajax_zbp_get_slots', array( $this, 'ajax_get_slots' ) );
        add_action( 'wp_ajax_nopriv_zbp_get_slots', array( $this, 'ajax_get_slots' ) );
    }

    /**
     * Register public assets.
     *
     * @return void
     */
    public function register_assets() {
        $script_url = ZBP_PLUGIN_URL . 'public/assets/js/script.js';
        $script_path = ZBP_PLUGIN_PATH . 'public/assets/js/script.js';
        $style_url = ZBP_PLUGIN_URL . 'public/assets/css/style.css';
        $style_path = ZBP_PLUGIN_PATH . 'public/assets/css/style.css';

        $script_ver = file_exists( $script_path ) ? filemtime( $script_path ) : ZBP_VERSION;
        $style_ver  = file_exists( $style_path ) ? filemtime( $style_path ) : ZBP_VERSION;

        wp_register_style(
            'zbp-style',
            $style_url,
            array(),
            $style_ver
        );

        wp_register_script(
            'zbp-script',
            $script_url,
            array(),
            $script_ver,
            true
        );

        wp_localize_script(
            'zbp-script',
            'zbpAjax',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'zbp_get_slots' ),
            )
        );
    }

    /**
     * Handle AJAX request to fetch products and slots for selected date.
     *
     * @return void
     */
    public function ajax_get_slots() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'zbp_get_slots' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid request.', 'zen-bookpro' ),
                ),
                403
            );
        }

        $raw_date = isset( $_POST['date'] ) ? wp_unslash( $_POST['date'] ) : '';
        $date     = sanitize_text_field( (string) $raw_date );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid date format. Use YYYY-MM-DD.', 'zen-bookpro' ),
                ),
                400
            );
        }



        $filters = array(
            'experience_category' => isset( $_POST['experience'] ) ? absint( wp_unslash( $_POST['experience'] ) ) : 0,
            'activity_type'       => isset( $_POST['activity'] ) ? absint( wp_unslash( $_POST['activity'] ) ) : 0,
            'selected_date'       => $date,
        );

        $products = $this->product_service->get_products( $filters );

        $response_products = array_map(
            function ( $product ) {
                return array(
                    'id'              => isset( $product['id'] ) ? absint( $product['id'] ) : 0,
                    'name'            => isset( $product['title'] ) ? sanitize_text_field( $product['title'] ) : '',
                    'description'     => isset( $product['description'] ) ? sanitize_textarea_field( $product['description'] ) : '',
                    'cancellation_policy' => isset( $product['cancellation_policy'] ) ? sanitize_textarea_field( $product['cancellation_policy'] ) : '',
                    'location'        => isset( $product['location'] ) ? sanitize_text_field( $product['location'] ) : '',
                    'mode'            => isset( $product['mode'] ) ? sanitize_key( $product['mode'] ) : 'free_flow',
                    'duration'        => isset( $product['duration'] ) ? sanitize_text_field( $product['duration'] ) : '',
                    'zen_duration'    => isset( $product['zen_duration'] ) ? sanitize_text_field( $product['zen_duration'] ) : '',
                    'booking_duration_minutes' => isset( $product['booking_duration_minutes'] ) ? (int) $product['booking_duration_minutes'] : 0,
                    'experience_category' => isset( $product['experience_category'] ) ? sanitize_text_field( $product['experience_category'] ) : '',
                    'zen_coins'       => isset( $product['zen_coins'] ) ? sanitize_text_field( $product['zen_coins'] ) : '',
                    'image'           => isset( $product['image'] ) ? esc_url_raw( $product['image'] ) : '',
                    'gallery'         => isset( $product['gallery'] ) ? $product['gallery'] : array(),
                    'price_html'      => isset( $product['price_html'] ) ? $product['price_html'] : '',
                    'slots'           => isset( $product['slots'] ) ? $product['slots'] : array(),
                    'max_spots'       => isset( $product['max_spots'] ) ? (int) $product['max_spots'] : 1,
                    'booked_spots'    => isset( $product['booked_spots'] ) ? (int) $product['booked_spots'] : 0,
                    'event_status'    => isset( $product['event_status'] ) ? $product['event_status'] : 'join',
                    'slot_debug'      => isset( $product['slot_debug'] ) ? $product['slot_debug'] : '',
                    'zen_instructor'  => isset( $product['zen_instructor'] ) ? sanitize_text_field( $product['zen_instructor'] ) : '',
                );
            },
            $products
        );

        wp_send_json_success(
            array(
                'products' => $response_products,
            )
        );
    }

    /**
     * Render shortcode template.
     *
     * @param array $atts Shortcode attributes.
     *
     * @return string
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'product_id' => '',
                'experience' => '',
                'activity'   => '',
                'date'       => '',
            ),
            $atts,
            'zen_bookpro'
        );

        wp_enqueue_style( 'zbp-style' );
        wp_enqueue_script( 'zbp-script' );

        $template_file = ZBP_PLUGIN_PATH . 'public/templates/booking-ui.php';

        if ( ! file_exists( $template_file ) ) {
            return '';
        }

        $experience_filter = isset( $_GET['experience'] ) ? wp_unslash( $_GET['experience'] ) : $atts['experience'];
        $activity_filter   = isset( $_GET['activity'] ) ? wp_unslash( $_GET['activity'] ) : $atts['activity'];
        $date_filter       = isset( $_GET['date'] ) ? wp_unslash( $_GET['date'] ) : $atts['date'];

        $filters = array(
            'experience_category' => absint( $experience_filter ),
            'activity_type'       => absint( $activity_filter ),
            'selected_date'       => sanitize_text_field( $date_filter ),
        );

        $products = $this->product_service->get_products( $filters );

        ob_start();
        $product_id = $atts['product_id'];
        include $template_file;

        return ob_get_clean();
    }
}

