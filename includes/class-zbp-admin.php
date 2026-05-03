<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZBP_Admin {
    /**
     * Product service.
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
     * Register admin hooks.
     *
     * @return void
     */
    public function register() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Add settings page.
     *
     * @return void
     */
    public function add_menu_page() {
        add_options_page(
            __( 'Zen-BookPro Settings', 'zen-bookpro' ),
            __( 'Zen-BookPro', 'zen-bookpro' ),
            'manage_options',
            'zen-bookpro-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings.
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            'zbp_settings_group',
            'zbp_selected_product_ids',
            array( $this, 'sanitize_selected_product_ids' )
        );
    }

    /**
     * Sanitize selected product IDs.
     *
     * @param mixed $value Raw option value.
     *
     * @return array
     */
    public function sanitize_selected_product_ids( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $ids = array_values(
            array_filter(
                array_map( 'absint', $value )
            )
        );

        return $ids;
    }

    /**
     * Render settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $selected_ids      = $this->product_service->get_selected_product_ids();
        $bookable_products = $this->product_service->get_all_bookable_products();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Zen-BookPro Product Selection', 'zen-bookpro' ); ?></h1>
            <p><?php esc_html_e( 'Choose which bookable products should appear in Zen-BookPro frontend.', 'zen-bookpro' ); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields( 'zbp_settings_group' ); ?>

                <table class="widefat striped" style="max-width: 1100px;">
                    <thead>
                        <tr>
                            <th style="width:80px;"><?php esc_html_e( 'Select', 'zen-bookpro' ); ?></th>
                            <th><?php esc_html_e( 'Product', 'zen-bookpro' ); ?></th>
                            <th><?php esc_html_e( 'Experience Category', 'zen-bookpro' ); ?></th>
                            <th><?php esc_html_e( 'Activity Type', 'zen-bookpro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $bookable_products ) ) : ?>
                            <tr>
                                <td colspan="4"><?php esc_html_e( 'No bookable products found.', 'zen-bookpro' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $bookable_products as $product ) : ?>
                                <?php $product_id = (int) $product->get_id(); ?>
                                <tr>
                                    <td>
                                        <label>
                                            <input
                                                type="checkbox"
                                                name="zbp_selected_product_ids[]"
                                                value="<?php echo esc_attr( $product_id ); ?>"
                                                <?php checked( in_array( $product_id, $selected_ids, true ) ); ?>
                                            />
                                        </label>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html( $product->get_name() ); ?></strong>
                                        <div><?php echo esc_html( '#' . $product_id ); ?></div>
                                    </td>
                                    <td><?php echo esc_html( $this->product_service->get_term_names_for_product( $product_id, 'experience_category' ) ); ?></td>
                                    <td><?php echo esc_html( $this->product_service->get_term_names_for_product( $product_id, 'activity_type' ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php submit_button( __( 'Save Selected Products', 'zen-bookpro' ) ); ?>
            </form>
        </div>
        <?php
    }
}
