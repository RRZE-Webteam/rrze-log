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
}

jQuery(rrzeLogInit);