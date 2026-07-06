(function () {

    "use strict";



    document.addEventListener("DOMContentLoaded", function () {

        var wrappers = document.querySelectorAll(".zbp-booking-ui");



        function escapeHtml(value) {

            return String(value || "")

                .replace(/&/g, "&amp;")

                .replace(/</g, "&lt;")

                .replace(/>/g, "&gt;")

                .replace(/\"/g, "&quot;")

                .replace(/'/g, "&#039;");

        }



        function decodeHtmlEntities(value) {

            var txt = document.createElement("textarea");

            txt.innerHTML = String(value || "");

            return txt.value;

        }



        function formatSlotTimeRange(timeStr, durationMinutes) {
            if (!timeStr) return "";
            var trimmed = timeStr.trim();
            var h, m;
            var parsed = false;

            var match12 = trimmed.match(/^(\d{1,2}):(\d{2})\s*(am|pm)$/i);
            if (match12) {
                h = parseInt(match12[1], 10);
                m = parseInt(match12[2], 10);
                var ampm = match12[3].toLowerCase();
                if (ampm === "pm" && h < 12) h += 12;
                if (ampm === "am" && h === 12) h = 0;
                parsed = true;
            } else {
                var match24 = trimmed.match(/^(\d{1,2}):(\d{2})$/);
                if (match24) {
                    h = parseInt(match24[1], 10);
                    m = parseInt(match24[2], 10);
                    parsed = true;
                }
            }

            if (!parsed) {
                return timeStr;
            }

            var startHH = String(h).padStart(2, "0");
            var startMM = String(m).padStart(2, "0");

            if (!durationMinutes || durationMinutes <= 0) {
                return startHH + ":" + startMM;
            }

            var startTotalMins = h * 60 + m;
            var endTotalMins = startTotalMins + durationMinutes;
            var endH = Math.floor(endTotalMins / 60) % 24;
            var endM = endTotalMins % 60;

            var endHH = String(endH).padStart(2, "0");
            var endMM = String(endM).padStart(2, "0");

            return startHH + ":" + startMM + "-" + endHH + ":" + endMM;
        }

        function slotLabel(slot, durationMinutes) {
            if (!slot) return "Unavailable";
            var label = "Unavailable";
            if (typeof slot === "string") {
                label = slot;
            } else if (slot && slot.label) {
                label = slot.label;
            }
            return formatSlotTimeRange(label, durationMinutes);
        }

        function isSlotPast(slot, dateObj) {
            if (!slot) return false;

            if (slot && typeof slot === "object") {
                if (slot.status === "expired") {
                    return true;
                }
                if (slot.timestamp) {
                    var nowTs = Math.floor(Date.now() / 1000);
                    if (slot.timestamp < nowTs) {
                        return true;
                    }
                }
            }

            var label = "";
            if (typeof slot === "string") {
                label = slot;
            } else if (slot && slot.label) {
                label = slot.label;
            }
            if (!label) return false;

            var trimmed = label.trim();
            var parts = trimmed.split(/\s*[-\u2013]\s*/);
            var startTimePart = parts[0] ? parts[0].trim() : trimmed;
            var h = 0, m = 0;
            var parsed = false;

            var match12 = startTimePart.match(/^(\d{1,2}):(\d{2})\s*(am|pm)$/i);
            if (match12) {
                h = parseInt(match12[1], 10);
                m = parseInt(match12[2], 10);
                var ampm = match12[3].toLowerCase();
                if (ampm === "pm" && h < 12) h += 12;
                if (ampm === "am" && h === 12) h = 0;
                parsed = true;
            } else {
                var match24 = startTimePart.match(/^(\d{1,2}):(\d{2})$/);
                if (match24) {
                    h = parseInt(match24[1], 10);
                    m = parseInt(match24[2], 10);
                    parsed = true;
                }
            }

            if (parsed && dateObj) {
                var slotDate = new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate(), h, m, 0, 0);
                var now = new Date();
                return now.getTime() >= slotDate.getTime();
            }

            return false;
        }



        function toSafeInt(value, fallback) {

            var parsed = parseInt(value, 10);

            return Number.isFinite(parsed) ? parsed : fallback;

        }



        function toDurationMinutes(value) {

            var parsed = parseInt(value, 10);

            if (!Number.isFinite(parsed) || parsed < 0) {

                return 0;

            }

            return parsed;

        }



        function getPopupGalleryImage(product) {

            if (!product || typeof product !== "object") {

                return "";

            }

            return product.product_featured_image || "";

        }

        function getBookingCoinCost(product) {

            if (!product || typeof product !== "object") {

                return "0";

            }

            return product.booking_coin_cost || product.zen_coins || "0";

        }



        function renderEmptyState(productList) {

            if (!productList) {

                return;

            }

            productList.setAttribute("aria-busy", "false");

            productList.innerHTML =

                '<article class="zbp-product-card zbp-empty-state">' +

                '<div class="zbp-card-content">' +

                "<h4>No booking products found.</h4>" +

                "<p>Try another date or adjust your filters.</p>" +

                "</div>" +

                "</article>";

        }

        function renderAjaxLoader(productList) {

            if (!productList) {

                return;

            }

            productList.setAttribute("aria-busy", "true");

            productList.innerHTML =

                '<div class="zbp-ajax-loader-card" role="status" aria-live="polite">' +

                '<span class="zbp-zencoin-loader" aria-hidden="true"><span>Z</span></span>' +

                '<span class="zbp-loader-text">Loading availability</span>' +

                '</div>';

        }



        function renderProducts(productList, products, selectedDateKey) {

            if (!selectedDateKey) {

                var fallbackDate = new Date();

                var y = fallbackDate.getFullYear();

                var m = String(fallbackDate.getMonth() + 1).padStart(2, "0");

                var d = String(fallbackDate.getDate()).padStart(2, "0");

                selectedDateKey = y + "-" + m + "-" + d;

            }

            var productDateObj = null;

            if (selectedDateKey) {

                var parts = selectedDateKey.split("-");

                if (parts.length === 3) {

                    productDateObj = new Date(

                        parseInt(parts[0], 10),

                        parseInt(parts[1], 10) - 1,

                        parseInt(parts[2], 10)

                    );

                }

            }

            if (!productList) {

                return;

            }

            productList.setAttribute("aria-busy", "false");



            if (!Array.isArray(products) || products.length === 0) {

                renderEmptyState(productList);

                return;

            }



            var html = products

                .map(function (product) {

                    console.log('Zen-BookPro Debug booked blocks:', {

                        name: product.name,

                        booked: product.booked_spots,

                        max: product.max_spots,

                        status: product.event_status,

                        debug: product.slot_debug

                    });

                    var isSlotBased = product.mode !== "event";

                    var cardClass = isSlotBased ? "zbp-slot-card" : "zbp-event-card";

                    var primaryImage = product.image || "";

                    var popupImage = getPopupGalleryImage(product);

                    var bookingDurationMinutes = toDurationMinutes(product.booking_duration_minutes || 0);

                    var imageHtml = primaryImage

                        ? '<img src="' + escapeHtml(primaryImage) + '" alt="' + escapeHtml(product.name) + '" class="zbp-product-image" />'

                        : '<span class="zbp-image-placeholder">&#128247;</span>';

                    var durationText = bookingDurationMinutes > 0 ? (bookingDurationMinutes + " min") : "Duration N/A";

                    var bookingCoinCost = getBookingCoinCost(product);

                    var zcoins = escapeHtml(bookingCoinCost);

                    var duration = '<svg width="14" height="14" viewBox="0 0 24 24" style="vertical-align: middle; margin-right: 4px; margin-top: -2px;"><circle cx="12" cy="12" r="10" fill="currentColor"></circle><path d="M12 7v5h5" stroke="var(--zbp-card)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"></path></svg> (' + durationText + ')';

                    var slots = [];

                    if (Array.isArray(product.slots)) {

                        slots = product.slots;

                    } else if (product.slots && typeof product.slots === "object") {

                        slots = Object.keys(product.slots).map(function (k) { return product.slots[k]; });

                    }

                    if (isSlotBased && slots.length === 0) {

                        return "";

                    }



                    var instructorHtml = product.zen_instructor

                        ? '<span class="zbp-instructor" style="color: #888; font-size: 13px; margin-top: 4px; display: block;">' + escapeHtml(product.zen_instructor) + '</span>'

                        : '';



                    if (isSlotBased) {

                        var chips = slots.length

                            ? slots

                                .map(function (slot) {

                                    var label = slotLabel(slot, bookingDurationMinutes);

                                    var val = (slot && slot.value) ? slot.value : label;

                                    var isPast = isSlotPast(slot, productDateObj);

                                    var pastClass = isPast ? " is-past" : "";

                                    var isCancelled = slot && slot.status === 'cancelled';

                                    var cancelledClass = isCancelled ? " is-cancelled" : "";

                                    var disabledAttr = isCancelled ? " disabled aria-disabled=\"true\"" : "";

                                    return '<button type="button" class="zbp-slot-chip' + pastClass + cancelledClass + '"' + disabledAttr + ' data-value="' + escapeHtml(val) + '">' + escapeHtml(label) + "</button>";

                                })

                                .join("")

                            : '<span class="zbp-no-slots">No slots available</span>';



                        return (

                            '<article class="zbp-product-card ' +

                            cardClass +

                            '">' +

                            '<div class="zbp-card-icon">' +

                            imageHtml +

                            "</div>" +

                            '<div class="zbp-card-content">' +

                            '<div class="zbp-card-top">' +

                            "<h4>" +

                            escapeHtml(product.name) +

                            "</h4>" +

                            '<div class="zbp-coins">ZENCOINS: <span>' +

                            zcoins +

                            "</span></div>" +

                            "</div>" +

                            '<div class="zbp-select-wrap zbp-custom-dropdown">' +



                            '<button type="button" class="zbp-dropdown-toggle">' +

                            '<span class="zbp-selected-slot-label">Choose Slot</span>' +

                            '<svg class="zbp-chevron" width="24" height="24" viewBox="0 0 24 24" fill="#9A9A9A"><path d="M4 8l8 8 8-8H4z"/></svg>' +

                            '</button>' +

                            '<div class="zbp-dropdown-menu" hidden>' +

                            '<div class="zbp-slot-chips zbp-grid-view">' +

                            chips +

                            "</div>" +

                            "</div>" +

                            "</div>" +

                            '<div class="zbp-card-bottom">' +

                            '<div class="zbp-duration">' +

                            duration +

                            (instructorHtml ? ' ' + instructorHtml : '') +

                            "</div>" +

                            '<button class="zbp-join-btn" type="button" data-product-id="' + escapeHtml(String(product.id || 0)) + '" data-product-name="' + escapeHtml(product.name || "") + '" data-product-zencoins="' + escapeHtml(bookingCoinCost) + '" data-product-image="' + escapeHtml(popupImage || "") + '" data-product-mode="' + escapeHtml(product.mode || "") + '" data-product-duration-minutes="' + escapeHtml(String(product.booking_duration_minutes || 0)) + '" data-product-description="' + escapeHtml(product.description || "") + '" data-product-cancellation-policy="' + escapeHtml(product.cancellation_policy || "") + '" data-product-instructor="' + escapeHtml(product.zen_instructor || "") + '" data-product-location="' + escapeHtml(product.location || "") + '" data-product-experience-category="' + escapeHtml(product.experience_category || "") + '" data-product-slots="' + escapeHtml(JSON.stringify(product.slots || [])) + '" data-product-gallery="' + escapeHtml(JSON.stringify(product.gallery || [])) + '">Join</button>' +

                            "</div>" +

                            "</div>" +

                            "</article>"

                        );

                    }



                    var firstSlotStr = slots.length ? slotLabel(slots[0]) : "No slot available";

                    var formattedTimeBlock = firstSlotStr;



                    if (slots.length && firstSlotStr !== "Unavailable") {

                        var rawDur = product.duration || product.zen_duration || "";

                        var durationMinutes = 60;

                        var matchDur = rawDur.match(/[\d.]+/);

                        if (matchDur) {

                            var val = parseFloat(matchDur[0]);

                            if (rawDur.toLowerCase().indexOf("hour") !== -1 || rawDur.toLowerCase().indexOf("hr") !== -1) {

                                durationMinutes = Math.round(val * 60);

                            } else {

                                durationMinutes = Math.round(val);

                            }

                        }



                        var match12 = firstSlotStr.match(/(\d+):(\d+)\s*(am|pm)/i);

                        var match24 = firstSlotStr.match(/^(\d{1,2}):(\d{2})$/);

                        var h, m;

                        var parsed = false;



                        if (match12) {

                            h = parseInt(match12[1], 10);

                            m = parseInt(match12[2], 10);

                            var ampm = match12[3].toLowerCase();

                            if (ampm === "pm" && h < 12) h += 12;

                            if (ampm === "am" && h === 12) h = 0;

                            parsed = true;

                        } else if (match24) {

                            h = parseInt(match24[1], 10);

                            m = parseInt(match24[2], 10);

                            parsed = true;

                        }



                        if (parsed) {

                            var startTotalMins = h * 60 + m;

                            var endTotalMins = startTotalMins + durationMinutes;

                            var endH = Math.floor(endTotalMins / 60) % 24;

                            var endM = endTotalMins % 60;



                            var startHH = String(h).padStart(2, '0');

                            var startMM = String(m).padStart(2, '0');

                            var endHH = String(endH).padStart(2, '0');

                            var endMM = String(endM).padStart(2, '0');



                            formattedTimeBlock = startHH + ":" + startMM + "-" + endHH + ":" + endMM + " (" + durationMinutes + " min)";

                        }

                    }



                    var maxSpots = toSafeInt(product.max_spots, 1);

                    var bookedSpots = toSafeInt(product.booked_spots, 0);

                    if (maxSpots < 1) {

                        maxSpots = 1;

                    }

                    if (bookedSpots < 0) {

                        bookedSpots = 0;

                    }

                    if (bookedSpots > maxSpots) {

                        maxSpots = bookedSpots;

                    }



                    var volumeText = bookedSpots + "/" + maxSpots + " (Voll)";

                    var volumeHtml = '<p style="display: flex; align-items: center; gap: 4px; color: var(--zbp-accent); font-weight: 500; font-size: 14px; margin: 0;">' +

                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="margin-top:-2px;"><circle cx="6.5" cy="10" r="3"></circle><path d="M6.5 14c-1.8 0-4.5.7-4.5 2.2V18h9v-1.8c0-1.5-2.7-2.2-4.5-2.2z"></path><circle cx="17.5" cy="10" r="3"></circle><path d="M17.5 14c-1.8 0-4.5.7-4.5 2.2V18h9v-1.8c0-1.5-2.7-2.2-4.5-2.2z"></path><circle cx="12" cy="8.5" r="3.5"></circle><path d="M12 13c-2.2 0-6 .9-6 2.5V18h12v-2.5c0-1.6-3.8-2.5-6-2.5z"></path></svg>' +

                        escapeHtml(volumeText) +

                        "</p>";



                    var classHasEnded = false;

                    if (product.mode === "event" && parsed && selectedDateKey) {

                        var dateParts = selectedDateKey.split("-");

                        if (dateParts.length === 3) {

                            var year = parseInt(dateParts[0], 10);

                            var month = parseInt(dateParts[1], 10) - 1;

                            var day = parseInt(dateParts[2], 10);

                            var eventStartDate = new Date(year, month, day, h, m, 0, 0);

                            var now = new Date();

                            var hideBeforeValue = parseInt(product.hide_before_value, 10) || 0;
                            var hideBeforeUnit = product.hide_before_unit || "minutes";
                            var hideBeforeMs = 0;

                            if (hideBeforeValue > 0) {
                                if (hideBeforeUnit === "minutes") {
                                    hideBeforeMs = hideBeforeValue * 60 * 1000;
                                } else if (hideBeforeUnit === "hours") {
                                    hideBeforeMs = hideBeforeValue * 60 * 60 * 1000;
                                } else if (hideBeforeUnit === "days") {
                                    hideBeforeMs = hideBeforeValue * 24 * 60 * 60 * 1000;
                                }
                            }

                            classHasEnded = now.getTime() >= (eventStartDate.getTime() - hideBeforeMs);

                            console.log("Zen-BookPro Real-Time Check:", {

                                product: product.name,

                                eventStart: eventStartDate.toLocaleString(),

                                now: now.toLocaleString(),

                                hideBeforeMs: hideBeforeMs,

                                classHasEnded: classHasEnded

                            });

                        }

                    }



                    var isEnded = product.event_status === 'ended' || classHasEnded;

                    if (!isSlotBased && isEnded) {
                        return "";
                    }

                    var isCancelled = product.event_status === 'cancelled';

                    var isWaitlist = !isEnded && !isCancelled && (product.event_status === 'waitlist' || bookedSpots >= maxSpots);

                    var btnText = isCancelled ? 'Class Canceled' : (isEnded ? 'Class Ended' : (isWaitlist ? 'Join Waitlist' : 'Join'));

                    var btnClass = isCancelled ? 'zbp-join-btn is-cancelled' : (isEnded ? 'zbp-join-btn is-ended' : (isWaitlist ? 'zbp-join-btn is-waitlist' : 'zbp-join-btn'));

                    var btnDisabledAttr = (isEnded || isCancelled) ? ' disabled aria-disabled="true"' : '';



                    var cardCancelledClass = isCancelled ? " is-cancelled" : "";

                    return (

                        '<article class="zbp-product-card ' +

                        cardClass +

                        cardCancelledClass +

                        '">' +

                        '<div class="zbp-card-icon">' +

                        imageHtml +

                        "</div>" +

                        '<div class="zbp-card-content">' +

                        '<div class="zbp-card-top">' +

                        "<h4>" +

                        escapeHtml(product.name) +

                        "</h4>" +

                        '<div class="zbp-coins">ZENCOINS: <span>' +

                        zcoins +

                        "</span></div>" +

                        "</div>" +

                        '<div class="zbp-event-meta">' +

                        '<p style="display: flex; align-items: center; gap: 4px;">' +

                        '<svg width="14" height="14" viewBox="0 0 24 24" style="margin-top:-2px;"><circle cx="12" cy="12" r="10" fill="currentColor"></circle><path d="M12 7v5h5" stroke="var(--zbp-card)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"></path></svg>' +

                        escapeHtml(formattedTimeBlock) +

                        "</p>" +

                        "</div>" +

                        '<div class="zbp-card-bottom">' +

                        '<div class="zbp-event-footer-left">' +

                        volumeHtml +

                        instructorHtml +

                        "</div>" +

                        '<div class="zbp-event-actions">' +

'<button class="' + btnClass + '" type="button" data-product-id="' + escapeHtml(String(product.id || 0)) + '" data-product-name="' + escapeHtml(product.name || "") + '" data-product-zencoins="' + escapeHtml(bookingCoinCost) + '" data-product-image="' + escapeHtml(popupImage || "") + '" data-product-mode="' + escapeHtml(product.mode || "") + '" data-product-duration-minutes="' + escapeHtml(String(product.booking_duration_minutes || 0)) + '" data-product-description="' + escapeHtml(product.description || "") + '" data-product-cancellation-policy="' + escapeHtml(product.cancellation_policy || "") + '" data-product-instructor="' + escapeHtml(product.zen_instructor || "") + '" data-product-location="' + escapeHtml(product.location || "") + '" data-product-experience-category="' + escapeHtml(product.experience_category || "") + '" data-product-slots="' + escapeHtml(JSON.stringify(product.slots || [])) + '" data-product-gallery="' + escapeHtml(JSON.stringify(product.gallery || [])) + '" data-product-formatted-slot="' + escapeHtml(formattedTimeBlock) + '" data-product-volume="' + escapeHtml(bookedSpots + "/" + maxSpots) + '"' + btnDisabledAttr + '>' + escapeHtml(btnText) + '</button>' +

                        '</div>' +

                        "</div>" +

                        "</div>" +

                        "</article>"

                    );

                })

                .join("");

            if (!html.trim()) {

                renderEmptyState(productList);

                return;

            }



            productList.innerHTML = html;

        }



        function fetchSlots(dateKey, wrapper, productList) {

            var expId = "0";
            var actId = "0";
            if (wrapper) {
                var activeExpBtn = wrapper.querySelector(".zbp-category-grid button.is-active");
                var activeActBtn = wrapper.querySelector(".zbp-chip-wrap button.is-active");
                expId = activeExpBtn ? (activeExpBtn.getAttribute("data-term-id") || "0") : "0";
                actId = activeActBtn ? (activeActBtn.getAttribute("data-term-id") || "0") : "0";
            }
            if (!window.zbpAjax || !zbpAjax.ajaxUrl) {

                return;

            }

            if (wrapper) {

                wrapper.classList.add("is-ajax-loading");

            }

            renderAjaxLoader(productList);



            var payload = new URLSearchParams();

            payload.append("action", "zbp_get_slots");

            payload.append("date", dateKey);

            payload.append("experience", expId);
            payload.append("activity", actId);
            payload.append("nonce", zbpAjax.nonce || "");



            fetch(zbpAjax.ajaxUrl, {

                method: "POST",

                headers: {

                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",

                },

                body: payload.toString(),

                credentials: "same-origin",

            })

                .then(function (response) {

                    return response.json();

                })

                .then(function (result) {

                    if (!result || !result.success) {

                        renderEmptyState(productList);

                        return;

                    }



                    var products = result.data && Array.isArray(result.data.products) ? result.data.products : [];



                    renderProducts(productList, products, dateKey);

                    wrapper.dispatchEvent(

                        new CustomEvent("zbp_slots_updated", {

                            detail: {

                                selected_date: dateKey,

                                products_count: products.length,

                            },

                        })

                    );

                })

                .catch(function (error) {

                    renderEmptyState(productList);

                })

                .then(function () {

                    if (wrapper) {

                        wrapper.classList.remove("is-ajax-loading");

                    }

                    if (productList) {

                        productList.setAttribute("aria-busy", "false");

                    }

                });

        }



        wrappers.forEach(function (wrapper) {

            var filterToggle = wrapper.querySelector(".zbp-filter-toggle");

            var modal = wrapper.querySelector(".zbp-filter-modal");

            var overlay = wrapper.querySelector(".zbp-overlay");

            var closeBtn = wrapper.querySelector(".zbp-modal-close");

            var confirmBtn = wrapper.querySelector(".zbp-confirm-btn");

            var joinModal = wrapper.querySelector(".zbp-join-modal");

            var joinOverlay = wrapper.querySelector(".zbp-join-overlay");

            var joinModalCloseBtn = wrapper.querySelector(".zbp-join-modal-close");

            var joinModalImage = wrapper.querySelector(".zbp-join-media");

            var joinModalImagePlaceholder = wrapper.querySelector(".zbp-join-media-placeholder");

            var joinModalZencoin = wrapper.querySelector(".zbp-join-zencoins strong");

            var joinModalTitle = wrapper.querySelector(".zbp-join-product-title");

            var joinDateValue = wrapper.querySelector(".zbp-join-date-value");

            var joinTimeRow = wrapper.querySelector(".zbp-join-time-row");

            var joinTimeValue = wrapper.querySelector(".zbp-join-time-value");

            var joinVolumeRow = wrapper.querySelector(".zbp-join-volume-row");

            var joinVolumeValue = wrapper.querySelector(".zbp-join-volume-value");

            var joinEventCategoryWrap = wrapper.querySelector(".zbp-join-event-category-wrap");

            var joinEventCategoryValue = wrapper.querySelector(".zbp-join-event-category-value");

            var joinDurationRow = wrapper.querySelector(".zbp-join-duration-row");

            var joinDurationValue = wrapper.querySelector(".zbp-join-duration-value");

            var joinCategoryValue = wrapper.querySelector(".zbp-join-category-value");

            var joinDescriptionRow = wrapper.querySelector(".zbp-join-description-row");

            var joinDescriptionValue = wrapper.querySelector(".zbp-join-description-value");

            var joinCancellationRow = wrapper.querySelector(".zbp-join-cancellation-row");

            var joinCancellationValue = wrapper.querySelector(".zbp-join-cancellation-value");

            var joinInstructorRow = wrapper.querySelector(".zbp-join-instructor-row");

            var joinInstructorValue = wrapper.querySelector(".zbp-join-instructor-value");

            var joinLocationRow = wrapper.querySelector(".zbp-join-location-row");

            var joinLocationValue = wrapper.querySelector(".zbp-join-location-value");

            var joinActions = wrapper.querySelector(".zbp-join-actions");

            var joinActionClose = wrapper.querySelector(".zbp-join-action-close");

            var joinActionSubmit = wrapper.querySelector(".zbp-join-action-submit");

            var joinSlotWrap = wrapper.querySelector(".zbp-join-slot-wrap");

            var joinSlotToggle = wrapper.querySelector(".zbp-join-slot-toggle");

            var joinSlotMenu = wrapper.querySelector(".zbp-join-slot-menu");

            var joinSlotChips = wrapper.querySelector(".zbp-join-slot-chips");

            var joinSelectedSlotLabel = wrapper.querySelector(".zbp-join-selected-slot-label");

            var weekRange = wrapper.querySelector(".zbp-week-range");

            var dateRow = wrapper.querySelector(".zbp-date-row");

            var prevBtn = wrapper.querySelector(".zbp-nav-prev");

            var nextBtn = wrapper.querySelector(".zbp-nav-next");

            var productList = wrapper.querySelector(".zbp-product-list");

            var joinModalProductId = 0;

            var joinModalProductMode = "";

            var joinModalProductSlots = [];



            if (!dateRow || !weekRange || !productList) {

                return;

            }



            wrapper.addEventListener("zbp_date_selected", function (event) {

                var selectedKey = event && event.detail ? event.detail.selected_date : "";



                if (!selectedKey) {

                    return;

                }



                fetchSlots(selectedKey, wrapper, productList);

            });



            function openModal() {

                if (!modal || !overlay) {

                    return;

                }

                modal.hidden = false;

                overlay.hidden = false;

            }



            function closeModal() {

                if (!modal || !overlay) {

                    return;

                }

                modal.hidden = true;

                overlay.hidden = true;

            }



            function renderJoinSlots(slots, preselectedSlotValue, durationMinutes) {

                if (!joinSlotChips) {

                    return;

                }



                if (!Array.isArray(slots) || slots.length === 0) {

                    joinSlotChips.innerHTML = '<span class="zbp-no-slots">No slots available</span>';

                    if (joinSelectedSlotLabel) {

                        joinSelectedSlotLabel.textContent = "No slots available";

                    }

                    return;

                }



                var chips = slots.map(function (slot) {

                    var label = slotLabel(slot, durationMinutes);

                    var val = (slot && slot.value) ? slot.value : label;

                    var isSelected = (preselectedSlotValue && val === preselectedSlotValue) ? " is-selected" : "";

                    var isPast = isSlotPast(slot, selectedDate);

                    var pastClass = isPast ? " is-past" : "";

                    var isCancelled = slot && slot.status === 'cancelled';

                    var cancelledClass = isCancelled ? " is-cancelled" : "";

                    var disabledAttr = isCancelled ? " disabled aria-disabled=\"true\"" : "";

                    return '<button type="button" class="zbp-slot-chip zbp-join-slot-chip' + isSelected + pastClass + cancelledClass + '"' + disabledAttr + ' data-value="' + escapeHtml(val) + '">' + escapeHtml(label) + "</button>";

                }).join("");



                joinSlotChips.innerHTML = chips;

                if (joinSelectedSlotLabel) {

                    if (preselectedSlotValue) {

                        var matchingSlot = slots.find(function (slot) {

                            var val = (slot && slot.value) ? slot.value : slotLabel(slot, durationMinutes);

                            return val === preselectedSlotValue;

                        });

                        joinSelectedSlotLabel.textContent = matchingSlot ? slotLabel(matchingSlot, durationMinutes) : "Choose Slot";

                    } else {

                        joinSelectedSlotLabel.textContent = "Choose Slot";

                    }

                }

            }

            function updateJoinModalZencoins(value) {
                var normalizedValue = String(value || "0");
                var zencoinWrap = wrapper.querySelector(".zbp-join-zencoins");
                var valueNode;
                var coinNode;

                if (!zencoinWrap) {
                    return;
                }

                zencoinWrap.setAttribute("data-zbp-zencoin-value", normalizedValue);

                valueNode = zencoinWrap.querySelector(".zen-coin-global__value, strong");

                if (valueNode) {
                    valueNode.textContent = normalizedValue;
                }

                coinNode = zencoinWrap.querySelector(".zen-coin-global");

                if (coinNode) {
                    coinNode.setAttribute("data-zencoin-value", normalizedValue);
                    coinNode.setAttribute("aria-label", normalizedValue + " Zencoins");
                }

                if (joinModalZencoin && joinModalZencoin !== valueNode) {
                    joinModalZencoin.textContent = normalizedValue;
                }
            }



            function openJoinModal(joinBtn) {

                if (!joinModal || !joinOverlay) {

                    return;

                }



                var productName = joinBtn ? (joinBtn.getAttribute("data-product-name") || "") : "";

                var productId = joinBtn ? (joinBtn.getAttribute("data-product-id") || "0") : "0";

                var productCoins = joinBtn ? (joinBtn.getAttribute("data-product-zencoins") || "0") : "0";

                var productImage = joinBtn ? (joinBtn.getAttribute("data-product-image") || "") : "";

                var productMode = joinBtn ? (joinBtn.getAttribute("data-product-mode") || "") : "";

                productMode = String(productMode).toLowerCase().trim();

                joinModalProductId = toSafeInt(productId, 0);

                joinModalProductMode = productMode;

                var productDurationMinutes = joinBtn ? (joinBtn.getAttribute("data-product-duration-minutes") || "0") : "0";

                var productDescription = joinBtn ? (joinBtn.getAttribute("data-product-description") || "") : "";

                var productCancellationPolicy = joinBtn ? (joinBtn.getAttribute("data-product-cancellation-policy") || "") : "";

                var productInstructor = joinBtn ? (joinBtn.getAttribute("data-product-instructor") || "") : "";

                var productLocation = joinBtn ? (joinBtn.getAttribute("data-product-location") || "") : "";

                var productExperienceCategory = joinBtn ? (joinBtn.getAttribute("data-product-experience-category") || "") : "";

                var productSlotsRaw = joinBtn ? (joinBtn.getAttribute("data-product-slots") || "[]") : "[]";

                var productSlots = [];

                try {

                    productSlots = JSON.parse(productSlotsRaw);

                } catch (e) {

                    productSlots = [];

                }

                joinModalProductSlots = productSlots;

                if (joinModalCloseBtn) {

                    joinModalCloseBtn.hidden = (productMode === "event" || productMode === "free_flow");

                }



                if (joinModalTitle) {

                    joinModalTitle.textContent = productName || "Product";

                }

                if (joinDateValue) {

                    joinDateValue.textContent = formatDateDisplay(selectedDate);

                }

                updateJoinModalZencoins(productCoins);

                if (joinModalImage) {

                    if (productImage) {

                        joinModalImage.src = productImage;

                        joinModalImage.alt = productName || "Product";

                        joinModalImage.hidden = false;

                        if (joinModalImagePlaceholder) {

                            joinModalImagePlaceholder.hidden = true;

                        }

                    } else {

                        joinModalImage.removeAttribute("src");

                        joinModalImage.alt = "";

                        joinModalImage.hidden = true;

                        if (joinModalImagePlaceholder) {

                            joinModalImagePlaceholder.hidden = false;

                        }

                    }

                }



                if (joinTimeRow && joinTimeValue) {

                    joinTimeRow.hidden = true;

                    if (productMode === "event") {

                        var formattedSlot = joinBtn ? (joinBtn.getAttribute("data-product-formatted-slot") || "") : "";

                        if (formattedSlot) {

                            joinTimeValue.textContent = formattedSlot;

                            joinTimeRow.hidden = false;

                        }

                    }

                }



                if (joinVolumeRow && joinVolumeValue) {

                    joinVolumeRow.hidden = true;

                    if (joinEventCategoryWrap) {

                        joinEventCategoryWrap.hidden = true;

                    }

                    if (productMode === "event") {

                        var volumeVal = joinBtn ? (joinBtn.getAttribute("data-product-volume") || "") : "";

                        if (volumeVal) {

                            joinVolumeValue.textContent = volumeVal;

                            joinVolumeRow.hidden = false;

                        }

                        if (joinEventCategoryWrap && joinEventCategoryValue) {

                            var eventCategoryText = decodeHtmlEntities(productExperienceCategory || "").trim();

                            if (eventCategoryText) {

                                joinEventCategoryValue.textContent = eventCategoryText;

                                joinEventCategoryWrap.hidden = false;

                            }

                        }

                    }

                }



                if (joinSlotWrap) {

                    joinSlotWrap.hidden = true;

                    if (productMode === "free_flow") {

                        joinSlotWrap.hidden = false;

                        

                        // Find the selected chip on the product card

                        var cardContent = joinBtn ? joinBtn.closest(".zbp-card-content") : null;

                        var selectedChip = cardContent ? cardContent.querySelector(".zbp-slot-chip.is-selected") : null;

                        var preselectedSlotValue = selectedChip ? (selectedChip.getAttribute("data-value") || "") : "";

                        var durationMins = toDurationMinutes(productDurationMinutes);

                        

                        renderJoinSlots(productSlots, preselectedSlotValue, durationMins);

                        if (joinActionSubmit) {
                            var selectedSlotObj = null;
                            if (preselectedSlotValue) {
                                selectedSlotObj = productSlots.find(function (s) {
                                    var val = (s && s.value) ? s.value : s;
                                    return String(val) === String(preselectedSlotValue);
                                });
                            }
                            if (selectedSlotObj && selectedSlotObj.status === 'cancelled') {
                                joinActionSubmit.textContent = "Class Canceled";
                                joinActionSubmit.classList.add("is-ended");
                                joinActionSubmit.disabled = true;
                                joinActionSubmit.setAttribute("aria-disabled", "true");
                            } else if (selectedSlotObj && isSlotPast(selectedSlotObj, selectedDate)) {
                                joinActionSubmit.textContent = "Class Ended";
                                joinActionSubmit.classList.add("is-ended");
                                joinActionSubmit.disabled = true;
                                joinActionSubmit.setAttribute("aria-disabled", "true");
                            } else {
                                joinActionSubmit.textContent = "Join";
                                joinActionSubmit.classList.remove("is-ended");
                                joinActionSubmit.disabled = false;
                                joinActionSubmit.removeAttribute("aria-disabled");
                            }
                        }

                    } else {
                        if (joinActionSubmit) {
                            joinActionSubmit.textContent = "Join";
                            joinActionSubmit.classList.remove("is-ended");
                            joinActionSubmit.disabled = false;
                            joinActionSubmit.removeAttribute("aria-disabled");
                        }
                    }

                }

                if (joinDurationRow && joinDurationValue) {

                    joinDurationRow.hidden = true;

                    if (productMode === "free_flow") {

                        joinDurationValue.textContent = "(" + toDurationMinutes(productDurationMinutes) + " min)";

                        if (joinCategoryValue) {

                            joinCategoryValue.textContent = decodeHtmlEntities(productExperienceCategory || "None");

                        }

                        joinDurationRow.hidden = false;

                    }

                }

                if (joinDescriptionRow && joinDescriptionValue) {

                    joinDescriptionRow.hidden = true;

                    joinDescriptionValue.textContent = "";

                    if (productMode === "free_flow" || productMode === "event") {

                        var descriptionText = decodeHtmlEntities(productDescription || "").trim();

                        if (descriptionText) {

                            joinDescriptionValue.textContent = descriptionText;

                            joinDescriptionRow.hidden = false;

                        }

                    }

                }

                if (joinCancellationRow && joinCancellationValue) {

                    joinCancellationRow.hidden = true;

                    joinCancellationValue.textContent = "";

                    if (productMode === "free_flow" || productMode === "event") {

                        var cancellationText = decodeHtmlEntities(productCancellationPolicy || "").trim();

                        if (cancellationText) {

                            joinCancellationValue.textContent = cancellationText;

                            joinCancellationRow.hidden = false;

                        }

                    }

                }

                if (joinInstructorRow && joinInstructorValue) {

                    joinInstructorRow.hidden = true;

                    joinInstructorValue.textContent = "";

                    if (productMode === "free_flow" || productMode === "event") {

                        var instructorText = decodeHtmlEntities(productInstructor || "").trim();

                        if (instructorText) {

                            joinInstructorValue.textContent = instructorText;

                            joinInstructorRow.hidden = false;

                        }

                    }

                }

                if (joinLocationRow && joinLocationValue) {

                    joinLocationRow.hidden = true;

                    joinLocationValue.textContent = "";

                    if (productMode === "free_flow" || productMode === "event") {

                        var locationText = decodeHtmlEntities(productLocation || "").trim();

                        if (locationText) {

                            joinLocationValue.textContent = locationText;

                            joinLocationRow.hidden = false;

                        }

                    }

                }

                if (joinSlotMenu) {

                    joinSlotMenu.hidden = true;

                }

                if (joinActions) {

                    joinActions.hidden = (productMode !== "free_flow" && productMode !== "event");

                }



                joinModal.hidden = false;

                joinOverlay.hidden = false;

            }



            function closeJoinModal() {

                if (!joinModal || !joinOverlay) {

                    return;

                }

                joinModal.hidden = true;

                joinOverlay.hidden = true;

                if (joinSlotMenu) {

                    joinSlotMenu.hidden = true;

                }

                if (joinActions) {

                    joinActions.hidden = true;

                }

            }



            function buildAddToCartUrl(productId, selectedSlotValue) {

                var baseUrl = (window.zbpAjax && zbpAjax.cartUrl) ? zbpAjax.cartUrl : window.location.href;

                var url = new URL(baseUrl, window.location.origin);

                

                var y = String(selectedDate.getFullYear());

                var m = String(selectedDate.getMonth() + 1).padStart(2, '0');

                var d = String(selectedDate.getDate()).padStart(2, '0');

                var timePart = "";



                if (selectedSlotValue) {

                    var clean = selectedSlotValue.replace('T', ' ').split('+')[0].split('Z')[0].trim();

                    var parts = clean.split(' ');

                    if (parts.length >= 2 && parts[0].indexOf('-') !== -1) {

                        var dateParts = parts[0].split('-');

                        if (dateParts.length === 3) {

                            y = String(parseInt(dateParts[0], 10));

                            m = String(parseInt(dateParts[1], 10)).padStart(2, '0');

                            d = String(parseInt(dateParts[2], 10)).padStart(2, '0');

                            

                            var timeParts = parts[1].split(':');

                            if (timeParts.length >= 2) {

                                timePart = String(timeParts[0]).padStart(2, '0') + ":" + String(timeParts[1]).padStart(2, '0');

                            }

                        }

                    } else if (selectedSlotValue.indexOf(':') !== -1) {

                        var timeParts = selectedSlotValue.split(':');

                        if (timeParts.length >= 2) {

                            timePart = String(timeParts[0]).padStart(2, '0') + ":" + String(timeParts[1]).padStart(2, '0');

                        }

                    }

                }



                url.searchParams.set("add-to-cart", String(productId));

                url.searchParams.set("wc_bookings_field_start_date_year", y);

                url.searchParams.set("wc_bookings_field_start_date_month", String(parseInt(m, 10)));

                url.searchParams.set("wc_bookings_field_start_date_day", String(parseInt(d, 10)));

                url.searchParams.set("wc_bookings_field_duration", "1");

                url.searchParams.set("wc_bookings_field_qty", "1");

                url.searchParams.set("wc_bookings_field_persons", "1");



                if (timePart) {

                    var fullDateTime = y + "-" + m + "-" + d + " " + timePart + ":00";

                    url.searchParams.set("wc_bookings_field_start_date_time", fullDateTime);

                }



                return url.toString();

            }



            if (filterToggle) {

                filterToggle.addEventListener("click", openModal);

            }

            if (closeBtn) {

                closeBtn.addEventListener("click", closeModal);

            }

            if (overlay) {

                overlay.addEventListener("click", closeModal);

            }

            if (confirmBtn) {

                confirmBtn.addEventListener("click", function () {
                    closeModal();
                    var dateKey = formatDateKey(selectedDate);
                    fetchSlots(dateKey, wrapper, productList);
                });

                // Click handling for term selection in filter grid/chips
                var categoryGrid = wrapper.querySelector(".zbp-category-grid");
                var chipWrap = wrapper.querySelector(".zbp-chip-wrap");

                if (categoryGrid) {
                    categoryGrid.addEventListener("click", function (event) {
                        var btn = event.target.closest("button");
                        if (!btn) return;

                        var wasActive = btn.classList.contains("is-active");
                        categoryGrid.querySelectorAll("button").forEach(function (b) {
                            b.classList.remove("is-active");
                        });
                        if (!wasActive) {
                            btn.classList.add("is-active");
                        }
                    });
                }

                if (chipWrap) {
                    chipWrap.addEventListener("click", function (event) {
                        var btn = event.target.closest("button");
                        if (!btn) return;

                        var wasActive = btn.classList.contains("is-active");
                        chipWrap.querySelectorAll("button").forEach(function (b) {
                            b.classList.remove("is-active");
                        });
                        if (!wasActive) {
                            btn.classList.add("is-active");
                        }
                    });
                }
            }



            // Custom Dropdown Event Delegation

            if (productList) {

                productList.addEventListener("click", function (event) {

                    var toggle = event.target.closest(".zbp-dropdown-toggle");

                    var chip = event.target.closest(".zbp-slot-chip");

                    var joinBtn = event.target.closest(".zbp-join-btn");




                    if (toggle) {

                        var dropdown = toggle.closest(".zbp-custom-dropdown");

                        var menu = dropdown.querySelector(".zbp-dropdown-menu");



                        // Close other menus

                        productList.querySelectorAll(".zbp-dropdown-menu").forEach(function (m) {

                            if (m !== menu) m.hidden = true;

                        });



                        if (menu) {

                            menu.hidden = !menu.hidden;

                        }

                        return;

                    }



                    if (chip) {

                        var dropdown = chip.closest(".zbp-custom-dropdown");

                        if (!dropdown) return;



                        var menu = dropdown.querySelector(".zbp-dropdown-menu");

                        var label = dropdown.querySelector(".zbp-selected-slot-label");



                        menu.querySelectorAll(".zbp-slot-chip").forEach(function (c) {

                            c.classList.remove("is-selected");

                        });

                        chip.classList.add("is-selected");



                        if (label) {

                            label.textContent = chip.textContent;

                        }

                        if (menu) {

                            menu.hidden = true;

                        }

                        // Update join button on product card
                        var cardContent = chip.closest(".zbp-card-content");
                        var joinBtn = cardContent ? cardContent.querySelector(".zbp-join-btn") : null;
                        if (joinBtn) {
                            var slotsRaw = joinBtn.getAttribute("data-product-slots") || "[]";
                            var slots = [];
                            try {
                                slots = JSON.parse(slotsRaw);
                            } catch (e) {}
                            var selectedVal = chip.getAttribute("data-value");
                            var selectedSlotObj = slots.find(function (s) {
                                var val = (s && s.value) ? s.value : s;
                                return String(val) === String(selectedVal);
                            });
                            if (selectedSlotObj && selectedSlotObj.status === 'cancelled') {
                                joinBtn.textContent = "Class Canceled";
                                joinBtn.classList.add("is-ended");
                                joinBtn.disabled = true;
                                joinBtn.setAttribute("aria-disabled", "true");
                            } else if (selectedSlotObj && isSlotPast(selectedSlotObj, selectedDate)) {
                                joinBtn.textContent = "Class Ended";
                                joinBtn.classList.add("is-ended");
                                joinBtn.disabled = true;
                                joinBtn.setAttribute("aria-disabled", "true");
                            } else {
                                joinBtn.textContent = "Join";
                                joinBtn.classList.remove("is-ended");
                                joinBtn.disabled = false;
                                joinBtn.removeAttribute("aria-disabled");
                            }
                        }

                        return;

                    }



                    if (joinBtn) {

                        if (joinBtn.disabled || joinBtn.classList.contains("is-ended")) {

                            return;

                        }

                        console.log("ZBP Booking Mode Debug:", joinBtn.getAttribute("data-product-mode") || "");

                        var productGalleryRaw = joinBtn.getAttribute("data-product-gallery") || "[]";

                        var productGallery = [];

                        try {

                            productGallery = JSON.parse(productGalleryRaw);

                        } catch (e) {

                            productGallery = productGalleryRaw;

                        }

                        console.log("ZBP Join Click Gallery Debug:", {

                            name: joinBtn.getAttribute("data-product-name") || "",

                            gallery: productGallery,

                        });

                        openJoinModal(joinBtn);

                    }


                });

            }



            if (joinModalCloseBtn) {

                joinModalCloseBtn.addEventListener("click", closeJoinModal);

            }

            if (joinActionClose) {

                joinActionClose.addEventListener("click", closeJoinModal);

            }

            if (joinActionSubmit) {

                joinActionSubmit.addEventListener("click", function () {

                    if (joinModalProductId <= 0) {

                        console.warn("ZBP Debug: Invalid Product ID:", joinModalProductId);

                        return;

                    }

                    if (joinModalProductMode !== "free_flow" && joinModalProductMode !== "event") {

                        console.warn("ZBP Debug: Invalid Product Mode:", joinModalProductMode);

                        return;

                    }



                    var selectedSlotValue = "";

                    if (joinModalProductMode === "free_flow") {

                        var selectedChip = joinSlotChips ? joinSlotChips.querySelector(".zbp-join-slot-chip.is-selected") : null;

                        selectedSlotValue = selectedChip ? (selectedChip.getAttribute("data-value") || "") : "";

                    } else if (joinModalProductMode === "event") {

                        selectedSlotValue = (joinModalProductSlots && joinModalProductSlots.length > 0) ? (joinModalProductSlots[0].value || "") : "";

                    }



                    var addToCartUrl = buildAddToCartUrl(joinModalProductId, selectedSlotValue);

                    window.location.href = addToCartUrl;

                });

            }

            if (joinOverlay) {

                joinOverlay.addEventListener("click", closeJoinModal);

            }

            if (joinSlotToggle) {

                joinSlotToggle.addEventListener("click", function () {

                    if (!joinSlotMenu) {

                        return;

                    }

                    joinSlotMenu.hidden = !joinSlotMenu.hidden;

                });

            }

            if (joinSlotChips) {

                joinSlotChips.addEventListener("click", function (event) {

                    var chip = event.target.closest(".zbp-join-slot-chip");

                    if (!chip) {

                        return;

                    }



                    joinSlotChips.querySelectorAll(".zbp-join-slot-chip").forEach(function (c) {

                        c.classList.remove("is-selected");

                    });

                    chip.classList.add("is-selected");



                    if (joinSelectedSlotLabel) {

                        joinSelectedSlotLabel.textContent = chip.textContent;

                    }

                    if (joinSlotMenu) {

                        joinSlotMenu.hidden = true;

                    }

                    // Update modal submit button
                    if (joinActionSubmit) {
                        var selectedVal = chip.getAttribute("data-value");
                        var selectedSlotObj = joinModalProductSlots.find(function (s) {
                            var val = (s && s.value) ? s.value : s;
                            return String(val) === String(selectedVal);
                        });
                        if (selectedSlotObj && selectedSlotObj.status === 'cancelled') {
                            joinActionSubmit.textContent = "Class Canceled";
                            joinActionSubmit.classList.add("is-ended");
                            joinActionSubmit.disabled = true;
                            joinActionSubmit.setAttribute("aria-disabled", "true");
                        } else if (selectedSlotObj && isSlotPast(selectedSlotObj, selectedDate)) {
                            joinActionSubmit.textContent = "Class Ended";
                            joinActionSubmit.classList.add("is-ended");
                            joinActionSubmit.disabled = true;
                            joinActionSubmit.setAttribute("aria-disabled", "true");
                        } else {
                            joinActionSubmit.textContent = "Join";
                            joinActionSubmit.classList.remove("is-ended");
                            joinActionSubmit.disabled = false;
                            joinActionSubmit.removeAttribute("aria-disabled");
                        }
                    }

                });

            }

            document.addEventListener("keydown", function (event) {

                if (event.key === "Escape") {

                    closeJoinModal();

                }

            });



            document.addEventListener("click", function (event) {

                if (!event.target.closest(".zbp-custom-dropdown") && productList) {

                    productList.querySelectorAll(".zbp-dropdown-menu").forEach(function (m) {

                        m.hidden = true;

                    });

                }

                if (joinSlotMenu && !event.target.closest(".zbp-join-slot-wrap")) {

                    joinSlotMenu.hidden = true;

                }

            });



            var dayNames = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];



            function startOfDay(date) {

                var d = new Date(date);

                d.setHours(0, 0, 0, 0);

                return d;

            }



            function addDays(date, days) {

                var d = new Date(date);

                d.setDate(d.getDate() + days);

                return d;

            }



            function formatDateKey(date) {

                var y = date.getFullYear();

                var m = String(date.getMonth() + 1).padStart(2, "0");

                var d = String(date.getDate()).padStart(2, "0");

                return y + "-" + m + "-" + d;

            }



            function formatDateDisplay(date) {

                var d = String(date.getDate()).padStart(2, "0");

                var m = String(date.getMonth() + 1).padStart(2, "0");

                var y = date.getFullYear();

                return d + "." + m + "." + y;

            }



            function parseDateKeyLocal(dateKey) {

                var parts = dateKey.split("-");

                if (parts.length !== 3) {

                    return startOfDay(new Date());

                }

                return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

            }



            function formatHeaderRange(start, end) {

                var startLabel = String(start.getDate()).padStart(2, "0") + "." + String(start.getMonth() + 1).padStart(2, "0") + ".";

                var endLabel = String(end.getDate()).padStart(2, "0") + "." + String(end.getMonth() + 1).padStart(2, "0") + "." + end.getFullYear();

                return startLabel + " - " + endLabel;

            }



            function getWeekStartMonday(date) {

                var dayIndex = date.getDay();

                var mondayOffset = dayIndex === 0 ? 6 : dayIndex - 1;

                return addDays(startOfDay(date), -mondayOffset);

            }



            var today = startOfDay(new Date());

            var currentWeekStart = getWeekStartMonday(today);

            var weekStart = new Date(currentWeekStart);

            var selectedDate = new Date(today);



            function isSameDate(a, b) {

                return formatDateKey(a) === formatDateKey(b);

            }



            function emitDateSelected(date) {

                var selectedKey = formatDateKey(date);

                wrapper.dispatchEvent(

                    new CustomEvent("zbp_date_selected", {

                        detail: {

                            selected_date: selectedKey,

                        },

                    })

                );





            }



            function renderWeek() {

                var weekEnd = addDays(weekStart, 6);

                weekRange.textContent = formatHeaderRange(weekStart, weekEnd);



                dateRow.innerHTML = "";



                for (var i = 0; i < 7; i += 1) {

                    var dayDate = addDays(weekStart, i);

                    var button = document.createElement("button");

                    button.type = "button";

                    button.className = "zbp-date-item";

                    button.setAttribute("data-date", formatDateKey(dayDate));



                    if (isSameDate(dayDate, selectedDate)) {

                        button.classList.add("is-active");

                    }



                    var number = document.createElement("span");

                    number.className = "zbp-date-number";

                    number.textContent = String(dayDate.getDate());



                    var weekday = document.createElement("span");

                    weekday.className = "zbp-date-day";

                    weekday.textContent = dayNames[i];



                    button.appendChild(number);

                    button.appendChild(weekday);



                    button.addEventListener("click", function (event) {

                        var newDate = startOfDay(parseDateKeyLocal(event.currentTarget.getAttribute("data-date")));

                        selectedDate = newDate;

                        renderWeek();

                        emitDateSelected(selectedDate);

                    });



                    dateRow.appendChild(button);

                }



                if (prevBtn) {

                    prevBtn.disabled = weekStart.getTime() <= currentWeekStart.getTime();

                }

            }



            if (prevBtn) {

                prevBtn.addEventListener("click", function () {

                    var nextPrevStart = addDays(weekStart, -7);



                    if (nextPrevStart.getTime() < currentWeekStart.getTime()) {

                        return;

                    }



                    weekStart = nextPrevStart;

                    renderWeek();

                    emitDateSelected(selectedDate);

                });

            }



            if (nextBtn) {

                nextBtn.addEventListener("click", function () {

                    weekStart = addDays(weekStart, 7);

                    renderWeek();

                    emitDateSelected(selectedDate);

                });

            }



            renderWeek();

            emitDateSelected(selectedDate);

        });

    });

})();

