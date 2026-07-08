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
     * Waitlist service.
     *
     * @var ZBP_Waitlist_Service
     */
    private $waitlist_service;

    /**
     * Constructor.
     *
     * @param ZBP_Product_Service  $product_service  Product service.
     * @param ZBP_Waitlist_Service $waitlist_service Waitlist service.
     */
    public function __construct( $product_service, $waitlist_service = null ) {
        $this->product_service  = $product_service;
        $this->waitlist_service = $waitlist_service ?: new ZBP_Waitlist_Service();
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

        register_setting(
            'zbp_settings_group',
            'zbp_waitlist_expiry_value',
            array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 20,
            )
        );

        register_setting(
            'zbp_settings_group',
            'zbp_waitlist_expiry_unit',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => 'minutes',
            )
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

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'product_selection';
        $tabs = array(
            'product_selection'   => __( 'Product Selection', 'zen-bookpro' ),
            'waitlist_settings'   => __( 'Waitlist Settings', 'zen-bookpro' ),
            'waitlist_management' => __( 'Waitlist Management', 'zen-bookpro' ),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Zen-BookPro Settings', 'zen-bookpro' ); ?></h1>
            
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <?php foreach ( $tabs as $tab_key => $tab_caption ) : ?>
                    <a href="?page=zen-bookpro-settings&tab=<?php echo esc_attr( $tab_key ); ?>" class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_caption ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php if ( 'product_selection' === $active_tab ) : 
                $selected_ids      = $this->product_service->get_selected_product_ids();
                $bookable_products = $this->product_service->get_all_bookable_products();
                ?>
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
            <?php elseif ( 'waitlist_settings' === $active_tab ) : ?>
                <form method="post" action="options.php">
                    <?php settings_fields( 'zbp_settings_group' ); ?>

                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 1062px;">
                        <h2 style="margin-top:0; font-size:16px; border-bottom:1px solid #eee; padding-bottom:8px;"><?php esc_html_e( 'Waitlist Expiry Configuration', 'zen-bookpro' ); ?></h2>
                        <p style="margin-bottom:20px; color:#666;"><?php esc_html_e( 'Configure how long an invited customer has to respond before their waitlist invitation expires.', 'zen-bookpro' ); ?></p>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <label for="zbp_waitlist_expiry_value" style="font-weight: bold;"><?php esc_html_e( 'Waitlist Response Time', 'zen-bookpro' ); ?></label>
                                    </th>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <input
                                                type="number"
                                                id="zbp_waitlist_expiry_value"
                                                name="zbp_waitlist_expiry_value"
                                                value="<?php echo esc_attr( get_option( 'zbp_waitlist_expiry_value', 20 ) ); ?>"
                                                min="1"
                                                style="width: 80px;"
                                                required
                                            />
                                            <select name="zbp_waitlist_expiry_unit" style="min-width: 120px;">
                                                <option value="minutes" <?php selected( get_option( 'zbp_waitlist_expiry_unit', 'minutes' ), 'minutes' ); ?>><?php esc_html_e( 'Minutes', 'zen-bookpro' ); ?></option>
                                                <option value="hours" <?php selected( get_option( 'zbp_waitlist_expiry_unit', 'hours' ), 'hours' ); ?>><?php esc_html_e( 'Hours', 'zen-bookpro' ); ?></option>
                                                <option value="days" <?php selected( get_option( 'zbp_waitlist_expiry_unit', 'days' ), 'days' ); ?>><?php esc_html_e( 'Days', 'zen-bookpro' ); ?></option>
                                            </select>
                                        </div>
                                        <p class="description"><?php esc_html_e( 'This determines the duration of the booking reservation window for new invitations.', 'zen-bookpro' ); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <?php submit_button( __( 'Save Expiry Settings', 'zen-bookpro' ) ); ?>
                </form>
            <?php else : ?>
                <?php $this->render_waitlist_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Waitlist Management tab content.
     *
     * @return void
     */
    public function render_waitlist_tab() {
        // Retrieve and sanitize filters
        $product_id = isset( $_GET['zbp_filter_product'] ) ? absint( $_GET['zbp_filter_product'] ) : 0;
        $status     = isset( $_GET['zbp_filter_status'] ) ? sanitize_text_field( $_GET['zbp_filter_status'] ) : '';
        $event_date = isset( $_GET['zbp_filter_date'] ) ? sanitize_text_field( $_GET['zbp_filter_date'] ) : '';
        $search     = isset( $_GET['zbp_search'] ) ? sanitize_text_field( $_GET['zbp_search'] ) : '';

        // Query entries
        $filters = array(
            'product_id' => $product_id,
            'status'     => $status,
            'event_date' => $event_date,
            'search'     => $search,
        );

        $entries = $this->waitlist_service->query_waitlist_entries( $filters );

        // Fetch Event products for the dropdown filter
        $bookable_products = $this->product_service->get_all_bookable_products();
        $event_products    = array();
        foreach ( $bookable_products as $prod ) {
            if ( 'event' === get_post_meta( $prod->get_id(), '_zbp_product_mode', true ) ) {
                $event_products[] = $prod;
            }
        }
        ?>
        <p><?php esc_html_e( 'View the complete waitlist records. Search or filter by product, status, and event date.', 'zen-bookpro' ); ?></p>

        <!-- Filter Bar -->
        <form method="get" action="" style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="page" value="zen-bookpro-settings" />
            <input type="hidden" name="tab" value="waitlist_management" />

            <!-- Product filter -->
            <div>
                <label for="zbp_filter_product" style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;"><?php esc_html_e( 'Product', 'zen-bookpro' ); ?></label>
                <select name="zbp_filter_product" id="zbp_filter_product" style="min-width: 200px;">
                    <option value=""><?php esc_html_e( 'All Event Products', 'zen-bookpro' ); ?></option>
                    <?php foreach ( $event_products as $prod ) : ?>
                        <option value="<?php echo esc_attr( $prod->get_id() ); ?>" <?php selected( $product_id, $prod->get_id() ); ?>>
                            <?php echo esc_html( $prod->get_name() ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Date filter -->
            <div>
                <label for="zbp_filter_date" style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;"><?php esc_html_e( 'Event Date', 'zen-bookpro' ); ?></label>
                <input type="date" name="zbp_filter_date" id="zbp_filter_date" value="<?php echo esc_attr( $event_date ); ?>" style="line-height:20px;" />
            </div>

            <!-- Status filter -->
            <div>
                <label for="zbp_filter_status" style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;"><?php esc_html_e( 'Status', 'zen-bookpro' ); ?></label>
                <select name="zbp_filter_status" id="zbp_filter_status" style="min-width: 150px;">
                    <option value=""><?php esc_html_e( 'All Statuses', 'zen-bookpro' ); ?></option>
                    <option value="waiting" <?php selected( $status, 'waiting' ); ?>><?php esc_html_e( 'Waiting', 'zen-bookpro' ); ?></option>
                    <option value="invited" <?php selected( $status, 'invited' ); ?>><?php esc_html_e( 'Invited', 'zen-bookpro' ); ?></option>
                    <option value="booked" <?php selected( $status, 'booked' ); ?>><?php esc_html_e( 'Booked', 'zen-bookpro' ); ?></option>
                    <option value="expired" <?php selected( $status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'zen-bookpro' ); ?></option>
                    <option value="left" <?php selected( $status, 'left' ); ?>><?php esc_html_e( 'Left', 'zen-bookpro' ); ?></option>
                </select>
            </div>

            <!-- Search box -->
            <div>
                <label for="zbp_search" style="display:block; font-weight:bold; margin-bottom:4px; font-size:12px;"><?php esc_html_e( 'Search Customer', 'zen-bookpro' ); ?></label>
                <input type="text" name="zbp_search" id="zbp_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name or email...', 'zen-bookpro' ); ?>" />
            </div>

            <!-- Buttons -->
            <div style="align-self: flex-end; display: flex; gap: 8px;">
                <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Apply Filters', 'zen-bookpro' ); ?>" />
                <a href="?page=zen-bookpro-settings&tab=waitlist_management" class="button button-secondary"><?php esc_html_e( 'Clear', 'zen-bookpro' ); ?></a>
            </div>
        </form>

        <!-- Waitlist Table -->
        <table class="widefat striped" style="max-width: 1100px;">
            <thead>
                <tr>
                    <th style="width: 70px;"><?php esc_html_e( 'Priority', 'zen-bookpro' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'zen-bookpro' ); ?></th>
                    <th><?php esc_html_e( 'Event Product', 'zen-bookpro' ); ?></th>
                    <th><?php esc_html_e( 'Event Date', 'zen-bookpro' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'zen-bookpro' ); ?></th>
                    <th><?php esc_html_e( 'Joined At', 'zen-bookpro' ); ?></th>
                    <th><?php esc_html_e( 'Invitation Sent At', 'zen-bookpro' ); ?></th>
                    <th><?php esc_html_e( 'Invitation Expires At', 'zen-bookpro' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $entries ) ) : ?>
                    <tr>
                        <td colspan="8"><?php esc_html_e( 'No waitlist entries found matching the criteria.', 'zen-bookpro' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $entries as $entry ) : 
                        $entry_id       = $entry->ID;
                        $entry_status   = get_post_meta( $entry_id, '_waitlist_status', true );
                        $entry_priority = get_post_meta( $entry_id, '_waitlist_priority', true );
                        $customer_name  = get_post_meta( $entry_id, '_customer_name', true );
                        $customer_email = get_post_meta( $entry_id, '_customer_email', true );
                        $prod_id        = get_post_meta( $entry_id, '_product_id', true );
                        $event_dt       = get_post_meta( $entry_id, '_event_date', true );
                        $joined         = get_post_meta( $entry_id, '_joined_at', true );
                        $invited        = get_post_meta( $entry_id, '_invited_at', true );
                        $expires        = get_post_meta( $entry_id, '_expires_at', true );

                        $product_name   = '';
                        $product        = wc_get_product( $prod_id );
                        if ( $product ) {
                            $product_name = $product->get_name();
                        } else {
                            $product_name = '#' . $prod_id;
                        }

                        // Badge styling
                        $status_label = ucwords( $entry_status );
                        $badge_style  = 'display: inline-block; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 11px;';

                        if ( 'waiting' === $entry_status ) {
                            $badge_html = '<span style="' . $badge_style . ' background: #e3f2fd; color: #1565c0;">' . esc_html( $status_label ) . '</span>';
                        } elseif ( 'invited' === $entry_status ) {
                            $badge_html = '<span style="' . $badge_style . ' background: #fff3e0; color: #e65100;">' . esc_html( $status_label ) . '</span>';
                        } elseif ( 'booked' === $entry_status ) {
                            $badge_html = '<span style="' . $badge_style . ' background: #e8f5e9; color: #2e7d32;">' . esc_html( $status_label ) . '</span>';
                        } elseif ( 'expired' === $entry_status ) {
                            $badge_html = '<span style="' . $badge_style . ' background: #eceff1; color: #37474f;">' . esc_html( $status_label ) . '</span>';
                        } elseif ( 'left' === $entry_status ) {
                            $badge_html = '<span style="' . $badge_style . ' background: #ffebee; color: #b71c1c;">' . esc_html( $status_label ) . '</span>';
                        } else {
                            $badge_html = '<span style="' . $badge_style . ' background: #eee; color: #555;">' . esc_html( $status_label ) . '</span>';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong>
                                    <?php echo ( 'waiting' === $entry_status ) ? '#' . esc_html( $entry_priority ? $entry_priority : '—' ) : '—'; ?>
                                </strong>
                            </td>
                            <td>
                                <strong><?php echo esc_html( $customer_name ); ?></strong>
                                <div><small><?php echo esc_html( $customer_email ); ?></small></div>
                            </td>
                            <td><?php echo esc_html( $product_name ); ?></td>
                            <td><?php echo esc_html( $event_dt ); ?></td>
                            <td><?php echo $badge_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo esc_html( $joined ? wp_date( get_option( 'date_format' ) . ' H:i', intval( $joined ) ) : '—' ); ?></td>
                            <td><?php echo esc_html( $invited ? wp_date( get_option( 'date_format' ) . ' H:i', intval( $invited ) ) : '—' ); ?></td>
                            <td><?php echo esc_html( $expires ? wp_date( get_option( 'date_format' ) . ' H:i', intval( $expires ) ) : '—' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
