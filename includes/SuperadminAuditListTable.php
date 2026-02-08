<?php

declare(strict_types=1);

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * SuperadminAuditListTable
 *
 * Wrapper around AuditListTable that reads from the dedicated superadmin audit log file.
 */
final class SuperadminAuditListTable extends AuditListTable {

    /**
     * Constructor: binds this table to the superadmin audit log file.
     */
    public function __construct() {
        parent::__construct(Constants::SUPERADMIN_AUDIT_LOG_FILE);
    }
}
