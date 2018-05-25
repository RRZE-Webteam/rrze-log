RRZE Log
========

WordPress-Plugin
----------------

Ermöglicht die Protokollierung von Plugins und Themes.

Die Protokollierung erfolgt über die WP-Funktion do_action().

### Vorhandene Protokollierungs-Hooks

```
'rrze.log.error'
'rrze.log.warning'
'rrze.log.notice'
'rrze.log.info'
'rrze.log.debug'
```

### Einstellungsmenü (Multisite)

```
Netzwerkverwaltung / Einstellungen / Protokoll
```

### Einstellungsmenü (Single Site)

```
Dashboard / Einstellungen / Protokoll
```

### Beispiele

Ein Beispiel für die Protokollierung mit RRZE Log könnte so aussehen:

```
do_action('rrze.log.error', ['plugin' =>'cms-basis', 'wp-error' => $wp_error]);
```

Ein weiterer häufiger Anwendungsfall ist die Protokollierung einer Exception, die während der Ausführung von Code ausgelöst wird.

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

### Anmerkungen

- Die Protokolldateien werden im Verzeichnis WP_CONTENT / log / rrzelog abgelegt
- Die Protokollierung mittels rrze.log.error, rrze.log.warning und rrze.log.notice werden in derselben Datei gespeichert (error.{YY-MM-DD}.log)
- Die Protokollierung mittels rrze.log.debug ist nur möglich, wenn die Konstante WP_DEBUG auf true gesetzt ist
