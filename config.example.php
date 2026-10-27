<?php
// +---------------------------------------------------------------------+
// | Vorlage für config.php                                              |
// +---------------------------------------------------------------------+
// | 1. Diese Datei als config.php in DENSELBEN Ordner kopieren          |
// |    (neben web/, also außerhalb des Webroots).                       |
// | 2. Zugangsdaten eintragen.                                          |
// | config.php wird von Git ignoriert und vom pre-commit-Hook geblockt. |
// | In diese Vorlage gehören NIE echte Zugangsdaten.                    |
// +---------------------------------------------------------------------+

return [

	'db' => [
		'host'     => 'localhost',
		'name'     => '',
		'user'     => '',
		'password' => '',
		'charset'  => 'utf8mb4',
	],

	// 'demo'  = jeder Besucher bekommt eine eigene Demo-Tabelle (2 Stunden)
	// 'admin' = der Editor bearbeitet direkt die Tabelle unten
	'mode' => 'demo',

	// Nur im Admin-Modus: zu bearbeitende Baumtabelle
	'table' => 'kategorien',

	// Nur im Admin-Modus: API verlangt einen per Webserver angemeldeten
	// Benutzer (Basic Auth). Nur abschalten, wenn der Zugriff anders
	// geschützt ist, z. B. lokal auf dem eigenen Rechner.
	'require_auth' => true,

	// Nur im Demo-Modus
	'demo' => [
		'ttl_hours'           => 2,     // Lebensdauer einer Demo-Session
		'max_sessions'        => 100,   // gleichzeitig aktive Sessions
		'max_sessions_per_ip' => 5,     // neue Sessions pro IP und Stunde
		'max_nodes'           => 50,    // Knoten pro Session
		'max_meta_columns'    => 10,    // Meta-Spalten pro Session
		'max_value_length'    => 1000,  // Zeichen pro Meta-Wert
		'ip_secret'           => '',    // zufälliger Text, z. B. bin2hex(random_bytes(16))
	],
];
