# Changelog

## 2.1.0 – 27.10.2026

Nur der Editor; die Klassen sind unverändert (2.0.0).

- **Keine überlappenden Beschriftungen mehr bei großen Bäumen.** Standard ist jetzt ein waagerechter Baum (Wurzel links, Beschriftung waagerecht, bis 28 Zeichen). Die Zeichenfläche wächst mit dem Baum (24 px je Blatt, 190 px je Ebene); ist sie größer als der Bereich, gibt es Scrollbalken. Zur Sicherheit blendet ECharts sich trotzdem überdeckende Beschriftungen aus (`labelLayout.hideOverlap`).
- Knopf „⇅ Senkrecht“ / „⇄ Waagerecht“ im Kopf; die Wahl merkt sich der Browser.
- Nach dem Laden und beim Umschalten scrollt der Bereich zum Root-Knoten; beim Bearbeiten bleibt die Scrollposition erhalten.
- Zoomen jetzt mit **Strg + Mausrad** oder den Knöpfen „−“, „100 %“, „+“ (in festen 10-%-Schritten von 30 bis 250 %, der Browser merkt sich den Wert). Die Stelle unter der Maus bleibt beim Zoomen stehen. Das Mausrad allein scrollt, Ziehen mit gedrückter linker Maustaste verschiebt den Baum (ein Klick auf einen Knoten wählt ihn weiter aus).
- Der Hinweis „Verschiebe-Modus“ bleibt beim Scrollen oben sichtbar.
- README: Server-Aufbau mit Domain-Ordnern und Symlinks ergänzt.

## 2.0.0 – 27.10.2026

Die beiden Repos `adjacency_list` (Basisklasse) und `adjacency_list_tree_editor` (Editor) sind zu einem zusammengeführt; der Demo-Modus der Online-Version ist jetzt Teil des Repos. **Alle Namen sind neu** (englisch, snake_case, ein Begriff pro Sache). Alias-Methoden für die alten Namen gibt es bewusst nicht.

### Umbenennungen

| 1.x | 2.0 |
|---|---|
| Klasse `ADJACENCY_LIST` | `AdjacencyList` |
| Klasse `KATEGORIE` | `MetaTree` |
| Klasse `DEMO_SESSION` (nur Server) | `DemoSession` (eigenständig, keine Unterklasse mehr) |
| `new ADJACENCY_LIST($host, $db, $user, $pw)` | `new AdjacencyList(AdjacencyList::connect($host, $db, $user, $pw), $table)` |
| `$sql_table` bei jedem Aufruf | einmal im Konstruktor |
| `$meng_num`, `$site_id`, `$link_id` | `$node_id` |
| `$link_name`, `$new_name` | `$name` |
| `get_menge($id, $table, 'secure')` | `get_subtree($node_id, online_only: true)` |
| `get_menge_with_meta()` | `get_subtree_with_meta()` |
| `get_link_info()` (protected, lieferte `[0 => row]`) | `get_node()` (public, liefert die Zeile) |
| `insert_knoten()` → `bool` | `insert_node()` → neue ID |
| `del_knoten()` → `bool` | `delete_node()` → Anzahl gelöschter Knoten |
| `reorder_knoten($id, 'links'/'rechts', $table)` | `reorder_node($node_id, 'up'/'down')` |
| `move_knoten()` | `move_node()` |
| `rename_knoten()` | `rename_node()` |
| – | neu: `set_online()` |
| `get_meta_col_names()` | `get_meta_columns()` |
| – | neu: `add_meta_column()`, `get_meta()`, `purge_orphaned_meta()` |
| `delete_col()` | `delete_meta_column()` |
| Feld `LEVEL` | `level` |
| Feld `KINDER` | `child_count` |
| Feld `KONTROLLE` (`'auf'`/`'zu'`) | `has_children` (bool) |
| Feld `GROESSER` | `has_next_sibling` (bool), neu dazu `has_prev_sibling` |
| Schlüssel `_meta` | `meta` |
| Typen aus der DB als Strings | `id`, `parent_id`, `sort_order`, `level`, `child_count` als int; `online`, `has_*` als bool |
| API `tree` + `root_id` | `get_tree` (liefert Root, Knoten und Meta-Spalten in einem Aufruf) |
| API `insert`, `delete`, `reorder`, `move`, `rename` | `insert_node`, `delete_node`, `reorder_node`, `move_node`, `rename_node` |
| API `meta_cols`, `delete_col` | `get_meta_columns`, `delete_meta_column` |
| API-Parameter `id` | `node_id` |
| – | API neu: `get_status`, `set_online`, `add_meta_column` |
| `config.php` mit `define()` im Webroot | `config.php` gibt ein Array zurück und liegt neben `web/` |

### Fehler behoben

- **Verwaiste Meta-Zeilen:** `del_knoten()` setzte `FOREIGN_KEY_CHECKS = 0` und hebelte damit `ON DELETE CASCADE` aus. Jetzt Löschen in einer Transaktion ohne diesen Schalter. Alte Leichen entfernt einmalig `php bin/cleanup.php --purge-meta` (bzw. `purge_orphaned_meta()`).
- **XSS im Tooltip:** Knotennamen und Meta-Werte wurden unmaskiert als HTML eingesetzt. Jetzt durchgehend maskiert, Meta-Felder werden per DOM statt `innerHTML` gebaut, `escapeHtml()` maskiert auch Anführungszeichen.
- **`move_knoten()` ohne Zielprüfung:** Nicht existierende Ziele wurden akzeptiert, Ziel `0` erzeugte einen zweiten Root (die API schickte bei fehlendem Parameter genau diese 0). Jetzt muss das Ziel existieren, und `new_parent_id` ist Pflicht.
- **Unerreichbarer `catch (PDOException)`** in `api.php` (stand hinter `RuntimeException`). Datenbankfehler gehen jetzt nur noch ins Server-Log, der Client bekommt eine allgemeine Meldung.
- **Vorgemerkte Meta-Spalten gingen verloren**, wenn kein Knoten ausgewählt war. Spalten werden jetzt sofort über `add_meta_column` angelegt.
- **`SHOW TABLES LIKE`** behandelte `_` als Platzhalter. Jetzt exakter Vergleich über `information_schema`.
- **Groß-/Kleinschreibung bei Meta-Spalten:** `Bild` neben `bild` führte zu einem `ALTER`-Fehler. Jetzt wird die vorhandene Spalte erkannt und benutzt.
- **`secure`-Modus inkonsistent:** Kinderzahl und Geschwister-Flags zählten Offline-Knoten mit. Mit `online_only` zählen jetzt alle Felder nur Online-Knoten.
- **Google-Fonts-`@import`** stand hinter `:root` und wurde ignoriert; entfernt, nur noch Systemschriften.
- Demo: Aufräumen schaltete ebenfalls `FOREIGN_KEY_CHECKS` ab (jetzt Meta-Tabelle zuerst löschen); das globale Limit zählte per `LIKE 'demo_%'` auch `demo_sessions` und jede Meta-Tabelle mit (jetzt Zählung über die Registry); der Kommentar „1 von 20“ passte nicht zu `random_int(1, 5)`; jede API-Anfrage ohne Cookie legte eine neue Session samt Tabellen an (jetzt nur `init_session`); IP-Hash ohne Schlüssel (jetzt HMAC); Beispielbaum mit fest angenommenen IDs.

### Sicherheit

- Tabellennamen werden im Konstruktor geprüft (`^[A-Za-z_][A-Za-z0-9_]{0,58}$`).
- API: kein `Access-Control-Allow-Origin: *` mehr; Schreiben nur per POST mit Header `X-Tree-Editor: 1` (CSRF-Schutz); Admin-Modus nur mit angemeldetem Benutzer (Basic Auth), außer bewusst abgeschaltet.
- Demo-Cookie nur noch für den Pfad des Editors, `Secure` nur bei HTTPS (lokal testbar).
- `config.php` außerhalb des Webroots, in `.gitignore`, und ein pre-commit-Hook blockiert Commits mit Zugangsdaten.
- Auslieferung per `git push` statt Hochladen von Hand.

### Sonstiges

- Schreibende Methoden laufen in Transaktionen mit Zeilensperren (keine doppelten `sort_order`-Werte, kein zweiter Root bei parallelen Aufrufen).
- `ensure_table()` prüft pro Instanz nur noch einmal; die Kinderzahl wird nur noch einmal pro Zeile berechnet.
- `get_root_id()` mit `ORDER BY id`.
- `update_meta()` prüft, ob der Knoten existiert, und nimmt nur skalare Werte an.
- `IN (…)` durchgehend mit Platzhaltern.
- `reorder_knoten()` schluckte `PDOException` – jetzt werfen alle Methoden einheitlich.
- PHP-Mindestversion korrekt mit 8.1 angegeben (1.x nutzte den Typ `true` aus PHP 8.2 und `never` aus 8.1, das README nannte 8.0).
- Frontend: Root-ID-Feld entfernt (war wirkungslos), Hoch/Runter am Rand deaktiviert, Sichtbarkeit schaltbar, Offline-Knoten gestrichelt, Inline-Styles und -Handler in `style.css`/`app.js` verschoben, ungenutzte `<datalist>` und überzähliges `</span>` im Footer entfernt, `--hover` definiert, Layout mit Footer im Grid statt `position: fixed`.
- Einrückung mit Tabs, LF-Zeilenenden (`.gitattributes`).
- Neu: `tests/smoke_test.php`, `bin/cleanup.php`, `bin/deploy.sh`, `bin/post-receive`.

## 1.0 – 2026

Erste Fassung als `ADJACENCY_LIST` und `KATEGORIE` in zwei getrennten Repos.
