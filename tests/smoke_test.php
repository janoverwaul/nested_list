<?php
// +---------------------------------------------------------------------+
// | Smoke-Test für AdjacencyList, MetaTree und DemoSession              |
// | Aufruf:  php tests/smoke_test.php                                   |
// | Nutzt die Zugangsdaten aus config.php und legt nur Tabellen mit     |
// | zufälligem Namen an (zz_test_…), die am Ende wieder gelöscht werden.|
// +---------------------------------------------------------------------+

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit("Nur für die Kommandozeile.\n");
}

require_once dirname(__DIR__) . '/src/MetaTree.php';
require_once dirname(__DIR__) . '/src/DemoSession.php';

$config = require (getenv('NESTED_LIST_CONFIG') ?: dirname(__DIR__) . '/config.php');
$db     = $config['db'];
$pdo    = AdjacencyList::connect($db['host'], $db['name'], $db['user'], $db['password'], $db['charset'] ?? 'utf8mb4');

$table  = 'zz_test_' . bin2hex(random_bytes(4));
$passed = 0;
$failed = 0;

function check(string $label, bool $ok): void {
	global $passed, $failed;
	$ok ? $passed++ : $failed++;
	echo ($ok ? "  ok    " : "  FEHLER") . "  {$label}\n";
}

function throws(string $label, string $class, callable $fn): void {
	try {
		$fn();
		check("{$label} (keine Exception)", false);
	} catch (Throwable $e) {
		check("{$label} → " . get_class($e) . ': ' . $e->getMessage(), $e instanceof $class);
	}
}

echo 'Datenbank: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo "Testtabelle: {$table}\n\n";

try {
	throws('Ungültiger Tabellenname', InvalidArgumentException::class, fn() => new AdjacencyList($pdo, 'x-y; DROP'));

	$tree = new MetaTree($pdo, $table);
	check('Leere Tabelle: kein Root', $tree->get_root_id() === null);

	$root = $tree->insert_node('Root');
	$a    = $tree->insert_node('A', $root);
	$b    = $tree->insert_node('B', $root);
	$a1   = $tree->insert_node('A1', $a);
	$a2   = $tree->insert_node('A2', $a);
	$a3   = $tree->insert_node('A3', $a);
	check('insert_node gibt IDs zurück', $root > 0 && $a3 > $a2);
	check('get_root_id', $tree->get_root_id() === $root);

	throws('Zweiter Root', TreeException::class, fn() => $tree->insert_node('Root 2'));
	throws('Eltern fehlt', TreeException::class, fn() => $tree->insert_node('X', 99999));
	throws('Leerer Name', InvalidArgumentException::class, fn() => $tree->insert_node('   ', $root));

	$nodes = array_column($tree->get_subtree($root), null, 'id');
	check('Teilbaum hat 6 Knoten', count($nodes) === 6);
	check('level', $nodes[$root]['level'] === 0 && $nodes[$a1]['level'] === 2);
	check('child_count / has_children', $nodes[$a]['child_count'] === 3 && $nodes[$a]['has_children'] && !$nodes[$a1]['has_children']);
	check('Geschwister A1', !$nodes[$a1]['has_prev_sibling'] && $nodes[$a1]['has_next_sibling']);
	check('Geschwister A3', $nodes[$a3]['has_prev_sibling'] && !$nodes[$a3]['has_next_sibling']);
	check('Root hat keine Geschwister', !$nodes[$root]['has_prev_sibling'] && !$nodes[$root]['has_next_sibling']);

	// online_only
	$tree->set_online($a2, false);
	$tree->set_online($b, false);
	$online = array_column($tree->get_subtree($root, true), null, 'id');
	check('online_only blendet aus', !isset($online[$a2]) && !isset($online[$b]) && count($online) === 4);
	check('online_only zählt Kinder konsistent', $online[$a]['child_count'] === 2 && $online[$root]['child_count'] === 1);
	check('online_only Geschwister konsistent', $online[$a1]['has_next_sibling'] && $online[$a3]['has_prev_sibling']);
	$tree->set_online($a2, true);
	$tree->set_online($b, true);

	// reorder
	$tree->reorder_node($a3, 'up');
	$order = array_column(array_filter($tree->get_subtree($a), fn($n) => $n['level'] === 1), 'name');
	check('reorder_node up', $order === ['A1', 'A3', 'A2']);
	throws('reorder am Rand', TreeException::class, fn() => $tree->reorder_node($a1, 'up'));
	throws('reorder falsche Richtung', InvalidArgumentException::class, fn() => $tree->reorder_node($a1, 'links'));

	// move
	throws('move auf sich selbst', InvalidArgumentException::class, fn() => $tree->move_node($a, $a));
	throws('move in eigenen Teilbaum', TreeException::class, fn() => $tree->move_node($a, $a1));
	throws('move nach 0 (zweiter Root)', InvalidArgumentException::class, fn() => $tree->move_node($a, 0));
	throws('move zu fehlendem Ziel', TreeException::class, fn() => $tree->move_node($a, 99999));
	$tree->move_node($a1, $b);
	check('move_node', $tree->get_node($a1)['parent_id'] === $b);

	// rename
	$tree->rename_node($b, '  B neu  ');
	check('rename_node trimmt', $tree->get_node($b)['name'] === 'B neu');

	// Meta
	check('Keine Meta-Spalten', $tree->get_meta_columns() === []);
	check('add_meta_column', $tree->add_meta_column('Bild') === 'Bild');
	check('add_meta_column gleiche Spalte anders geschrieben', $tree->add_meta_column('bild') === 'Bild');
	$tree->update_meta($a1, ['bild' => 'a1.jpg', 'farbe' => 'rot', 'zahl' => 3, 'flag' => true, 'leer' => null]);
	check('Spalten angelegt', $tree->get_meta_columns() === ['Bild', 'farbe', 'zahl', 'flag', 'leer']);
	$meta = $tree->get_meta($a1);
	check('Meta-Werte', $meta['Bild'] === 'a1.jpg' && $meta['zahl'] === '3' && $meta['flag'] === '1' && $meta['leer'] === null);
	$tree->update_meta($a1, ['farbe' => 'blau']);
	check('UPSERT', $tree->get_meta($a1)['farbe'] === 'blau' && $tree->get_meta($a1)['Bild'] === 'a1.jpg');
	throws('Meta: geschützte Spalte', InvalidArgumentException::class, fn() => $tree->update_meta($a1, ['NODE_ID' => 1]));
	throws('Meta: verschachteltes Array', InvalidArgumentException::class, fn() => $tree->update_meta($a1, ['x' => ['y']]));
	throws('Meta: Knoten fehlt', TreeException::class, fn() => $tree->update_meta(99999, ['farbe' => 'grün']));
	throws('Meta: ungültiger Spaltenname', InvalidArgumentException::class, fn() => $tree->add_meta_column('a b'));

	$with_meta = array_column($tree->get_subtree_with_meta($root), null, 'id');
	check('get_subtree_with_meta', $with_meta[$a1]['meta']['farbe'] === 'blau' && $with_meta[$root]['meta']['farbe'] === null);

	$tree->delete_meta_column('LEER');
	check('delete_meta_column (andere Schreibweise)', !in_array('leer', $tree->get_meta_columns(), true));
	throws('delete_meta_column fehlt', TreeException::class, fn() => $tree->delete_meta_column('gibtsnicht'));

	// delete mit CASCADE
	$tree->update_meta($b, ['farbe' => 'gelb']);
	$deleted = $tree->delete_node($b);
	check('delete_node löscht Teilbaum (B + A1)', $deleted === 2);
	$orphans = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}_meta` m LEFT JOIN `{$table}` t ON t.id = m.node_id WHERE t.id IS NULL")->fetchColumn();
	check('CASCADE: keine verwaisten Meta-Zeilen', $orphans === 0);
	check('purge_orphaned_meta', $tree->purge_orphaned_meta() === 0);

	// Transaktion: Fehler in move rollt zurück
	check('Keine offene Transaktion', !$pdo->inTransaction());

	// DemoSession
	echo "\nDemoSession\n";
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 254);
	$_SERVER['SCRIPT_NAME'] = '/nested_list/api.php';
	$demo    = new DemoSession($pdo, ['max_sessions_per_ip' => 2, 'max_nodes' => 10, 'max_meta_columns' => 2]);
	$session = @$demo->start(); // @: setcookie() meckert in der CLI wegen bereits gesendeter Ausgabe
	check('Demo-Session angelegt', $session['is_new'] && str_starts_with($session['table'], 'demo_'));
	check('resume findet Session', $demo->resume()['token'] === $session['token']);
	$demo_tree = $demo->tree($session['table']);
	check('Beispielbaum', count($demo_tree->get_subtree($demo_tree->get_root_id())) === 8);
	check('Beispielbaum online_only', count($demo_tree->get_subtree($demo_tree->get_root_id(), true)) === 7);
	$demo->check_node_limit($demo_tree);
	$demo_tree->insert_node('9', $demo_tree->get_root_id());
	$demo_tree->insert_node('10', $demo_tree->get_root_id());
	throws('Knotenlimit', TreeException::class, fn() => $demo->check_node_limit($demo_tree));
	throws('Meta-Spaltenlimit', TreeException::class, fn() => $demo->check_meta_limits($demo_tree, ['a', 'b']));
	throws('Feldlänge', TreeException::class, fn() => $demo->check_meta_limits($demo_tree, [], ['beschreibung' => str_repeat('x', 1001)]));

	unset($_COOKIE['demo_token']);
	$second = @$demo->start();
	unset($_COOKIE['demo_token']);
	throws('IP-Limit', TreeException::class, fn() => @$demo->start());

	// Ablauf simulieren
	$pdo->prepare("UPDATE demo_sessions SET created_at = NOW() - INTERVAL 3 HOUR WHERE token IN (?, ?)")
		->execute([$session['token'], $second['token']]);
	$_COOKIE['demo_token'] = $session['token'];
	check('Abgelaufene Session wird nicht fortgesetzt', $demo->resume() === null);
	$dropped = $demo->cleanup_expired();
	check("cleanup_expired löscht 4 Tabellen ({$dropped})", $dropped === 4);
	$left = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('{$session['table']}', '{$session['table']}_meta')")->fetchColumn();
	check('Demo-Tabellen weg', $left === 0);
	check('Registry-Tabelle bleibt', (bool)$pdo->query("SHOW TABLES LIKE 'demo\\_sessions'")->fetch());

} finally {
	$pdo->exec("DROP TABLE IF EXISTS `{$table}_meta`");
	$pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}

echo "\n{$passed} ok, {$failed} Fehler\n";
exit($failed > 0 ? 1 : 0);
