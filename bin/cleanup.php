<?php
// +---------------------------------------------------------------------+
// | cleanup.php – Aufräumen per Cron (nur Kommandozeile)                |
// +---------------------------------------------------------------------+
// | Demo-Modus: löscht abgelaufene Demo-Sessions und ihre Tabellen.     |
// |   */15 * * * * /pfad/zu/php /pfad/bin/cleanup.php                   |
// |                                                                     |
// | Admin-Modus mit --purge-meta: entfernt Meta-Zeilen ohne Knoten      |
// | (Altlast aus Version 1.x, einmalig nach dem Umstieg).               |
// |   php bin/cleanup.php --purge-meta                                  |
// +---------------------------------------------------------------------+

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once dirname(__DIR__) . '/src/MetaTree.php';
require_once dirname(__DIR__) . '/src/DemoSession.php';

$config_file = getenv('NESTED_LIST_CONFIG') ?: dirname(__DIR__) . '/config.php';
if (!is_file($config_file)) {
	fwrite(STDERR, "config.php fehlt: {$config_file}\n");
	exit(1);
}
$config = require $config_file;
$db     = $config['db'] ?? [];
$pdo    = AdjacencyList::connect(
	(string)($db['host'] ?? 'localhost'),
	(string)($db['name'] ?? ''),
	(string)($db['user'] ?? ''),
	(string)($db['password'] ?? ''),
	(string)($db['charset'] ?? 'utf8mb4')
);

$quiet = in_array('--quiet', $argv, true);

if (in_array('--purge-meta', $argv, true)) {
	$tree    = new MetaTree($pdo, (string)($config['table'] ?? 'kategorien'));
	$removed = $tree->purge_orphaned_meta();
	echo "Verwaiste Meta-Zeilen in {$tree->get_table()}_meta entfernt: {$removed}\n";
	exit(0);
}

if (($config['mode'] ?? 'demo') !== 'demo') {
	$quiet || print("Admin-Modus – keine Demo-Sessions aufzuräumen.\n");
	exit(0);
}

$demo    = new DemoSession($pdo, $config['demo'] ?? []);
$dropped = $demo->cleanup_expired();
$quiet || print(date('Y-m-d H:i:s') . " Demo-Tabellen gelöscht: {$dropped}\n");
