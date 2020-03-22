RRZE Log
========

WordPress-Plugin
----------------

Das Plugin erlaubt es, bestimmte Aktionen der Plugins und Themes in einer Logdatei zu protokollieren, die für weitere Untersuchungen notwendig sind oder sein können.

- Die Protokollierung erfolgt über die WP-Funktion do_action()
- Auch zum Debuggen geeignet

### Einstellungsmenü (Multisite)

```
Netzwerkverwaltung / Einstellungen / Protokoll
```

### Einstellungsmenü (Single Site)

```
Dashboard / Einstellungen / Protokoll
```

### Beispiele

Der Code, der in einem Plugin hinzugefügt wird, um die Protokollierung zu aktivieren, könnte folgendes sein:

```
// Die Ausgabe verwendet das Standardformat {$value1 $value2 ...})
do_action('rrze.log.error', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);

// Die Ausgabe ist nicht formatiert
do_action('rrze.log.error', 'Ein WP-Fehler ist aufgetreten.', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);

// Mit eine formatierten Ausgabe
do_action('rrze.log.error', 'Plugin: {plugin} WP-Fehler: {wp-error}', ['plugin' =>'cms-basis', 'wp-error' => $wp_error->get_error_message()]);
```

Ein weiterer Anwendungsfall ist die Protokollierung einer Exception, die während der Ausführung von Code ausgelöst wird.

```
try {
    // (Code)
} catch(\Exception $exception) {
    // Die Exception protokollieren, indem man sie direkt an RRZE Log übergeben.
    do_action('rrze.log.warning', ['exception' => $exception]);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        throw $exception;
    }
}
```

### Vorhandene Protokollierungs-Hooks

```
'rrze.log.error'
'rrze.log.warning'
'rrze.log.notice'
'rrze.log.info'
```

### Anmerkungen

- Die Protokolldateien werden im Verzeichnis WP_CONTENT.'/log/rrze-log' abgelegt
- Das Dateinamenformat ist "yyy-mm-dd.log"
- Das Datensatzformat ist JSON
