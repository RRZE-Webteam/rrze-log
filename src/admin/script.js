"use strict";
jQuery(document).ready(function ($) {
    let $listTable = $("table.wp-list-table");
    $listTable.find("tr.data").show();
    $listTable.find("tr.metadata").hide();

    $listTable.find("tr.data").click(function () {
        $("tr.data").not(this).next("tr.metadata").hide();
        $(this).next("tr.metadata").toggle("fast");
    });
});
