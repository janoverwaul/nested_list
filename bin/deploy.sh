#!/bin/sh
# +---------------------------------------------------------------------+
# | deploy.sh – Prüfung nach dem Checkout auf dem Server                |
# +---------------------------------------------------------------------+
# | Wird vom post-receive-Hook aufgerufen. Läuft im SSH-chroot von      |
# | KeyHelp und nutzt deshalb nur Shell-Bordmittel (kein PHP, kein ln). |
# | Die PHP-Syntax wird VOR dem Push lokal geprüft (siehe README).      |
# |                                                                     |
# | Prüft:                                                              |
# |  1. config.php vorhanden (liegt nur auf dem Server, nie im Repo)    |
# |  2. web/ vorhanden                                                  |
# | Bricht nie ab – der Code ist ja schon ausgecheckt. Probleme werden  |
# | in der Push-Ausgabe als „!“ gemeldet.                               |
# +---------------------------------------------------------------------+

APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"

echo "nested_list: Prüfung in $APP_DIR"

if [ -f "$APP_DIR/config.php" ]; then
	echo "  ok  config.php vorhanden"
else
	echo "  !   config.php fehlt – die Seite zeigt nur eine Fehlermeldung."
	echo "      Kopiere config.example.php nach $APP_DIR/config.php und trag die Zugangsdaten ein."
fi

if [ -f "$APP_DIR/web/api.php" ]; then
	echo "  ok  web/api.php vorhanden"
else
	echo "  !   web/api.php fehlt – Checkout prüfen."
fi

echo "nested_list: fertig."
