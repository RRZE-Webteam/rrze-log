RRZE Log
========

WordPress-Plugin
----------------

Das Plugin erlaubt es, bestimmte Aktionen der Plugins und Themes in einer Logdatei zu protokollieren, die für weitere Untersuchungen notwendig sind oder sein können. Das Plugin funktioniert nur in einer WP-Multisite-Installation.

### Einstellungsmenü (Multisite)

```
Netzwerkverwaltung / Protokoll
```

### Protokollierung

Die Protokollierung erfolgt über die WP-Funktion `do_action()`.
```php
do_action(string $logHook, mixed $message [, array $context])
```
#### Parameter

**$logHook**

Der Name des Hooks, der die entsprechende Fehlerstufe protokolliert. Vorhandene Hooks sind:
- 'rrze.log.error'
- 'rrze.log.warning'
- 'rrze.log.notice'
- 'rrze.log.info'

**$message**

Es kann ein Text oder eine Array sein. Wenn es sich um ein Array handelt, wird der Parameter $context ignoriert.

**$context**

Es ist ein Array, das mit dem Wert (String) des Parameters $message interpolieren kann.

#### Beispiele

```php
// Der Wert des Parameters $message ist ein Text.
// Der Wert des Parameters $context wird nicht eingegeben.
do_action('rrze.log.info', 'Alles funktioniert perfekt.');

// Der Wert des Parameters $message ist ein Array.
// Der Wert des Parameters $context wird ignoriert.
do_action('rrze.log.error', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);

// Der Wert des Parameters $message ist ein Text.
// Der Wert des Parameters $context wird eingegeben.
do_action('rrze.log.error', 'Ein WP-Fehler ist aufgetreten.', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);

// Der Wert des Parameters $message ist eine formatierte Zeichenfolge,
// die mit dem Array $context interpoliert werden kann.
do_action('rrze.log.error', 'Plugin: {plugin} WP-Fehler: {wp-error}', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);
```

Ein weiterer Anwendungsfall ist die Protokollierung einer Exception, die während der Ausführung von Code ausgelöst wird.

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

### Protokollabfrage

Die Protokolle des aktuellen Tages können mit der Funktion `apply_filters()` von WP abgerufen werden.

```php
$logs = apply_filters('rrze.log.get', array $args);
```

#### Argumente

Wenn `$arg` ein leeres Array ist, werden alle Protokolle für den aktuellen Tag abgerufen.

#### Rückgabewert

Ein Array von Datensätzen, die die Protokolle enthalten.

**Standardargumente-Array**
```php
$args = [
    'search' => [],
    'limit' => -1,
    'offset' => 0,
];
```

#### Beispiele
```php
// Alle Protokolle für den aktuellen Tag abrufen.
$logs = apply_filters('rrze.log.get', []);

// Alle Protokolle für den aktuellen Tag abrufen, die den Suchbegriff 'mein-plugin' enthalten.
$logs = apply_filters('rrze.log.get', ['search' => ['mein-plugin']]);

// Suche basierend auf einem bestimmten Schlüssel (bspw. "level" und "plugin").
$logs = apply_filters('rrze.log.get', ['search' => ['"level":"error"', '"plugin":"mein-plugin"']]);
```

### Anmerkungen

- Die Protokolldateien werden im Verzeichnis <code>WP_CONTENT_DIR . '/log/rrze-log'</code> abgelegt
- Das Dateinamenformat ist "yyy-mm-dd.log"
- Das Datensatzformat ist JSON
