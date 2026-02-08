<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\File\Flock;
use RRZE\Log\File\FlockException;
use RRZE\Log\REST\StrayOutputSniffer;

class Logger {
    protected object $options;
    protected string $siteUrl = '';
    protected string $logFile = '';
    protected int $filePermissions = 0644;

    /** Pending JSON line written under lock (no anonymous functions) */ 
    protected string $pendingLine = '';

    public function __construct()  {
        $this->options = Options::getOptions();
    }

    public function loaded(): void  {
        $this->siteUrl = site_url();
    }

    public function error(string $message, array $context = []): bool {
        return $this->log('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): bool {
        return $this->log('WARNING', $message, $context);
    }

    public function notice(string $message, array $context = []): bool {
        return $this->log('NOTICE', $message, $context);
    }

    public function info(string $message, array $context = []): bool {
        return $this->log('INFO', $message, $context);
    }

    /**
     * Audit log (admin/superadmin actions).
     */
    public function audit(string $message, array $context = []): bool {
        return $this->logToFile(Constants::AUDIT_LOG_FILE, 'AUDIT', $message, $context);
    }

    /**
        * Superadmin audit log (multisite only).
        */
    public function auditSuperadmin(string $message, array $context = []): bool {
        if (!is_multisite()) {
            return $this->audit($message, $context);
        }
        return $this->logToFile(Constants::SUPERADMIN_AUDIT_LOG_FILE, 'AUDIT', $message, $context);
    }


    /**
     * Default logger (rrze-log.log).
     */
    protected function log(string $level, string $message, array $context = []): bool {
        return $this->logToFile(Constants::LOG_FILE, $level, $message, $context);
    }

    /**
     * Core implementation: write a single entry to the given file.
     */
    protected function logToFile(string $file, string $level, string $message, array $context = []): bool {
        $this->logFile = $file;

        $entry = [
            'datetime' => $this->nowMicro(),
            'siteurl'  => $this->siteUrl ?: site_url(),
            'level'    => $level,
            'message'  => $message,
            'context'  => $this->normalizeContext($context),
        ];

        $this->pendingLine = $this->jsonEncodeSafe($entry);

        return $this->writePendingLine();
    }

    protected function writePendingLine(): bool {
        $isNew = !file_exists($this->logFile);

        try {
            $lock = new Flock($this->logFile);
            $lock->withLock([$this, 'writeLockedLine'], 200);
        } catch (FlockException $e) {
            return false;
        }

        if ($isNew) {
            @chmod($this->logFile, $this->filePermissions);
        }

        return true;
    }

    /**
     * Callback used by Flock::withLock(). Must accept exactly one argument.
     */
    public function writeLockedLine(Flock $lock): void  {
        $lock->writeln($this->pendingLine, true, false);
    }

    protected function jsonEncodeSafe(array $data): string {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
                | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (!is_string($json) || $json === '') {
            $json = '{}';
        }

        return $json;
    }

    protected function normalizeContext(array $ctx): array  {
        foreach ($ctx as $k => $v) {
            if ($v instanceof \Throwable) {
                $ctx[$k] = [
                    'exception' => get_class($v),
                    'message'   => $v->getMessage(),
                    'code'      => $v->getCode(),
                    'file'      => $v->getFile(),
                    'line'      => $v->getLine(),
                ];
            } elseif (is_resource($v)) {
                $ctx[$k] = 'RESOURCE(' . get_resource_type($v) . ')';
            } elseif (is_object($v) && !method_exists($v, '__toString')) {
                $ctx[$k] = ['object' => get_class($v)];
            }
        }

        return $ctx;
    }

    protected function nowMicro(): string {
        $t  = microtime(true);
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $t)) ?: new \DateTimeImmutable('now');
        return $dt->format('Y-m-d H:i:s.uP');
    }
    
    /**
    * Attach the StrayOutputSniffer to REST requests.
    *
    * @param int  $site  Limit to specific blog_id (0 = all sites)
    * @param bool $guard If true, stray output is cleaned to avoid broken JSON
    */
   public static function attachRestSniffer(int $site = 0, bool $guard = true): void {
       $logger = new self();
       $logger->loaded();

       $sniffer = new StrayOutputSniffer([
           'enabled' => true,
           'guard' => $guard,
           'site' => $site,
           'logger' => [$logger, 'logRestStrayOutput'],
       ]);

       $sniffer->boot();
   }

   /**
    * Callback for StrayOutputSniffer: writes a warning entry.
    *
    * @param string $message
    */
   public function logRestStrayOutput(string $message): void {
       $this->warning('REST stray output detected', [
           'detail' => $message,
       ]);
   }

}
