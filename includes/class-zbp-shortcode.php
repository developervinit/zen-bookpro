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
        error_log( '[ZBP Debug] register_assets() fired on wp_enqueue_scripts.' );
        $script_url = ZBP_PLUGIN_URL . 'public/assets/js/script.js';
        $script_path = ZBP_PLUGIN_PATH . 'public/assets/js/script.js';
        $style_url = ZBP_PLUGIN_URL . 'public/assets/css/style.css';
        $style_path = ZBP_PLUGIN_PATH . 'public/assets/css/style.css';
        error_log( '[ZBP Debug] Script URL generated: ' . $script_url );

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

        error_log(
            'ZBP Debug: ' . wp_json_encode(
                array(
                    'stage' => 'ajax_request',
                    'date'  => $date,
                )
            )
        );

        $filters = array(
            'experience_category' => isset( $_POST['experience'] ) ? absint( wp_unslash( $_POST['experience'] ) ) : 0,
            'activity_type'       => isset( $_POST['activity'] ) ? absint( wp_unslash( $_POST['activity'] ) ) : 0,
            'selected_date'       => $date,
        );

        $products = $this->product_service->get_products( $filters );

        $response_products = array_map(
            function ( $product ) {
                $slots = isset( $product['slots'] ) && is_array( $product['slots'] ) ? $product['slots'] : array();
                $mode  = isset( $product['mode'] ) ? sanitize_key( $product['mode'] ) : 'free_flow';

                if ( 'event' === $mode && ! empty( $slots ) ) {
                    $slots = array( reset( $slots ) );
                }

                return array(
                    'id'          => isset( $product['id'] ) ? absint( $product['id'] ) : 0,
                    'name'        => isset( $product['title'] ) ? sanitize_text_field( $product['title'] ) : '',
                    'mode'        => $mode,
                    'duration'    => ! empty( $product['zen_duration'] ) ? sanitize_text_field( $product['zen_duration'] ) : ( isset( $product['duration'] ) ? sanitize_text_field( $product['duration'] ) : '' ),
                    'zen_coins'   => isset( $product['zen_coins'] ) ? sanitize_text_field( $product['zen_coins'] ) : '',
                    'image'       => isset( $product['image'] ) ? esc_url_raw( $product['image'] ) : '',
                    'price'       => isset( $product['price_html'] ) ? wp_strip_all_tags( $product['price_html'] ) : '',
                    'slots'       => $slots,
                    'max_spots'   => isset( $product['max_spots'] ) ? (int) $product['max_spots'] : 1,
                    'booked_spots'=> isset( $product['booked_spots'] ) ? (int) $product['booked_spots'] : 0,
                    'debug_info'  => isset( $product['debug_info'] ) ? $product['debug_info'] : array(),
                );
            },
            $products
        );

        error_log(
            'ZBP Debug: ' . wp_json_encode(
                array(
                    'stage'          => 'ajax_response',
                    'products_count' => count( $response_products ),
                )
            )
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

        error_log( '[ZBP Debug] render_shortcode() called. Enqueuing zbp-style and zbp-script.' );
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
