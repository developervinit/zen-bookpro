(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        var wrappers = document.querySelectorAll(".zbp-booking-ui");

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

            if (!dateRow || !weekRange) {
                return;
            }

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
                        var newDate = startOfDay(new Date(event.currentTarget.getAttribute("data-date")));
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
                    selectedDate = new Date(weekStart);
                    renderWeek();

                    console.log("ZBP Debug:", {
                        stage: "week_change",
                        direction: "prev",
                        week_start: formatDateKey(weekStart),
                        week_end: formatDateKey(addDays(weekStart, 6)),
                    });

                    emitDateSelected(selectedDate);
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener("click", function () {
                    weekStart = addDays(weekStart, 7);
                    selectedDate = new Date(weekStart);
                    renderWeek();

                    console.log("ZBP Debug:", {
                        stage: "week_change",
                        direction: "next",
                        week_start: formatDateKey(weekStart),
                        week_end: formatDateKey(addDays(weekStart, 6)),
                    });

                    emitDateSelected(selectedDate);
                });
            }

            renderWeek();

            console.log("ZBP Debug:", {
                stage: "calendar_init",
                today: formatDateKey(today),
                week_start: formatDateKey(weekStart),
                week_end: formatDateKey(addDays(weekStart, 6)),
            });

            emitDateSelected(selectedDate);
        });
    });
})();
