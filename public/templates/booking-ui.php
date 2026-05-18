<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="zbp-booking-ui" data-product-id="<?php echo esc_attr( $product_id ); ?>">
    <div class="zbp-week-header">
        <button class="zbp-nav-btn zbp-nav-prev" type="button" aria-label="Previous week">&#10094;</button>
        <div class="zbp-week-range">23.02. - 01.03.2026</div>
        <button class="zbp-nav-btn zbp-nav-next" type="button" aria-label="Next week">&#10095;</button>
    </div>

    <div class="zbp-date-row" role="tablist" aria-label="Booking dates">
        <button class="zbp-date-item" type="button" data-date="23">
            <span class="zbp-date-number">23</span>
            <span class="zbp-date-day">Monday</span>
        </button>
        <button class="zbp-date-item is-active" type="button" data-date="24">
            <span class="zbp-date-number">24</span>
            <span class="zbp-date-day">Tuesday</span>
        </button>
        <button class="zbp-date-item" type="button" data-date="25">
            <span class="zbp-date-number">25</span>
            <span class="zbp-date-day">Wednesday</span>
        </button>
        <button class="zbp-date-item" type="button" data-date="26">
            <span class="zbp-date-number">26</span>
            <span class="zbp-date-day">Thursday</span>
        </button>
        <button class="zbp-date-item" type="button" data-date="27">
            <span class="zbp-date-number">27</span>
            <span class="zbp-date-day">Friday</span>
        </button>
        <button class="zbp-date-item" type="button" data-date="28">
            <span class="zbp-date-number">28</span>
            <span class="zbp-date-day">Saturday</span>
        </button>
        <button class="zbp-date-item" type="button" data-date="29">
            <span class="zbp-date-number">29</span>
            <span class="zbp-date-day">Sunday</span>
        </button>
    </div>

    <div class="zbp-filter-row">
        <button class="zbp-filter-toggle" type="button">Filter <span class="zbp-filter-icon">&#9881;</span></button>
    </div>

    <div class="zbp-overlay" hidden></div>
    <div class="zbp-filter-modal" hidden>
        <button class="zbp-modal-close" type="button" aria-label="Close filter">&#10005;</button>
        <h3>Filter</h3>

        <div class="zbp-filter-group">
            <h4>Experience Category</h4>
            <div class="zbp-category-grid">
                <button type="button">Classes</button>
                <button type="button">Fire &amp; Ice</button>
                <button type="button">Workshops</button>
                <button type="button">Events</button>
            </div>
        </div>

        <div class="zbp-filter-group">
            <h4>Activity Type</h4>
            <div class="zbp-chip-wrap">
                <button type="button" class="is-active">Breathwork</button>
                <button type="button">Meditation</button>
                <button type="button">Pilates</button>
                <button type="button">Yoga</button>
            </div>
        </div>

        <button class="zbp-confirm-btn" type="button">Confirm</button>
    </div>
    <div class="zbp-join-overlay" hidden></div>
    <div class="zbp-join-modal" hidden role="dialog" aria-modal="true" aria-labelledby="zbp-join-modal-title">
        <button class="zbp-join-modal-close" type="button" aria-label="Close join popup">&#10005;</button>
        <div class="zbp-join-media-wrap">
            <img class="zbp-join-media" src="" alt="" hidden />
            <span class="zbp-join-media-placeholder" hidden><?php esc_html_e( 'No image available', 'zen-bookpro' ); ?></span>
            <span class="zbp-join-zencoins"><?php esc_html_e( 'ZENCOINS:', 'zen-bookpro' ); ?> <strong>0</strong></span>
        </div>
        <h3 id="zbp-join-modal-title" class="zbp-join-product-title"><?php esc_html_e( 'Product Name', 'zen-bookpro' ); ?></h3>
    </div>

    <div class="zbp-product-list">
        <?php if ( ! empty( $products ) ) : ?>
            <?php foreach ( $products as $product ) : ?>
                <?php
                $is_slot_based = ! empty( $product['is_slot_based'] );
                $product_image = '';
                if ( ! empty( $product['gallery'] ) && is_array( $product['gallery'] ) ) {
                    $first_gallery_item = reset( $product['gallery'] );
                    if ( is_string( $first_gallery_item ) ) {
                        $product_image = $first_gallery_item;
                    } elseif ( is_array( $first_gallery_item ) ) {
                        $product_image = ! empty( $first_gallery_item['url'] ) ? $first_gallery_item['url'] : ( ! empty( $first_gallery_item['src'] ) ? $first_gallery_item['src'] : '' );
                    }
                }
                if ( empty( $product_image ) && ! empty( $product['image'] ) ) {
                    $product_image = $product['image'];
                }
                $image_html    = ! empty( $product_image )
                    ? '<img src="' . esc_url( $product_image ) . '" alt="' . esc_attr( $product['title'] ) . '" class="zbp-product-image" />'
                    : '<span class="zbp-image-placeholder">&#128247;</span>';
                ?>
                <article class="zbp-product-card <?php echo $is_slot_based ? 'zbp-slot-card' : 'zbp-event-card'; ?>">
                    <div class="zbp-card-icon"><?php echo wp_kses_post( $image_html ); ?></div>
                    <div class="zbp-card-content">
                        <div class="zbp-card-top">
                            <h4><?php echo esc_html( $product['title'] ); ?></h4>
                            <div class="zbp-coins"><?php esc_html_e( 'ZENCOINS:', 'zen-bookpro' ); ?> <span><?php echo esc_html( ! empty( $product['zen_coins'] ) ? $product['zen_coins'] : '0' ); ?></span></div>
                        </div>

                        <?php if ( $is_slot_based ) : ?>
                            <div class="zbp-custom-dropdown">
                                <label><?php esc_html_e( 'Choose Slot', 'zen-bookpro' ); ?></label>
                                <button type="button" class="zbp-dropdown-toggle">
                                    <span class="zbp-selected-slot-label"><?php esc_html_e( 'Select a slot', 'zen-bookpro' ); ?></span>
                                    <span class="zbp-chevron">&#9662;</span>
                                </button>
                                <div class="zbp-dropdown-menu" hidden>
                                    <div class="zbp-slot-chips zbp-grid-view">
                                        <button type="button" class="zbp-slot-chip" data-value=""><?php esc_html_e( 'Slot Placeholder', 'zen-bookpro' ); ?></button>
                                    </div>
                                </div>
                            </div>

                            <div class="zbp-card-bottom">
                                <div class="zbp-duration">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px; margin-top: -2px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    (<?php echo esc_html( ! empty( $product['zen_duration'] ) ? $product['zen_duration'] : $product['duration'] ); ?>)
                                </div>
                                <button
                                    class="zbp-join-btn"
                                    type="button"
                                    data-product-name="<?php echo esc_attr( $product['title'] ); ?>"
                                    data-product-zencoins="<?php echo esc_attr( ! empty( $product['zen_coins'] ) ? $product['zen_coins'] : '0' ); ?>"
                                    data-product-image="<?php echo esc_url( $product_image ); ?>"
                                ><?php esc_html_e( 'Join', 'zen-bookpro' ); ?></button>
                            </div>
                        <?php else : ?>
                            <div class="zbp-event-meta">
                                <p><?php esc_html_e( '08:00-09:00 (placeholder)', 'zen-bookpro' ); ?></p>
                                <p><?php echo esc_html( $product['duration'] ); ?></p>
                                <p><?php esc_html_e( 'Availability placeholder', 'zen-bookpro' ); ?></p>
                            </div>

                            <div class="zbp-card-bottom">
                                <div></div>
                                <button class="zbp-ended-btn" type="button"><?php esc_html_e( 'Class Ended', 'zen-bookpro' ); ?></button>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else : ?>
            <article class="zbp-product-card zbp-empty-state">
                <div class="zbp-card-content">
                    <h4><?php esc_html_e( 'No booking products found.', 'zen-bookpro' ); ?></h4>
                    <p><?php esc_html_e( 'Try changing taxonomy filters or create booking products in WooCommerce.', 'zen-bookpro' ); ?></p>
                </div>
            </article>
        <?php endif; ?>
    </div>
</div>
