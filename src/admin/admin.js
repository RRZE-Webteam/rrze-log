"use strict";

function rrzeLogToggleCell($cell) {
    var $full;
    var $toggle;
    var $copy;
    var isOpen;

    $full = $cell.find(".rrze-log-message-full").first();
    if (!$full.length) {
        return;
    }

    $toggle = $cell.find("a.rrze-log-message-toggle").first();
    $copy = $cell.find("button.rrze-log-copy").first();

    isOpen = $cell.hasClass("rrze-log-expanded");

    if (isOpen) {
        $cell.removeClass("rrze-log-expanded");

        if ($toggle.length) {
            $toggle.attr("aria-expanded", "false");
        }

        $full.attr("aria-hidden", "true");

        if ($copy.length) {
            $copy.hide();
        }
    } else {
        $cell.addClass("rrze-log-expanded");

        if ($toggle.length) {
            $toggle.attr("aria-expanded", "true");
        }

        $full.attr("aria-hidden", "false");

        if ($copy.length) {
            $copy.show();
        }
    }
}

function rrzeLogCopyToClipboard(text) {
    var $tmp;

    if (!text) {
        return;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
        return;
    }

    $tmp = jQuery("<textarea>");
    $tmp.val(text).css({ position: "fixed", left: "-9999px", top: "0" });
    jQuery("body").append($tmp);
    $tmp[0].select();
    document.execCommand("copy");
    $tmp.remove();
}

function rrzeLogSubmitFilter($select) {
    var $form;

    $form = $select.closest("form");
    if (!$form.length) {
        return;
    }

    $form.trigger("submit");
}

function rrzeLogHandleLevelChange() {
    var $select;
    var $fileSelect;

    $select = jQuery(this);
    $fileSelect = jQuery("#rrze-log-file-filter");

    if ($fileSelect.length) {
        $fileSelect.prop("disabled", true);
    }

    rrzeLogSubmitFilter($select);
}

function rrzeLogHandleFileChange() {
    rrzeLogSubmitFilter(jQuery(this));
}

function rrzeLogUpdateRotationState($fieldset) {
    var $rotation;
    var $maxLines;
    var hasRotation;

    $rotation = $fieldset.find("[data-rrze-log-rotation]").first();
    $maxLines = $fieldset.find("[data-rrze-log-max-lines]").first();

    if (!$rotation.length || !$maxLines.length) {
        return;
    }

    hasRotation = $rotation.val() !== "none";
    $fieldset.toggleClass("rrze-log-has-rotation", hasRotation);
}

function rrzeLogHandleRotationChange() {
    rrzeLogUpdateRotationState(jQuery(this).closest(".rrze-log-action-level-settings"));
}

function rrzeLogInitRotationSetting() {
    rrzeLogUpdateRotationState(jQuery(this));
}

function rrzeLogInitRotationSettings($) {
    $(".rrze-log-action-level-settings").each(rrzeLogInitRotationSetting);
}

function rrzeLogInit($) {
    $(document).on("click", "a.rrze-log-message-toggle", function (e) {
        var $cell;

        e.preventDefault();

        $cell = $(this).closest("td.column-message");
        if (!$cell.length) {
            return;
        }

        rrzeLogToggleCell($cell);
    });

    $(document).on("click", "button.rrze-log-copy", function (e) {
        var text;

        e.preventDefault();

        text = $(this).attr("data-copy") || "";
        rrzeLogCopyToClipboard(text);
    });

    $(document).on("change", "#levels-filter", rrzeLogHandleLevelChange);
    $(document).on("change", "#rrze-log-file-filter", rrzeLogHandleFileChange);
    $(document).on("change", "[data-rrze-log-rotation]", rrzeLogHandleRotationChange);

    rrzeLogInitRotationSettings($);
}

jQuery(rrzeLogInit);
