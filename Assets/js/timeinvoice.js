// CSP-safe: delegated handlers only, bound from document. No inline handlers.
(function () {
    "use strict";
    // Confirm destructive draft delete.
    jQuery(document).on("click", ".timeinvoice-delete", function (e) {
        if (!window.confirm("Delete this draft invoice?")) {
            e.preventDefault();
        }
    });
    // Confirm the issue transition (freezes the snapshot + assigns a number).
    jQuery(document).on("click", ".timeinvoice-send", function (e) {
        if (!window.confirm("Issue this invoice? Its number and totals will be locked.")) {
            e.preventDefault();
        }
    });
    // Generate an AI cover note and drop it into the notes textarea.
    jQuery(document).on("click", ".timeinvoice-generate-note", function (e) {
        e.preventDefault();
        var $btn = jQuery(this);
        var $form = $btn.closest("form");
        var url = $btn.data("url");
        $btn.prop("disabled", true).addClass("timeinvoice-loading");
        jQuery.ajax({
            url: url,
            method: "POST",
            dataType: "json",
            data: $form.serialize()
        }).done(function (res) {
            if (res && res.note) {
                $form.find("textarea[name='notes']").val(res.note);
            } else {
                window.alert((res && res.error) || "No cover note returned.");
            }
        }).fail(function (xhr) {
            var msg = "Cover note generation failed.";
            try { var j = JSON.parse(xhr.responseText); if (j && j.error) { msg = j.error; } } catch (err) {}
            window.alert(msg);
        }).always(function () {
            $btn.prop("disabled", false).removeClass("timeinvoice-loading");
        });
    });

    // Live draft totals. Debounced; reuses the same $form.serialize() seam as
    // the cover note, so project_id arrives in the POST body.
    var totalsTimer = null;
    function refreshTotals() {
        var $box = jQuery(".timeinvoice-totals");
        if (!$box.length) { return; }
        var $form = $box.closest("form");
        $box.addClass("timeinvoice-loading");
        jQuery.ajax({
            url: $box.data("url"),
            method: "POST",
            dataType: "json",
            data: $form.serialize()
        }).done(function (r) {
            if (!r || r.error) {
                $box.text(r && r.error ? r.error : "Could not calculate totals.");
                return;
            }
            var html = "<strong>" + r.hours.toFixed(2) + "</strong> hours \u00b7 " +
                r.line_count + " line item(s) \u00b7 Subtotal " + r.symbol + r.subtotal.toFixed(2);
            if (r.tax) { html += " \u00b7 Tax " + r.symbol + r.tax.toFixed(2); }
            html += " \u00b7 <strong>Total " + r.symbol + r.total.toFixed(2) + "</strong>";
            $box.html(html);
        }).fail(function () {
            $box.text("Could not calculate totals.");
        }).always(function () {
            $box.removeClass("timeinvoice-loading");
        });
    }

    // Delegated from document, scoped to the form that owns a totals region, so
    // the handler is inert on every other page.
    jQuery(document).on("change keyup", "form :input", function () {
        var $box = jQuery(".timeinvoice-totals");
        if (!$box.length) { return; }
        var fields = String($box.data("recalc-fields") || "").split(",");
        if (jQuery.inArray(this.name, fields) === -1) { return; }
        window.clearTimeout(totalsTimer);
        totalsTimer = window.setTimeout(refreshTotals, 400);
    });

    jQuery(function () {
        if (jQuery(".timeinvoice-totals").length) { refreshTotals(); }
    });
}());
