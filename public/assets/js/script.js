(function () {
    "use strict";
    console.log("ZBP Debug: script loaded");

    document.addEventListener("DOMContentLoaded", function () {
        var wrappers = document.querySelectorAll(".zbp-booking-ui");
        console.log("ZBP Debug: wrappers found:", wrappers.length);
        console.log("ZBP Debug: next button:", document.querySelector(".zbp-nav-next"));
        console.log("ZBP Debug: prev button:", document.querySelector(".zbp-nav-prev"));

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

            console.log("ZBP Debug: wrapper next button:", nextBtn);
            console.log("ZBP Debug: wrapper prev button:", prevBtn);
            console.log("ZBP Debug: week range node:", weekRange);
            console.log("ZBP Debug: date row node:", dateRow);

            if (!dateRow || !weekRange) {
                console.log("ZBP Debug: init aborted - missing dateRow or weekRange");
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
                console.log("ZBP Debug: rendering calendar");
                console.log("ZBP Debug: weekStart:", weekStart);
                console.log("ZBP Debug: weekEnd:", weekEnd);
                weekRange.textContent = formatHeaderRange(weekStart, weekEnd);

                dateRow.innerHTML = "";
                var generatedDates = [];

                for (var i = 0; i < 7; i += 1) {
                    var dayDate = addDays(weekStart, i);
                    generatedDates.push(formatDateKey(dayDate));
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

                console.log("ZBP Debug:", {
                    stage: "calendar_audit",
                    today: formatDateKey(today),
                    week_start: formatDateKey(weekStart),
                    week_end: formatDateKey(weekEnd),
                    generated_dates: generatedDates,
                    selected_date: formatDateKey(selectedDate),
                });
            }

            if (prevBtn) {
                prevBtn.addEventListener("click", function () {
                    console.log("ZBP Debug: prev clicked");
                    var nextPrevStart = addDays(weekStart, -7);

                    if (nextPrevStart.getTime() < currentWeekStart.getTime()) {
                        return;
                    }

                    weekStart = nextPrevStart;
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
                    console.log("ZBP Debug: next clicked");
                    weekStart = addDays(weekStart, 7);
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
            console.log("ZBP Debug: event listeners attached");

            renderWeek();
            console.log("ZBP Debug: initial render triggered");

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
