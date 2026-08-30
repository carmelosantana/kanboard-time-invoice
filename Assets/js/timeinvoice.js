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
}());
