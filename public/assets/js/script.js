console.log("ZBP Script Running");
(function () {
    "use strict";
    console.log("ZBP Debug: script loaded");

    document.addEventListener("DOMContentLoaded", function () {
        var wrappers = document.querySelectorAll(".zbp-booking-ui");
        console.log("ZBP Debug: wrappers found:", wrappers.length);

        function escapeHtml(value) {
            return String(value || "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/\"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function slotLabel(slot) {
            if (!slot) return "Unavailable";
            if (typeof slot === "string") return slot;
            if (slot.label) return slot.label;
            return "Unavailable";
        }

        function renderEmptyState(productList) {
            productList.innerHTML =
                '<article class="zbp-product-card zbp-empty-state">' +
                '<div class="zbp-card-content">' +
                "<h4>No booking products found.</h4>" +
                "<p>Try another date or adjust your filters.</p>" +
                "</div>" +
                "</article>";
        }

        function renderProducts(productList, products) {
            if (!productList) {
                return;
            }

            if (!Array.isArray(products) || products.length === 0) {
                renderEmptyState(productList);
                return;
            }

            var html = products
                .map(function (product) {
                    var isSlotBased = product.mode !== "event";
                    var cardClass = isSlotBased ? "zbp-slot-card" : "zbp-event-card";
                    var imageHtml = product.image
                        ? '<img src="' + escapeHtml(product.image) + '" alt="' + escapeHtml(product.name) + '" class="zbp-product-image" />'
                        : '<span class="zbp-image-placeholder">&#128247;</span>';
                    var durationText = escapeHtml(product.zen_duration || product.duration || "Duration N/A");
                    var zcoins = escapeHtml(product.zen_coins || "0");
                    var duration = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px; margin-top: -2px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> (' + durationText + ')';
                    var slots = [];
                    if (Array.isArray(product.slots)) {
                        slots = product.slots;
                    } else if (product.slots && typeof product.slots === "object") {
                        slots = Object.keys(product.slots).map(function (k) { return product.slots[k]; });
                    }
                    console.log("cstslot", slots);

                    var instructorHtml = product.zen_instructor
                        ? '<span class="zbp-instructor"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px; margin-top: -2px; margin-left: 10px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg> ' + escapeHtml(product.zen_instructor) + '</span>'
                        : '';

                    if (isSlotBased) {
                        var chips = slots.length
                            ? slots
                                .map(function (slot) {
                                    var label = slotLabel(slot);
                                    var val = (slot && slot.value) ? slot.value : label;
                                    return '<button type="button" class="zbp-slot-chip" data-value="' + escapeHtml(val) + '">' + escapeHtml(label) + "</button>";
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
                            "<label>Choose Slot</label>" +
                            '<button type="button" class="zbp-dropdown-toggle">' +
                            '<span class="zbp-selected-slot-label">Select a slot</span>' +
                            '<span class="zbp-chevron">&#9662;</span>' +
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
                            '<button class="zbp-join-btn" type="button">Join</button>' +
                            "</div>" +
                            "</div>" +
                            "</article>"
                        );
                    }

                    var firstSlotStr = slots.length ? slotLabel(slots[0]) : "No slot available";
                    var formattedTimeBlock = firstSlotStr;

                    if (slots.length && firstSlotStr !== "Unavailable") {
                        var rawDur = product.zen_duration || product.duration || "";
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

                    var maxSpots = product.max_spots || 1;
                    var bookedSpots = product.booked_spots || 0;

                    if (product.debug_info) {
                        console.log("ZBP Debug for slot quantity " + product.id, product.debug_info);
                    }

                    var volumeText = bookedSpots + "/" + maxSpots + " (Voll)";
                    var volumeHtml = '<p style="display: flex; align-items: center; gap: 4px; color: var(--zbp-accent); font-weight: 500; font-size: 14px;">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-top:-2px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>' +
                        escapeHtml(volumeText) +
                        "</p>";

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
                        '<div class="zbp-event-meta">' +
                        '<p style="display: flex; align-items: center; gap: 4px;">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-top:-2px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>' +
                        escapeHtml(formattedTimeBlock) +
                        "</p>" +
                        volumeHtml +
                        (instructorHtml ? "<p>" + instructorHtml + "</p>" : "") +
                        "</div>" +
                        '<div class="zbp-card-bottom">' +
                        "<div></div>" +
                        '<button class="zbp-join-btn" type="button">Join</button>' +
                        "</div>" +
                        "</div>" +
                        "</article>"
                    );
                })
                .join("");

            productList.innerHTML = html;
        }

        function fetchSlots(dateKey, wrapper, productList) {
            if (!window.zbpAjax || !zbpAjax.ajaxUrl) {
                console.error("ZBP Debug: AJAX config missing");
                return;
            }

            var payload = new URLSearchParams();
            payload.append("action", "zbp_get_slots");
            payload.append("date", dateKey);
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
                        console.error("ZBP Debug: slot fetch failed", result);
                        renderEmptyState(productList);
                        return;
                    }

                    var products = result.data && Array.isArray(result.data.products) ? result.data.products : [];
                    console.log("ZBP Debug: zbp_get_slots raw products", products);
                    products.forEach(function (product) {
                        console.log("expirence-meta-fields: product meta for " + (product ? product.name : "Unknown"), product ? product.debug_meta : {});
                        console.log("ZBP Debug: product slots from zbp_get_slots", {
                            product_id: product && product.id ? product.id : 0,
                            product_name: product && product.name ? product.name : "",
                            mode: product && product.mode ? product.mode : "",
                            slots: product && Array.isArray(product.slots) ? product.slots : [],
                        });
                    });
                    renderProducts(productList, products);
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
                    console.error("ZBP Debug: ajax error", error);
                    renderEmptyState(productList);
                });
        }

        wrappers.forEach(function (wrapper) {
            var filterToggle = wrapper.querySelector(".zbp-filter-toggle");
            var modal = wrapper.querySelector(".zbp-filter-modal");
            var overlay = wrapper.querySelector(".zbp-overlay");
            var closeBtn = wrapper.querySelector(".zbp-modal-close");
            var confirmBtn = wrapper.querySelector(".zbp-confirm-btn");
            var weekRange = wrapper.querySelector(".zbp-week-range");
            var dateRow = wrapper.querySelector(".zbp-date-row");
            var prevBtn = wrapper.querySelector(".zbp-nav-prev");
            var nextBtn = wrapper.querySelector(".zbp-nav-next");
            var productList = wrapper.querySelector(".zbp-product-list");

            if (!dateRow || !weekRange || !productList) {
                console.log("ZBP Debug: init aborted - missing date row, week range, or product list");
                return;
            }

            wrapper.addEventListener("zbp_date_selected", function (event) {
                console.log("date_selected");
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
                confirmBtn.addEventListener("click", closeModal);
            }

            // Custom Dropdown Event Delegation
            if (productList) {
                productList.addEventListener("click", function (event) {
                    var toggle = event.target.closest(".zbp-dropdown-toggle");
                    var chip = event.target.closest(".zbp-slot-chip");

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
                    }
                });
            }

            document.addEventListener("click", function (event) {
                if (!event.target.closest(".zbp-custom-dropdown") && productList) {
                    productList.querySelectorAll(".zbp-dropdown-menu").forEach(function (m) {
                        m.hidden = true;
                    });
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

                console.log("ZBP Debug:", {
                    stage: "date_selected",
                    selected_date: selectedKey,
                });
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
