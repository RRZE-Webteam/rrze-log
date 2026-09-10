<?php

declare(strict_types=1);

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * WebsupportAuditListTable
 *
 * Wrapper around AuditListTable that reads from the dedicated websupport audit log file.
 */
final class WebsupportAuditListTable extends AuditListTable {

    /**
     * Constructor: binds this table to the websupport audit log file.
     */
    public function __construct() {
        parent::__construct(Constants::WEBSUPPORT_AUDIT_LOG_FILE);
    }
}
