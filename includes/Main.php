<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Main {

    /**
     * Option name.
     * @var string
     */
    public string $optionName;

    /**
     * Options values.
     * @var object
     */
    public object $options;

    /**
     * Logger object.
     * @var Logger|null
     */
    protected ?Logger $logger = null;

    /**
     * Audit logger (admin/superadmin actions).
     * @var AdminAudit|null
     */
    protected ?AdminAudit $adminAudit = null;

    /**
     * Set properties.
     */
    public function __construct() {
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    /**
     * Initiate classes & add hooks.
     */
    public function loaded(): void {
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);

        $settings = new Settings();
        $settings->loaded();

        if (!$this->options->enabled) {
            return;
        }

        $this->logger = new Logger();
        $this->logger->loaded();

        if (!empty($this->options->auditEnabled)) {
            $this->adminAudit = new AdminAudit($this->logger);
            $this->adminAudit->register();
        }

        add_action('rrze.log.error', [$this, 'logError'], 10, 2);
        add_action('rrze.log.warning', [$this, 'logWarning'], 10, 2);
        add_action('rrze.log.notice', [$this, 'logNotice'], 10, 2);
        add_action('rrze.log.info', [$this, 'logInfo'], 10, 2);

        Logger::attachRestSniffer();

        Cron::init();
    }

    /**
     * ERROR log type.
     * @param mixed $message
     * @param array $context
     */
    public function logError($message, $context = []): void {
        if (!$this->logger) {
            return;
        }

        $content = $this->sanitizeArgs($message, $context);
        if (!$content) {
            return;
        }

        $this->logger->error($content['message'], $content['context']);
    }

    /**
     * WARNING log type.
     * @param mixed $message
     * @param array $context
     */
    public function logWarning($message, $context = []): void {
        if (!$this->logger) {
            return;
        }

        $content = $this->sanitizeArgs($message, $context);
        if (!$content) {
            return;
        }

        $this->logger->warning($content['message'], $content['context']);
    }

    /**
     * NOTICE log type.
     * @param mixed $message
     * @param array $context
     */
    public function logNotice($message, $context = []): void {
        if (!$this->logger) {
            return;
        }

        $content = $this->sanitizeArgs($message, $context);
        if (!$content) {
            return;
        }

        $this->logger->notice($content['message'], $content['context']);
    }

    /**
     * INFO log type.
     * @param mixed $message
     * @param array $context
     */
    public function logInfo($message, $context = []): void {
        if (!$this->logger) {
            return;
        }

        $content = $this->sanitizeArgs($message, $context);
        if (!$content) {
            return;
        }

        $this->logger->info($content['message'], $content['context']);
    }

    /**
     * Sanitize log arguments.
     * @param mixed $message
     * @param mixed $context
     * @return array|false
     */
    protected function sanitizeArgs($message, $context) {
        if (empty($message)) {
            return false;
        }

        if (is_string($message) && empty($context)) {
            $context = [];
        } elseif (is_array($message) && empty($context)) {
            $context = $message;
            $message = '';
        }

        if (!is_array($context)) {
            return false;
        }

        $message = !$message && $context ? '{' . implode('} {', array_keys($context)) . '}' : $message;
        $message = $context ? $this->interpolate((string) $message, $context) : (string) $message;

        return [
            'message' => trim((string) $message),
            'context' => $context,
        ];
    }

    /**
     * Variable interpolation.
     * @param string $message
     * @param array  $context
     * @return string
     */
    protected function interpolate(string $message, array $context): string {
        $replace = [];

        foreach ($context as $key => $value) {
            $fromStr = '{' . $key . '}';
            $toStr = '';

            if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                $toStr = (string) $value;
            }

            $replace[$fromStr] = $toStr;
        }

        return strtr($message, $replace);
    }

    /**
     * Register admin styles & scripts.
     * @param string $hook
     */
    public function adminEnqueueScripts(string $hook): void {
        if (!str_contains($hook, 'page_rrze-log') && !str_contains($hook, 'page_rrze-log-debug')) {
            return;
        }

        $assetFile = include(plugin()->getPath('build') . 'admin.asset.php');

        wp_register_style(
            'rrze-log-list-table',
            plugins_url('build/admin.css', plugin()->getBasename()),
            [],
            $assetFile['version'] ?? plugin()->getVersion()
        );

        wp_register_script(
            'rrze-log-list-table',
            plugins_url('build/admin.js', plugin()->getBasename()),
            $assetFile['dependencies'],
            $assetFile['version'] ?? plugin()->getVersion()
        );
    }
}
