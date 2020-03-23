RRZE Log
========

WordPress-Plugin
----------------

Das Plugin erlaubt es, bestimmte Aktionen der Plugins und Themes in einer Logdatei zu protokollieren, die für weitere Untersuchungen notwendig sind oder sein können.

### Einstellungsmenü (Multisite)

```
Netzwerkverwaltung / Einstellungen / Protokoll
```

### Beschreibung

Die Protokollierung erfolgt über die WP-Funktion do_action().
```
do_action( string $logHook, mixed $message [, array $context] )
```
### Parameter-Liste

**$logHook**

Vorhandene Protokollierungs-Hooks:
- 'rrze.log.error'
- 'rrze.log.warning'
- 'rrze.log.notice'
- 'rrze.log.info'

**$message**

Es kann ein Text oder eine Array sein. Wenn es sich um ein Array handelt, wird der Parameter $context ignoriert.

**$context**

Es ist ein Array, das mit dem Wert (String) des Parameters $message interpolieren kann.

### Beispiele

```
// Nur ein Text ohne Verwendung des Parameters $context
do_action('rrze.log.info', 'Alles funktioniert perfekt.');

// Der Wert des Parameters $message ist ein Array und
// der Parameter $context wird ignoriert
do_action('rrze.log.error', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);

// Der Wert des Parameters $message ist Klartext
do_action('rrze.log.error', 'Ein WP-Fehler ist aufgetreten.', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);

// Der Wert des Parameters $message ist eine formatierte Zeichenfolge,
// die mit dem Array $context interpoliert werden kann.
do_action('rrze.log.error', 'Plugin: {plugin} WP-Fehler: {wp-error}', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);
```

Ein weiterer Anwendungsfall ist die Protokollierung einer Exception, die während der Ausführung von Code ausgelöst wird.

```
try {
    // ...
} catch(\Exception $exception) {

    do_action('rrze.log.warning', ['exception' => $exception]);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        throw $exception;
    }
}
```
### Anmerkungen

- Die Protokolldateien werden im Verzeichnis WP_CONTENT.'/log/rrze-log' abgelegt
- Das Dateinamenformat ist "yyy-mm-dd.log"
- Das Datensatzformat ist JSON
