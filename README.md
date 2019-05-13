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
do_action('rrze.log.error', ['plugin' =>'cms-basis', 'wp-error' => $wp_error]);
```

Ein weiterer Anwendungsfall ist die Protokollierung einer Exception, die während der Ausführung von Code ausgelöst wird.

```
try {
    // (Code)
} catch(\Exception $exception) {
    // Die Exception protokollieren, indem man sie direkt an RRZE Log übergeben.
    do_action('rrze.log.warning', ['Exception' => $exception]);

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
'rrze.log.debug'
```

### Anmerkungen

- Die Protokolldateien werden im Verzeichnis WP_CONTENT / log / rrzelog abgelegt
- Die Protokollierung mittels rrze.log.error, rrze.log.warning und rrze.log.notice werden in derselben Datei gespeichert (error.{YY-MM-DD}.log)
- Die Protokollierung mittels rrze.log.debug ist nur möglich, wenn die Konstante WP_DEBUG auf true gesetzt ist
