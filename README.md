[![Aktuelle Version](https://img.shields.io/github/package-json/v/rrze-webteam/rrze-log/main?label=Version)](https://github.com/RRZE-Webteam/rrze-log)
[![Release Version](https://img.shields.io/github/v/release/rrze-webteam/rrze-log?label=Release+Version)](https://github.com/rrze-webteam/rrze-log/releases/)
[![GitHub License](https://img.shields.io/github/license/rrze-webteam/rrze-log)](https://github.com/RRZE-Webteam/rrze-log)
[![GitHub issues](https://img.shields.io/github/issues/RRZE-Webteam/rrze-log)](https://github.com/RRZE-Webteam/rrze-log/issues)

# RRZE Log

## WordPress Plugin

This plugin allows certain actions from plugins and themes to be logged into a log file, which may be necessary for further investigation.

### Settings Menu (Multisite)

```
Network Admin / Log
```

### Logging

Logging is done via the WP function `do_action()`.

```php
do_action(string $logHook, mixed $message [, array $context])
```

#### Parameters

**$logHook**

The name of the hook that logs the corresponding error level. Available hooks are:

-   'rrze.log.error'
-   'rrze.log.warning'
-   'rrze.log.notice'
-   'rrze.log.info'

**$message**

Can be a string or an array. If it is an array, the parameter `$context` will be ignored.

**$context**

An array that can be interpolated into the string value of the `$message` parameter.

#### Examples

```php
// $message is a string.
// $context will not be applied.
do_action('rrze.log.info', 'Everything is working perfectly.');

// $message is an array.
// $context will be ignored.
do_action('rrze.log.error', ['plugin' => 'cms-basis', 'wp-error' => $wp_error->get_error_message()]);

// $message is a string.
// $context will be applied.
do_action('rrze.log.error', 'A WP error has occurred.', ['plugin' => 'cms-basis', 'wp-error' => $wp_error->get_error_message()]);

// $message is a formatted string that can be interpolated with the $context array.
do_action(
    'rrze.log.error',
    'Plugin: {plugin} WP Error: {wp-error}',
    ['plugin' => 'cms-basis', 'wp-error' => $wp_error->get_error_message()]
);
```

Another use case is logging an Exception that occurs during code execution.

```php
try {
    // ...
} catch(\Exception $exception) {

    do_action('rrze.log.warning', ['exception' => $exception]);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        throw $exception;
    }
}
```

### Retrieving Logs

Logs of the current day can be retrieved using WP’s `apply_filters()` function.

```php
$logs = apply_filters('rrze.log.get', array $args);
```

#### Arguments

If `$args` is an empty array, all logs of the current day will be retrieved.

**Default arguments array**

```php
$args = [
    'search' => [],
    'limit' => -1,
    'offset' => 0,
];
```

#### Return Value

An array of records containing the logs.

#### Examples

```php
// Get all logs for the current day.
$logs = apply_filters('rrze.log.get', []);

// Get all logs for the current day that contain the search term 'my-plugin'.
$logs = apply_filters('rrze.log.get', ['search' => ['my-plugin']]);

// Search based on a specific key (e.g., "level" and "plugin").
$logs = apply_filters(
    'rrze.log.get',
    ['search' => ['"level":"error"', '"plugin":"my-plugin"']]
);
```

### Notes

-   Log files are stored in the directory <code>WP_CONTENT_DIR . '/log/'</code>
-   The file name format is `rrze-log.log` and `wp-debug.log`
-   The record format is JSON
