"use strict";
(() => {
  // src/admin/admin.js
  function rrzeLogToggleMetadata(e) {
    var button = e.currentTarget;
    var row = jQuery(button).closest("tr.data");
    var metaRow = row.next("tr.metadata");
    if (metaRow.length === 0) {
      return;
    }
    var isOpen = row.hasClass("is-open");
    row.toggleClass("is-open", !isOpen);
    metaRow.toggleClass("is-open", !isOpen);
    button.setAttribute("aria-expanded", isOpen ? "false" : "true");
  }
  function rrzeLogInit() {
    jQuery("table.wp-list-table").on(
      "click",
      "button.rrze-log-toggle",
      rrzeLogToggleMetadata
    );
  }
  jQuery(document).ready(rrzeLogInit);
})();
//# sourceMappingURL=rrze-log.js.map
