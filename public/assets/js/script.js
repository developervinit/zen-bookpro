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
                    var duration = escapeHtml(product.duration || "Duration N/A");
                    var price = escapeHtml(product.price || "N/A");
                    var slots = [];
                    if (Array.isArray(product.slots)) {
                        slots = product.slots;
                    } else if (product.slots && typeof product.slots === "object") {
                        slots = Object.keys(product.slots).map(function(k) { return product.slots[k]; });
                    }
                    console.log("cstslot", slots);

                    if (isSlotBased) {
                        var options = slots.length
                            ? slots
                                  .map(function (slot) {
                                      var label = slotLabel(slot);
                                      var val = (slot && slot.value) ? slot.value : label;
                                      return '<option value="' + escapeHtml(val) + '">' + escapeHtml(label) + "</option>";
                                  })
                                  .join("")
                            : '<option value="">No slots available</option>';

                        var chips = slots.length
                            ? slots
                                  .map(function (slot) {
                                      return "<span>" + escapeHtml(slotLabel(slot)) + "</span>";
                                  })
                                  .join("")
                            : "<span>No slots</span>";

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
                            '<div class="zbp-coins">Price: <span>' +
                            price +
                            "</span></div>" +
                            "</div>" +
                            '<div class="zbp-select-wrap">' +
                            "<label>Choose Slot</label>" +
                            "<select>" +
                            options +
                            "</select>" +
                            "</div>" +
                            '<div class="zbp-slot-chips">' +
                            chips +
                            "</div>" +
                            '<div class="zbp-card-bottom">' +
                            '<div class="zbp-duration">' +
                            duration +
                            "</div>" +
                            '<button class="zbp-join-btn" type="button">Join</button>' +
                            "</div>" +
                            "</div>" +
                            "</article>"
                        );
                    }

                    var firstSlot = slots.length ? slotLabel(slots[0]) : "No slot available";

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
                        '<div class="zbp-coins">Price: <span>' +
                        price +
                        "</span></div>" +
                        "</div>" +
                        '<div class="zbp-event-meta">' +
                        "<p>" +
                        escapeHtml(firstSlot) +
                        "</p>" +
                        "<p>" +
                        duration +
                        "</p>" +
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
