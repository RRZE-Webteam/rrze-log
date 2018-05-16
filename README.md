RRZE Log
========

WordPress-Plugin
----------------

Ermöglicht die Protokollierung von Plugins und Themes.

Die Protokollierung erfolgt über die WP-Funktion do_action().

Einstellungsmenü (Multisite):

``
Netzwerkverwaltung / Einstellungen / Protokoll
```

Einstellungsmenü (Single Site):

```
Dashboard / Einstellungen / Protokoll
```

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

Protokollierungs-Hooks (in absteigender Reihenfolge des Schweregrades)

```
'rrze.log.error'
'rrze.log.warning'
'rrze.log.notice'
'rrze.log.info'
'rrze.log.debug'
```
