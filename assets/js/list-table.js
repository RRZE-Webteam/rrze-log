"use strict";
jQuery(document).ready(function($) {
    var $listTable = $('table.wp-list-table');
    $listTable.find("tr.data").show();
    $listTable.find("tr.metadata").hide();

    $listTable.find("tr.data").click(function() {
        $(this).next('tr.metadata').toggle(0, function() {
            $(this).toggleClass("metadata-hidden metadata-visible");
        });
    });
});
