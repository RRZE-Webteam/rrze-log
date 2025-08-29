"use strict";
jQuery(function ($) {
    const $listTable = $("table.wp-list-table");

    $listTable.on("click", "tr.data td.column-message", function () {
        const $row = $(this).closest("tr.data");
        const $metaRow = $row.next("tr.metadata");

        $("tr.metadata").not($metaRow).hide().addClass("metadata-hidden");

        $metaRow.toggle().toggleClass("metadata-hidden");
    });
});
