<?php

namespace RRZE\Log\REST;

/**
 * StrayOutputSniffer
 *
 * Detects and optionally guards against stray output (e.g., <p></p>)
 * during WordPress REST requests, which can break JSON responses.
 */
class StrayOutputSniffer {
    /** @var bool */
    protected $enabled = true;

    /** @var bool */
    protected $guard = true;

    /** @var int 0 = all sites */
    protected $site = 0;

    /** @var callable */
    protected $logger;

    /** @var int|null */
    protected $obLevelStart = null;

    /** @var string|null */
    protected $lastHook = null;

    /**
     * @param array $args {
     *   @type bool          $enabled
     *   @type bool          $guard
     *   @type int           $site
     *   @type callable|null $logger function(string $message): void
     * }
     */
    public function __construct(array $args = [])  {
        $this->enabled = isset($args['enabled']) ? (bool) $args['enabled'] : true;
        $this->guard   = isset($args['guard'])   ? (bool) $args['guard']   : true;
        $this->site    = isset($args['site'])    ? (int)  $args['site']    : 0;

        $this->logger = isset($args['logger']) && is_callable($args['logger'])
            ? $args['logger']
            : [$this, 'defaultLog'];
    }

    /** Initialize hooks */
    public function boot(): void  {
        if (!$this->enabled) {
            return;
        }
        if (function_exists('get_current_blog_id') && $this->site > 0 && get_current_blog_id() !== $this->site) {
            return;
        }

        // Track last hook when inside REST
        add_action('all', [$this, 'trackHook'], 1);

        // Open an output buffer at REST init; never flush, only inspect
        add_action('rest_api_init', [$this, 'startBuffer'], 0);

        // Optional guard: drop any stray output before serving JSON
        if ($this->guard) {
            add_filter('rest_pre_serve_request', [$this, 'guardPreServeRequest'], 0);
        }

        // Close buffers we opened (clean, no flush)
        add_action('rest_request_after_callbacks', [$this, 'afterCallbacks'], PHP_INT_MAX);
    }

    public function defaultLog(string $message): void {
        $dir  = WP_CONTENT_DIR . '/log';
        $file = $dir . '/rrze-log.log';
        if (!is_dir($dir)) {
            @wp_mkdir_p($dir);
        }
        $ts = gmdate('Y-m-d H:i:s');
        @error_log("[$ts] [rrze-rest-sniffer] $message\n", 3, $file);
    }

    public function trackHook(string $tag): void {
        if ($this->isRest()) {
            $this->lastHook = $tag;
        }
    }

    public function startBuffer(): void {
        if (!$this->isRest()) {
            return;
        }
        $this->obLevelStart = ob_get_level();
        ob_start([$this, 'inspectBuffer']);
    }

    public function guardPreServeRequest($served) {
        if (!$this->isRest()) {
            return $served;
        }
        $this->dropAllBuffers();
        return $served;
    }

    public function afterCallbacks($response) {
        $this->dropAllBuffers();
        return $response;
    }

    /** Output buffer callback: inspect but return unchanged */
    public function inspectBuffer(string $buffer): string  {
        $trim = trim($buffer);

        // Consider "stray output" if it's empty HTML like repeated <p></p>, <br>, empty <div>, etc.
        $looksLikeEmptyHTML = ($trim !== '') && preg_match(
            '#^(?:\s*<(?:p|br|div)(?:\s[^>]*)?>\s*</?(?:p|br|div)>\s*)+$#i',
            $trim
        );

        if ($looksLikeEmptyHTML) {
            $last = $this->lastHook ?: 'n/a';
            $bt   = function_exists('wp_debug_backtrace_summary') ? wp_debug_backtrace_summary() : '';
            $safe = substr(str_replace(["\n", "\r", "\t"], ' ', $trim), 0, 200);
            $this->log(sprintf("NOISE='%s' | last_hook=%s | bt=%s", $safe, $last, $bt));
        }

        // Return buffer intact; guarding (if enabled) happens in rest_pre_serve_request
        return $buffer;
    }

    /** Drop all buffers opened after our start level (clean, never flush) */
    protected function dropAllBuffers(): void  {
        if ($this->obLevelStart === null) {
            // Fallback: drain everything if we didn't record a start level
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            return;
        }
        while (ob_get_level() > $this->obLevelStart) {
            ob_end_clean();
        }
    }

    /** Is this request a REST request? */
    protected function isRest(): bool  {
        return (defined('REST_REQUEST') && REST_REQUEST) && !defined('WP_CLI');
    }

    /** Log via provided logger */
    protected function log(string $message): void  {
        try {
            ($this->logger)($message);
        } catch (\Throwable $e) {
            // Fallback to PHP error_log if custom logger fails
            @error_log('[rrze-rest-sniffer:FALLBACK] ' . $message);
        }
    }
}
