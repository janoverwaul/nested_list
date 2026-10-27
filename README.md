# nested_list – Baumstrukturen in MySQL/MariaDB

PHP-Klassen für hierarchische Daten (Kategorien, Menüs, Ordner …) nach dem **Adjacency-List-Modell** mit rekursiven CTEs, dazu ein **Web-Editor** mit ECharts.

**Klassen 2.0.0 · Editor 2.1.0** · Jan Overwaul · MIT-Lizenz · [Online-Demo](https://playground.jan-overwaul.de/nested_list/)

| Teil | Datei | Zweck |
|---|---|---|
| `AdjacencyList` | `src/AdjacencyList.php` | Basisklasse: Baum lesen, einfügen, löschen, sortieren, verschieben |
| `MetaTree` | `src/MetaTree.php` | erweitert `AdjacencyList` um frei definierbare Zusatzfelder je Knoten |
| `DemoSession` | `src/DemoSession.php` | eigene, befristete Demo-Tabelle pro Besucher (nur für die Online-Demo) |
| Tree Editor | `web/` | Oberfläche (`index.html`, `app.js`, `style.css`) und JSON-API (`api.php`) |

> Umstieg von Version 1.x (`ADJACENCY_LIST`, `KATEGORIE`, `get_menge()` …): siehe [CHANGELOG.md](CHANGELOG.md). Alle Namen haben sich geändert, Alias-Methoden gibt es bewusst nicht.

---

## Warum Adjacency List?

Jeder Knoten speichert nur seinen Elternknoten (`parent_id`). Dadurch sind **Einfügen, Verschieben und Löschen ohne Massenupdate** möglich: Es ändern sich nur die betroffenen Zeilen, nicht wie beim Nested-Set-Modell die `lft`/`rgt`-Werte des halben Baums. Ganze Teilbäume liest eine einzige Abfrage mit `WITH RECURSIVE`.

Die Idee und die ursprüngliche Benennung stammen von der Klasse `NESTEDSET` von Oliver Blum (Blumithek, Version 0.9, 2007). Die Technik ist komplett neu; das Verschieben (`move_node`), das dort „coming soon“ war, ist umgesetzt.

**Regel:** Pro Tabelle gibt es genau **einen Root-Knoten** (`parent_id = 0`). Parallele Bäume sind nicht erlaubt.

---

## Voraussetzungen

- PHP **8.1** oder neuer mit PDO (`pdo_mysql`) und `mbstring`
- MySQL **8.0+** oder MariaDB **10.2+** (wegen `WITH RECURSIVE`)
- Tabellen mit InnoDB (für Transaktionen und den Fremdschlüssel der Meta-Tabelle)

Getestet mit PHP 8.3, MariaDB 10.11 und MySQL 8.0.

---

## Aufbau des Repos

```
nested_list/
├── config.example.php     Vorlage für config.php (ohne Zugangsdaten)
├── config.php             echte Zugangsdaten – von Git ignoriert, nicht im Webroot
├── src/                   Klassen (kein Webzugriff)
├── web/                   Document Root des Editors
├── bin/                   cleanup.php (Cron), deploy.sh, post-receive (Vorlage)
├── tests/smoke_test.php   Test gegen eine echte Datenbank
└── .githooks/pre-commit   blockiert Commits mit Zugangsdaten
```

Nur `web/` ist im Browser erreichbar. `config.php` liegt eine Ebene darüber und kann deshalb nie über eine URL abgerufen werden.

---

## Schnellstart (nur die Klassen)

```php
require_once 'src/MetaTree.php';      // lädt AdjacencyList und TreeException mit

$config = require 'config.php';
$db     = $config['db'];
$pdo    = AdjacencyList::connect($db['host'], $db['name'], $db['user'], $db['password']);

$tree = new MetaTree($pdo, 'kategorien');   // Tabelle wird bei Bedarf angelegt

$root    = $tree->insert_node('Elektronik');              // Root
$phones  = $tree->insert_node('Smartphones', $root);
$laptops = $tree->insert_node('Laptops', $root);
$tree->insert_node('iPhone', $phones);

$tree->reorder_node($laptops, 'up');          // Laptops vor Smartphones
$tree->update_meta($phones, ['icon' => 'phone.svg']);

foreach ($tree->get_subtree($root) as $node) {
	echo str_repeat('  ', $node['level']) . $node['name']
		. ' (' . $node['child_count'] . " Kinder)\n";
}
```

Wer keine Zusatzfelder braucht, nimmt `AdjacencyList` statt `MetaTree` – die Methoden sind dieselben, nur ohne `*_meta*`.

---

## Tabellen

### Baumtabelle

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | INT UNSIGNED, PK, AUTO_INCREMENT | Knoten-ID |
| `name` | VARCHAR(255) | Bezeichnung |
| `parent_id` | INT UNSIGNED | Elternknoten, `0` = Root |
| `sort_order` | INT UNSIGNED | Reihenfolge unter Geschwistern |
| `online` | TINYINT(1) | Sichtbarkeit, `1` = online |

Fehlt die Tabelle, wird sie beim ersten Zugriff angelegt (mit Index auf `parent_id, sort_order`). Gibt es sie schon, werden nur die fünf Pflichtspalten geprüft; **eigene Zusatzspalten sind erlaubt** und werden in `get_subtree()` mit ausgegeben.

Tabellennamen: Buchstaben, Ziffern und `_`, höchstens 59 Zeichen (damit `<name>_meta` unter dem MySQL-Limit von 64 bleibt). Andere Namen lehnt schon der Konstruktor ab.

### Meta-Tabelle (`MetaTree`)

`<tabelle>_meta` mit `id`, `node_id` (UNIQUE, Fremdschlüssel auf die Baumtabelle mit `ON DELETE CASCADE`) und beliebig vielen `TEXT`-Spalten. Neue Spalten entstehen per `ALTER TABLE … ADD COLUMN`. Groß- und Kleinschreibung zählt bei Spaltennamen wie in MySQL nicht: `Bild` und `bild` sind dieselbe Spalte.

---

## AdjacencyList

```php
new AdjacencyList(PDO $pdo, string $table)
AdjacencyList::connect(string $host, string $dbname, string $user, string $password, string $charset = 'utf8mb4'): PDO
```

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `get_root_id()` | `?int` | ID des Root-Knotens, `null` bei leerer Tabelle |
| `get_node(int $node_id)` | `array` | eine Zeile |
| `get_subtree(int $node_id, bool $online_only = false)` | `array` | Knoten samt aller Nachfahren, flach, sortiert nach `level`, `parent_id`, `sort_order` |
| `insert_node(string $name, ?int $parent_id = null)` | `int` | neue ID; ohne `$parent_id` wird der Root angelegt |
| `delete_node(int $node_id)` | `int` | löscht den Teilbaum, gibt die Anzahl gelöschter Knoten zurück |
| `reorder_node(int $node_id, string $direction)` | `void` | `'up'` oder `'down'`: Platz mit dem Nachbar-Geschwister tauschen |
| `move_node(int $node_id, int $new_parent_id)` | `void` | Teilbaum als letztes Kind unter einen anderen Knoten hängen |
| `rename_node(int $node_id, string $name)` | `void` | umbenennen (Name wird getrimmt, max. 255 Zeichen) |
| `set_online(int $node_id, bool $online)` | `void` | Sichtbarkeit schalten |
| `get_table()` | `string` | Tabellenname |

Zusatzfelder jedes Knotens aus `get_subtree()`:

| Feld | Typ | Bedeutung |
|---|---|---|
| `level` | int | Tiefe relativ zum Startknoten (0) |
| `child_count` | int | Anzahl direkter Kinder |
| `has_children` | bool | `child_count > 0` |
| `has_prev_sibling` | bool | es gibt ein Geschwister davor (→ „Hoch“ möglich) |
| `has_next_sibling` | bool | es gibt ein Geschwister dahinter (→ „Runter“ möglich) |

Mit `$online_only = true` fehlen Offline-Knoten **samt ihrem Teilbaum**, und alle Zusatzfelder zählen nur Online-Knoten. Ist der Startknoten offline, ist das Ergebnis leer.

Alle schreibenden Methoden laufen in einer Transaktion und sperren die betroffenen Zeilen (`SELECT … FOR UPDATE`), damit parallele Aufrufe keine doppelten `sort_order`-Werte oder einen zweiten Root erzeugen.

**Prüfungen:** Es kann nur ein Root angelegt werden; der Elternknoten bzw. das Verschiebe-Ziel muss existieren; ein Knoten kann nicht unter sich selbst oder einen eigenen Nachfahren verschoben werden; `move_node(…, 0)` (zweiter Root) ist nicht möglich.

---

## MetaTree

Erweitert `AdjacencyList` um:

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `get_subtree_with_meta(int $node_id, bool $online_only = false)` | `array` | wie `get_subtree()`, jeder Knoten zusätzlich mit `'meta' => ['spalte' => wert\|null, …]` |
| `get_meta(int $node_id)` | `array` | Meta-Werte eines Knotens |
| `update_meta(int $node_id, array $data)` | `void` | speichert Werte, legt unbekannte Spalten an. Nur Text, Zahlen, bool und `null` erlaubt |
| `get_meta_columns()` | `string[]` | alle Meta-Spalten ohne `id`/`node_id` |
| `add_meta_column(string $column)` | `string` | legt eine Spalte an; gibt den echten Namen zurück (bei `bild` z. B. das vorhandene `Bild`) |
| `delete_meta_column(string $column)` | `void` | löscht die Spalte samt aller Werte |
| `purge_orphaned_meta()` | `int` | entfernt Meta-Zeilen ohne Knoten (Altlast aus 1.x, siehe CHANGELOG) |

Spaltennamen: Buchstaben, Ziffern und `_`, nicht mit einer Ziffer beginnend, max. 64 Zeichen. `id` und `node_id` sind gesperrt.

---

## Fehlerbehandlung

| Exception | Wann | Meldung anzeigen? |
|---|---|---|
| `InvalidArgumentException` | ungültige Eingabe: Tabellen-/Spaltenname, ID ≤ 0, leerer Name, falsche Richtung | ja |
| `TreeException` (erbt von `RuntimeException`) | fachlicher Fehler: Knoten fehlt, Root existiert schon, Ziel im eigenen Teilbaum, Demo-Limit | ja |
| `PDOException` | Datenbankfehler | **nein** – kann interne Details enthalten, nur ins Log |

```php
try {
	$tree->move_node(7, 2);
} catch (InvalidArgumentException | TreeException $e) {
	echo 'Geht nicht: ' . $e->getMessage();
} catch (PDOException $e) {
	error_log($e->getMessage());
	echo 'Datenbankfehler.';
}
```

---

## Tree Editor

Der Editor zeigt den Baum mit ECharts (vom eigenen CDN `cdn.jan-overwaul.de`). Standard ist ein **waagerechter** Baum (Wurzel links); der Knopf „⇅ Senkrecht“ / „⇄ Waagerecht“ oben schaltet um, der Browser merkt sich die Wahl. Die Zeichenfläche wächst mit dem Baum: Jedes Blatt bekommt eine feste Zeile, große Bäume bekommen Scrollbalken statt überlappender Beschriftungen. Zoomen geht mit **Strg + Mausrad** oder den Knöpfen „−“, „100 %“, „+“; das Mausrad allein scrollt, Ziehen mit gedrückter linker Maustaste verschiebt den Baum. Weit herausgezoomt passt auch ein großer Baum ganz ins Bild (zu kleine Beschriftungen blendet ECharts dann aus, der Tooltip zeigt sie weiter). Knoten anklicken → rechts Name, Sichtbarkeit und Meta-Felder bearbeiten; „Verschieben …“ und dann den neuen Elternknoten im Baum anklicken; „Hoch“/„Runter“ sind nur aktiv, wenn es in der Richtung ein Geschwister gibt. Offline-Knoten und ihr Teilbaum erscheinen gestrichelt.

### Einrichten

1. `config.example.php` nach `config.php` kopieren (im Projektordner, **neben** `web/`) und Zugangsdaten eintragen.
2. Modus wählen:
   - `'mode' => 'demo'` – jeder Besucher bekommt eine eigene Tabelle mit Beispielbaum (siehe unten).
   - `'mode' => 'admin'` – der Editor bearbeitet direkt die Tabelle aus `'table'`.
3. Document Root (oder einen Symlink) auf `web/` zeigen lassen.

Lokal reicht der eingebaute PHP-Server:

```
php -S localhost:8080 -t web
```

### Admin-Modus absichern

Die API hat keine eigene Benutzerverwaltung. Im Admin-Modus verlangt sie deshalb einen vom Webserver angemeldeten Benutzer und verweigert sonst den Dienst.

Am einfachsten über einen Verzeichnisschutz des Hosting-Panels (z. B. KeyHelp) für die ganze Domain oder per Basic Auth in `web/.htaccess` (liegt nicht im Repo):

```apache
AuthType Basic
AuthName "Tree Editor"
AuthUserFile /pfad/ausserhalb/des/webroots/.htpasswd_nested_list
Require valid-user
```

Nur wenn der Editor anders geschützt ist (z. B. lokal auf dem eigenen Rechner), `'require_auth' => false` setzen.

### API

`web/api.php` antwortet immer mit JSON:

```json
{"success": true, "data": …, "error": ""}
```

Schreibende Aktionen gehen **nur per POST** und nur mit dem Header `X-Tree-Editor: 1`. Ein fremder Origin kann diesen Header nur nach einem CORS-Preflight senden, den die API nicht erlaubt – das verhindert Cross-Site-Request-Forgery. Fehler kommen mit passendem HTTP-Status (401 Session abgelaufen, 403, 405, 422 fachlicher Fehler, 500 Datenbank).

| Aktion | Parameter | `data` |
|---|---|---|
| `get_status` | – | `{mode, session, expires_at, ttl_hours}` |
| `init_session` | – | `{mode, is_new, expires_at}` – startet bzw. setzt die Demo-Session fort |
| `get_tree` | `node_id` (optional, Standard: Root) | `{root_id, nodes, meta_columns}` |
| `get_meta_columns` | – | `["spalte", …]` |
| `insert_node` | `name`, `parent_id` (leer = Root) | `{id}` |
| `delete_node` | `node_id` | `{deleted}` |
| `reorder_node` | `node_id`, `direction` (`up`/`down`) | – |
| `move_node` | `node_id`, `new_parent_id` | – |
| `rename_node` | `node_id`, `name` | – |
| `set_online` | `node_id`, `online` (`0`/`1`) | – |
| `update_meta` | `node_id`, `data` (JSON-Objekt) | – |
| `add_meta_column` | `column` | `{column}` (tatsächlicher Name) |
| `delete_meta_column` | `column` | – |

### Demo-Modus

So läuft die Online-Demo:

- Beim ersten Besuch erklärt ein Dialog den Cookie `demo_token` (technisch notwendig, `HttpOnly`, `SameSite=Strict`, nur für den Pfad des Editors). Erst nach „Verstanden & starten“ legt `init_session` die Session an.
- Jede Session bekommt die Tabellen `demo_<token>` und `demo_<token>_meta` mit einem Beispielbaum. Die Registry `demo_sessions` speichert Token, Startzeit und einen **HMAC der IP** (mit `ip_secret` aus der Config, damit sich IPv4-Adressen nicht zurückrechnen lassen).
- Nach `ttl_hours` (Standard 2) ist die Session abgelaufen. Der Editor bekommt dann HTTP 401 und startet automatisch eine neue.
- Limits (alle in der Config): aktive Sessions gesamt, neue Sessions pro IP und Stunde, Knoten, Meta-Spalten und Zeichen pro Meta-Wert.
- **Aufräumen:** `bin/cleanup.php` per Cron löscht abgelaufene Sessions und verwaiste Demo-Tabellen. Zusätzlich räumt die API bei etwa jeder 20. Anfrage nebenbei auf, falls der Cron einmal nicht läuft.

```
*/15 * * * * /pfad/zu/php /pfad/zur/arbeitskopie/bin/cleanup.php --quiet
```

---

## Zugangsdaten: warum sie nicht mehr ins Repo gelangen

1. **Nur eine Vorlage im Repo:** `config.example.php` enthält leere Felder. Die echte `config.php` steht in `.gitignore`.
2. **Außerhalb des Webroots:** `config.php` liegt neben `web/`, nicht darin.
3. **pre-commit-Hook:** `.githooks/pre-commit` bricht jeden Commit ab, der eine `config.php` oder `.env` enthält oder einem Passwort-Schlüssel einen Wert zuweist. Einmal pro Klon aktivieren:

   ```
   git config core.hooksPath .githooks
   ```

4. **Kein Hochladen von Hand:** Der Server bekommt den Code per `git push` (nächster Abschnitt); `config.php` liegt dort einmalig und wird beim Ausliefern nicht angefasst.

---

## Auslieferung per git push

Es gibt **einen Code-Stand** und mehrere Installationen, z. B. eine private Test-Installation (Admin-Modus) und eine öffentliche Demo. Neue Versionen gehen zuerst auf die Test-Installation, nach dem Test **derselbe Commit** auf die Demo. GitHub ist `origin`.

Je Installation gibt es auf dem Server:

| Was | Beispiel | Bedeutung |
|---|---|---|
| Bare-Repo | `<home>/repos/nested_list-test.git` | Ziel von `git push`, enthält den Hook `post-receive` |
| Arbeitskopie | `<home>/www/nested_list-test` | ausgecheckter Code und die eigene `config.php` |
| Symlink | `<domain-ordner>/nested_list` → `<home>/www/nested_list-test/web` | macht `web/` unter `https://<domain>/nested_list/` erreichbar |

Jede Installation hat ihre eigene `config.php` (eigene Datenbank, eigener Modus).

**SSH-chroot:** Bei manchen Hostern (z. B. KeyHelp) läuft der Hook beim Push in einer Umgebung, in der das Home-Verzeichnis die Wurzel ist. `post-receive` nutzt deshalb einen **relativen** Pfad zur Arbeitskopie (`../../www/nested_list-test`). Dort gibt es oft kein PHP und kein `ln`; der Hook checkt nur aus und prüft, ob `config.php` da ist. Die PHP-Syntax (`php -l`) und JavaScript (`node --check`) werden deshalb vor dem Commit geprüft.

Einrichtung je Installation (einmalig, auf dem Server):

```bash
# 1. Bare-Repo und Arbeitskopie anlegen
git init --bare <home>/repos/nested_list-test.git
git --git-dir=<home>/repos/nested_list-test.git symbolic-ref HEAD refs/heads/main
mkdir -p <home>/www/nested_list-test

# 2. bin/post-receive nach <home>/repos/nested_list-test.git/hooks/ kopieren,
#    APP_DIR darin anpassen, ausführbar machen
chmod +x <home>/repos/nested_list-test.git/hooks/post-receive

# 3. config.example.php nach <home>/www/nested_list-test/config.php kopieren und ausfüllen

# 4. Symlink im Domain-Ordner anlegen (siehe unten)
```

Auf dem PC:

```bash
git remote add test ssh://<user>@<ssh-host>/repos/nested_list-test.git
git push test main
```

**Der Symlink** ist die Verbindung zwischen Adresse und Code: Der Webserver liefert den Domain-Ordner aus, darin zeigt der Link `nested_list` auf `web/` der Arbeitskopie.

```bash
cd <domain-ordner>
ln -s <home>/www/nested_list-test/web nested_list
chown -h <user>:<user> nested_list    # Link muss demselben Benutzer gehören wie das Ziel
ls -la | grep nested                  # zeigt: nested_list -> <home>/www/nested_list-test/web
```

Apache muss Symlinks folgen dürfen (`Options +SymLinksIfOwnerMatch`). Den Link nie durch einen echten Ordner ersetzen – sonst landen Pushes nicht mehr auf der Seite.

### Ablauf einer Änderung

1. Ändern, prüfen (`node --check web/app.js`, `php -l`).
2. Committen und auf die Test-Installation pushen → in der Push-Ausgabe müssen beide Prüfungen `ok` sein.
3. Testen – einmal **Strg+F5**, damit der Browser die neue `app.js` lädt.
4. Passt es: **derselbe Commit** auf die öffentliche Installation und nach `origin`.

---

## Tests

```
php tests/smoke_test.php
```

Der Test nutzt die Zugangsdaten aus `config.php`, legt Tabellen mit zufälligem Namen (`zz_test_…`) an, prüft alle Methoden einschließlich Demo-Session, Limits und Aufräumen und löscht die Tabellen am Ende wieder (die Registry-Tabelle `demo_sessions` bleibt stehen). Eine andere Config geht per Umgebungsvariable `NESTED_LIST_CONFIG=/pfad/config.php`.

Auf dem Server als Benutzer der Installation:

```
/pfad/zu/php <home>/www/nested_list-test/tests/smoke_test.php
```

---

## Lizenz

MIT – siehe [LICENSE](LICENSE).
