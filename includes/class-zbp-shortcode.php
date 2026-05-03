<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Shortcode {
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

        ob_start();
        $product_id = $atts['product_id'];
        include $template_file;

        return ob_get_clean();
    }
}
