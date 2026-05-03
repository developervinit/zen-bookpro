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

    <div class="zbp-product-list">
        <article class="zbp-product-card zbp-slot-card">
            <div class="zbp-card-icon">&#128293;</div>
            <div class="zbp-card-content">
                <div class="zbp-card-top">
                    <h4>FREE FLOW: Sauna, Icebath, Infrared</h4>
                    <div class="zbp-coins">ZENCOINS: <span>5</span></div>
                </div>

                <div class="zbp-select-wrap">
                    <label for="zbp-slot-select">Choose Slot</label>
                    <select id="zbp-slot-select">
                        <option>Choose Slot</option>
                        <option>08:00-09:00</option>
                        <option>09:00-10:00</option>
                    </select>
                </div>

                <div class="zbp-slot-chips">
                    <span>08:00-09:00</span>
                    <span>09:00-10:00</span>
                    <span>11:00-12:00</span>
                    <span>12:00-13:00</span>
                    <span>13:00-14:00</span>
                    <span>14:00-15:00</span>
                    <span>18:00-19:00</span>
                    <span>19:00-20:00</span>
                    <span>20:00-21:00</span>
                    <span>21:00-22:00</span>
                </div>

                <div class="zbp-card-bottom">
                    <div class="zbp-duration">(60 min)</div>
                    <button class="zbp-join-btn" type="button">Join</button>
                </div>
            </div>
        </article>

        <article class="zbp-product-card zbp-event-card">
            <div class="zbp-card-icon">&#129496;</div>
            <div class="zbp-card-content">
                <div class="zbp-card-top">
                    <h4>MEDITATION: Dynamic Vinyasa Flow (ENG)</h4>
                    <div class="zbp-coins">ZENCOINS: <span>5</span></div>
                </div>

                <div class="zbp-event-meta">
                    <p>08:00-09:00 (60 min)</p>
                    <p>19/19 (Vol)</p>
                    <p>Maria Pez</p>
                </div>

                <div class="zbp-card-bottom">
                    <div></div>
                    <button class="zbp-ended-btn" type="button">Class Ended</button>
                </div>
            </div>
        </article>
    </div>
</div>
