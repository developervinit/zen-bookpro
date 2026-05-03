(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        var wrappers = document.querySelectorAll(".zbp-booking-ui");

        wrappers.forEach(function (wrapper) {
            var dateItems = wrapper.querySelectorAll(".zbp-date-item");
            var filterToggle = wrapper.querySelector(".zbp-filter-toggle");
            var modal = wrapper.querySelector(".zbp-filter-modal");
            var overlay = wrapper.querySelector(".zbp-overlay");
            var closeBtn = wrapper.querySelector(".zbp-modal-close");
            var confirmBtn = wrapper.querySelector(".zbp-confirm-btn");
            var weekRange = wrapper.querySelector(".zbp-week-range");
            var weekIndex = 0;

            function openModal() {
                modal.hidden = false;
                overlay.hidden = false;
            }

            function closeModal() {
                modal.hidden = true;
                overlay.hidden = true;
            }

            dateItems.forEach(function (item) {
                item.addEventListener("click", function () {
                    dateItems.forEach(function (date) {
                        date.classList.remove("is-active");
                    });
                    item.classList.add("is-active");
                });
            });

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

            var prevBtn = wrapper.querySelector(".zbp-nav-prev");
            var nextBtn = wrapper.querySelector(".zbp-nav-next");
            var baseLabel = "23.02. - 01.03.2026";

            function refreshWeekLabel() {
                if (!weekRange) {
                    return;
                }
                if (weekIndex === 0) {
                    weekRange.textContent = baseLabel;
                    return;
                }
                weekRange.textContent = baseLabel + " (Week " + (weekIndex + 1) + ")";
            }

            if (prevBtn) {
                prevBtn.addEventListener("click", function () {
                    weekIndex = Math.max(0, weekIndex - 1);
                    refreshWeekLabel();
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener("click", function () {
                    weekIndex += 1;
                    refreshWeekLabel();
                });
            }
        });
    });
})();
