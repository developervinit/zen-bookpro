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
    }

    /**
     * Register public assets.
     *
     * @return void
     */
    public function register_assets() {
        wp_register_style(
            'zbp-style',
            ZBP_PLUGIN_URL . 'public/assets/css/style.css',
            array(),
            ZBP_VERSION
        );

        wp_register_script(
            'zbp-script',
            ZBP_PLUGIN_URL . 'public/assets/js/script.js',
            array(),
            ZBP_VERSION,
            true
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

        $filters = array(
            'experience_category' => absint( $experience_filter ),
            'activity_type'       => absint( $activity_filter ),
        );

        $products = $this->product_service->get_products( $filters );

        ob_start();
        $product_id = $atts['product_id'];
        include $template_file;

        return ob_get_clean();
    }
}
