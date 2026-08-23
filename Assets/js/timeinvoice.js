// CSP-safe: delegated handlers only, bound from document. No inline handlers.
(function () {
    "use strict";
    // Confirm destructive draft delete.
    jQuery(document).on("click", ".timeinvoice-delete", function (e) {
        if (!window.confirm("Delete this draft invoice?")) {
            e.preventDefault();
        }
    });
    // Confirm the send transition (freezes the snapshot + assigns a number).
    jQuery(document).on("click", ".timeinvoice-send", function (e) {
        if (!window.confirm("Send this invoice? Its number and totals will be locked.")) {
            e.preventDefault();
        }
    });
}());
