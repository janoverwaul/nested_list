<?php
// +---------------------------------------------------------------------+
// | api.php – JSON-Backend des Tree Editors                             |
// +---------------------------------------------------------------------+
// | Antwort immer: {"success": bool, "data": mixed, "error": string}    |
// |                                                                     |
// | Lesen:     get_status, get_tree, get_meta_columns  (GET oder POST)  |
// | Schreiben: init_session, insert_node, delete_node, reorder_node,    |
// |            move_node, rename_node, set_online, update_meta,         |
// |            add_meta_column, delete_meta_column     (nur POST)       |
// |                                                                     |
// | Schreibende Aufrufe brauchen den Header "X-Tree-Editor: 1". Ein     |
// | fremder Origin kann ihn nur nach CORS-Preflight senden, den diese   |
// | API nicht erlaubt – das schützt vor CSRF.                           |
// +---------------------------------------------------------------------+

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(bool $success, mixed $data = null, string $error = '', int $status = 200): never {
	ob_end_clean();
	http_response_code($status);
	echo json_encode(
		['success' => $success, 'data' => $data, 'error' => $error],
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	exit;
}

/** Ganzzahliger Parameter; fehlt er, gibt es eine Fehlermeldung. */
function param_int(string $key): int {
	$value = $_POST[$key] ?? $_GET[$key] ?? null;
	if ($value === null || $value === '' || !preg_match('/^\d+$/', (string)$value)) {
		throw new InvalidArgumentException("Parameter „{$key}“ fehlt oder ist keine positive Zahl.");
	}
	return (int)$value;
}

/** Ganzzahliger Parameter; leer oder fehlend ergibt null. */
function param_int_or_null(string $key): ?int {
	$value = $_POST[$key] ?? $_GET[$key] ?? '';
	return $value === '' ? null : param_int($key);
}

function param_string(string $key): string {
	$value = $_POST[$key] ?? $_GET[$key] ?? '';
	if (!is_string($value)) {
		throw new InvalidArgumentException("Parameter „{$key}“ ist ungültig.");
	}
	return trim($value);
}

// ── Konfiguration ───────────────────────────────────────────────────────
// Die echte config.php liegt AUSSERHALB des Webroots (eine Ebene über web/)
// und wird von Git ignoriert. Vorlage: config.example.php
$config_file = getenv('NESTED_LIST_CONFIG') ?: dirname(__DIR__) . '/config.php';
if (!is_file($config_file)) {
	respond(false, null, 'config.php fehlt. Kopiere config.example.php nach config.php (neben den Ordner web/) und trag die Zugangsdaten ein.', 500);
}
$config = require $config_file;
if (!is_array($config)) {
	respond(false, null, 'config.php muss ein Array zurückgeben (siehe config.example.php).', 500);
}

require_once dirname(__DIR__) . '/src/MetaTree.php';
require_once dirname(__DIR__) . '/src/DemoSession.php';

$mode = ($config['mode'] ?? 'demo') === 'admin' ? 'admin' : 'demo';

// ── Aktion und Anfrageart prüfen ────────────────────────────────────────
const READ_ACTIONS  = ['get_status', 'get_tree', 'get_meta_columns'];
const WRITE_ACTIONS = [
	'init_session', 'insert_node', 'delete_node', 'reorder_node', 'move_node',
	'rename_node', 'set_online', 'update_meta', 'add_meta_column', 'delete_meta_column',
];

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = is_string($action) ? $action : '';
if (!in_array($action, [...READ_ACTIONS, ...WRITE_ACTIONS], true)) {
	respond(false, null, "Unbekannte Aktion „{$action}“.", 400);
}
if (in_array($action, WRITE_ACTIONS, true)) {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		respond(false, null, 'Schreibende Aktionen nur per POST.', 405);
	}
	if (($_SERVER['HTTP_X_TREE_EDITOR'] ?? '') !== '1') {
		respond(false, null, 'Header X-Tree-Editor fehlt.', 403);
	}
}

// Im Admin-Modus muss der Webserver den Zugriff schützen (z. B. Basic Auth
// per .htaccess). Ohne angemeldeten Benutzer verweigert die API den Dienst,
// außer require_auth ist in der Config bewusst abgeschaltet.
if ($mode === 'admin' && ($config['require_auth'] ?? true)
	&& empty($_SERVER['REMOTE_USER']) && empty($_SERVER['PHP_AUTH_USER'])
	&& empty($_SERVER['REDIRECT_REMOTE_USER'])) {
	respond(false, null, 'Admin-Modus ohne Anmeldung. Schütz das Verzeichnis per Basic Auth oder setz require_auth in der Config auf false.', 403);
}

try {
	$db  = $config['db'] ?? [];
	$pdo = AdjacencyList::connect(
		(string)($db['host'] ?? 'localhost'),
		(string)($db['name'] ?? ''),
		(string)($db['user'] ?? ''),
		(string)($db['password'] ?? ''),
		(string)($db['charset'] ?? 'utf8mb4')
	);

	// ── Baum-Instanz: eigene Tabelle (Admin) oder Demo-Tabelle (Demo) ─────
	$demo    = null;
	$session = null;

	if ($mode === 'demo') {
		$demo = new DemoSession($pdo, $config['demo'] ?? []);

		// Nebenbei aufräumen (etwa jede 20. Anfrage), falls kein Cron läuft
		if (random_int(1, 20) === 1) {
			$demo->cleanup_expired();
		}

		if ($action === 'get_status') {
			$session = $demo->resume();
			respond(true, [
				'mode'       => 'demo',
				'session'    => $session !== null,
				'expires_at' => $session['expires_at'] ?? null,
				'ttl_hours'  => $demo->ttl_seconds() / 3600,
			]);
		}

		if ($action === 'init_session') {
			$session = $demo->start();
			respond(true, [
				'mode'       => 'demo',
				'is_new'     => $session['is_new'],
				'expires_at' => $session['expires_at'],
			]);
		}

		$session = $demo->resume();
		if ($session === null) {
			respond(false, null, 'Deine Demo-Session ist abgelaufen.', 401);
		}
		$tree = $demo->tree($session['table']);
	} else {
		if ($action === 'get_status' || $action === 'init_session') {
			respond(true, ['mode' => 'admin', 'session' => true, 'expires_at' => null]);
		}
		$tree = new MetaTree($pdo, (string)($config['table'] ?? 'kategorien'));
	}

	// ── Aktionen ──────────────────────────────────────────────────────────
	switch ($action) {

		case 'get_tree':
			$root_id = $tree->get_root_id();
			$columns = $tree->get_meta_columns();
			if ($root_id === null) {
				respond(true, ['root_id' => null, 'nodes' => [], 'meta_columns' => $columns]);
			}
			$start = param_int_or_null('node_id') ?? $root_id;
			respond(true, [
				'root_id'      => $root_id,
				'nodes'        => $tree->get_subtree_with_meta($start),
				'meta_columns' => $columns,
			]);

		case 'get_meta_columns':
			respond(true, $tree->get_meta_columns());

		case 'insert_node':
			$demo?->check_node_limit($tree);
			$id = $tree->insert_node(param_string('name'), param_int_or_null('parent_id'));
			respond(true, ['id' => $id]);

		case 'delete_node':
			respond(true, ['deleted' => $tree->delete_node(param_int('node_id'))]);

		case 'reorder_node':
			$tree->reorder_node(param_int('node_id'), param_string('direction'));
			respond(true);

		case 'move_node':
			$tree->move_node(param_int('node_id'), param_int('new_parent_id'));
			respond(true);

		case 'rename_node':
			$tree->rename_node(param_int('node_id'), param_string('name'));
			respond(true);

		case 'set_online':
			$online = param_string('online');
			if (!in_array($online, ['0', '1'], true)) {
				throw new InvalidArgumentException('Parameter „online“ muss 0 oder 1 sein.');
			}
			$tree->set_online(param_int('node_id'), $online === '1');
			respond(true);

		case 'update_meta':
			$data = json_decode(param_string('data'), true);
			if (!is_array($data) || array_is_list($data) && $data !== []) {
				throw new InvalidArgumentException('Parameter „data“ muss ein JSON-Objekt sein.');
			}
			$demo?->check_meta_limits($tree, array_map('strval', array_keys($data)), $data);
			$tree->update_meta(param_int('node_id'), $data);
			respond(true);

		case 'add_meta_column':
			$column = param_string('column');
			$demo?->check_meta_limits($tree, [$column]);
			respond(true, ['column' => $tree->add_meta_column($column)]);

		case 'delete_meta_column':
			$tree->delete_meta_column(param_string('column'));
			respond(true);
	}

	respond(false, null, "Aktion „{$action}“ ist in diesem Modus nicht verfügbar.", 400);

} catch (InvalidArgumentException | TreeException $e) {
	// Fachliche Fehler: Meldung ist für Nutzer gedacht
	respond(false, null, $e->getMessage(), 422);
} catch (PDOException $e) {
	// Interne Details nur ins Log, nie an den Client
	error_log('[nested_list] ' . $e->getMessage());
	respond(false, null, 'Datenbankfehler. Details stehen im Server-Log.', 500);
} catch (Throwable $e) {
	error_log('[nested_list] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	respond(false, null, 'Interner Fehler. Details stehen im Server-Log.', 500);
}
