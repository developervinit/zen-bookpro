var rawDur = "1.5 hours";
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
console.log("Duration:", durationMinutes);

var label = "2:00 pm";
var match12 = label.match(/(\d+):(\d+)\s*(am|pm)/i);
var match24 = label.match(/^(\d{1,2}):(\d{2})$/);
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
    
    var timeBlockLabel = startHH + ":" + startMM + "-" + endHH + ":" + endMM + " (" + durationMinutes + " min)";
    console.log("Time block:", timeBlockLabel);
}
