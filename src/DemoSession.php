<?php
// +---------------------------------------------------------------------+
// | Klasse DemoSession                                                  |
// | Eigene, befristete Demo-Tabelle pro Besucher für die Online-Demo    |
// +---------------------------------------------------------------------+
// | - Token im Cookie demo_token (HttpOnly, SameSite=Strict)            |
// | - Tabellen demo_<token> und demo_<token>_meta                       |
// | - Registry-Tabelle demo_sessions (token, ip_hash, created_at)       |
// | - Limits: aktive Sessions gesamt, Sessions pro IP und Stunde,       |
// |   Knoten, Meta-Spalten und Feldlänge pro Session                    |
// | - Aufräumen per cleanup_expired() aus Cron (bin/cleanup.php) und    |
// |   zusätzlich gelegentlich nebenbei aus der API                      |
// +---------------------------------------------------------------------+

declare(strict_types=1);

require_once __DIR__ . '/MetaTree.php';

class DemoSession {

	public const VERSION = '2.0.0';

	public const COOKIE_NAME    = 'demo_token';
	public const REGISTRY_TABLE = 'demo_sessions';

	/** Token: 16 Hex-Zeichen. Tabellenname: demo_<token>, max. 21 Zeichen. */
	private const TOKEN_PATTERN = '/^[a-f0-9]{16}$/';

	/** Erkennt Demo-Tabellen beim Aufräumen, schließt demo_sessions sicher aus. */
	private const TABLE_REGEX = '^demo_[a-f0-9]{16}(_meta)?$';

	private const DEFAULTS = [
		'ttl_hours'           => 2,
		'max_sessions'        => 100,
		'max_sessions_per_ip' => 5,
		'max_nodes'           => 50,
		'max_meta_columns'    => 10,
		'max_value_length'    => 1000,
		'ip_secret'           => '',
	];

	private PDO $pdo;
	private array $options;
	private bool $registry_checked = false;

	/**
	 * @param array $options Überschreibt einzelne Werte aus DEFAULTS
	 */
	public function __construct(PDO $pdo, array $options = []) {
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo     = $pdo;
		$this->options = array_merge(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));
	}

	public function ttl_seconds(): int {
		return (int)$this->options['ttl_hours'] * 3600;
	}

	// -------------------------------------------------------------------------
	// Session
	// -------------------------------------------------------------------------

	/**
	 * Gültige Session aus dem Cookie, sonst null. Legt nie etwas an.
	 *
	 * @return array{token: string, table: string, expires_at: int}|null
	 */
	public function resume(): ?array {
		$token = $_COOKIE[self::COOKIE_NAME] ?? '';
		if (!is_string($token) || !preg_match(self::TOKEN_PATTERN, $token)) {
			return null;
		}
		$this->ensure_registry();

		$stmt = $this->pdo->prepare(
			"SELECT UNIX_TIMESTAMP(created_at) FROM `" . self::REGISTRY_TABLE . "`
			  WHERE token = ? AND created_at > NOW() - INTERVAL ? SECOND"
		);
		$stmt->execute([$token, $this->ttl_seconds()]);
		$created = $stmt->fetchColumn();
		if ($created === false) {
			return null;
		}

		return [
			'token'      => $token,
			'table'      => $this->table_name($token),
			'expires_at' => (int)$created + $this->ttl_seconds(),
		];
	}

	/**
	 * Setzt eine bestehende Session fort oder legt eine neue an
	 * (Registry-Eintrag, Tabellen mit Beispielbaum, Cookie).
	 *
	 * @return array{token: string, table: string, expires_at: int, is_new: bool}
	 * @throws TreeException wenn ein Limit erreicht ist
	 */
	public function start(): array {
		$session = $this->resume();
		if ($session !== null) {
			return $session + ['is_new' => false];
		}

		$this->ensure_registry();
		$this->check_session_limits();

		$token = bin2hex(random_bytes(8));
		$stmt  = $this->pdo->prepare(
			"INSERT INTO `" . self::REGISTRY_TABLE . "` (token, ip_hash) VALUES (?, ?)"
		);
		$stmt->execute([$token, $this->ip_hash()]);

		$tree = $this->tree($this->table_name($token));
		$this->seed_example_tree($tree);

		$expires_at = time() + $this->ttl_seconds();
		setcookie(self::COOKIE_NAME, $token, [
			'expires'  => $expires_at,
			'path'     => $this->cookie_path(),
			'secure'   => $this->is_https(),
			'httponly' => true,
			'samesite' => 'Strict',
		]);
		// Für den Rest dieser Anfrage gilt die neue Session schon
		$_COOKIE[self::COOKIE_NAME] = $token;

		return [
			'token'      => $token,
			'table'      => $this->table_name($token),
			'expires_at' => $expires_at,
			'is_new'     => true,
		];
	}

	/** MetaTree-Instanz für die Tabelle einer Session. */
	public function tree(string $table): MetaTree {
		return new MetaTree($this->pdo, $table);
	}

	// -------------------------------------------------------------------------
	// Limits innerhalb einer Session (von der API vor dem Schreiben aufgerufen)
	// -------------------------------------------------------------------------

	/** @throws TreeException wenn die Session schon max_nodes Knoten hat */
	public function check_node_limit(MetaTree $tree): void {
		$stmt  = $this->pdo->query("SELECT COUNT(*) FROM `{$tree->get_table()}`");
		$limit = (int)$this->options['max_nodes'];
		if ((int)$stmt->fetchColumn() >= $limit) {
			throw new TreeException(
				"Demo-Limit erreicht: höchstens {$limit} Knoten. Lösch erst ein paar oder warte, bis die Demo zurückgesetzt wird."
			);
		}
	}

	/**
	 * Prüft Meta-Daten vor dem Speichern: Anzahl Spalten nach dem Speichern
	 * und Länge jedes Werts.
	 *
	 * @param string[] $new_columns Spalten, die neu hinzukommen würden
	 * @param array    $values      zu speichernde Werte
	 * @throws TreeException bei Überschreitung
	 */
	public function check_meta_limits(MetaTree $tree, array $new_columns, array $values = []): void {
		$existing = array_map('strtolower', $tree->get_meta_columns());
		$added    = array_diff(array_map('strtolower', $new_columns), $existing);
		$limit    = (int)$this->options['max_meta_columns'];
		if (count($existing) + count(array_unique($added)) > $limit) {
			throw new TreeException("Demo-Limit erreicht: höchstens {$limit} Meta-Spalten.");
		}

		$max_len = (int)$this->options['max_value_length'];
		foreach ($values as $column => $value) {
			if (is_string($value) && mb_strlen($value) > $max_len) {
				throw new TreeException("„{$column}“ ist zu lang: In der Demo sind höchstens {$max_len} Zeichen erlaubt.");
			}
		}
	}

	// -------------------------------------------------------------------------
	// Aufräumen
	// -------------------------------------------------------------------------

	/**
	 * Löscht abgelaufene Sessions samt Tabellen. Entfernt außerdem verwaiste
	 * Demo-Tabellen, zu denen es keinen gültigen Registry-Eintrag mehr gibt.
	 *
	 * @return int Anzahl gelöschter Tabellen
	 */
	public function cleanup_expired(): int {
		$this->ensure_registry();

		$stmt = $this->pdo->prepare(
			"DELETE FROM `" . self::REGISTRY_TABLE . "` WHERE created_at <= NOW() - INTERVAL ? SECOND"
		);
		$stmt->execute([$this->ttl_seconds()]);

		$active = $this->pdo->query("SELECT token FROM `" . self::REGISTRY_TABLE . "`")
			->fetchAll(PDO::FETCH_COLUMN);
		$active = array_flip($active);

		// Meta-Tabellen zuerst, weil sie per Fremdschlüssel auf die Haupttabelle zeigen
		$stmt = $this->pdo->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES
			  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP ?
			  ORDER BY (TABLE_NAME LIKE '%\\_meta') DESC, TABLE_NAME"
		);
		$stmt->execute([self::TABLE_REGEX]);

		$dropped = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
			if (!preg_match('/^demo_([a-f0-9]{16})(_meta)?$/', $table, $m)) {
				continue;
			}
			if (isset($active[$m[1]])) {
				continue;
			}
			$this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
			$dropped++;
		}
		return $dropped;
	}

	// -------------------------------------------------------------------------
	// Interne Hilfsmethoden
	// -------------------------------------------------------------------------

	private function ensure_registry(): void {
		if ($this->registry_checked) {
			return;
		}
		$this->pdo->exec("
			CREATE TABLE IF NOT EXISTS `" . self::REGISTRY_TABLE . "` (
				`token`      CHAR(16) NOT NULL PRIMARY KEY,
				`ip_hash`    CHAR(64) NOT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX `idx_ip_time` (`ip_hash`, `created_at`),
				INDEX `idx_time` (`created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
		");
		$this->registry_checked = true;
	}

	/** @throws TreeException wenn die Demo voll ist oder die IP zu viele Sessions hat */
	private function check_session_limits(): void {
		$ttl = $this->ttl_seconds();

		$stmt = $this->pdo->prepare(
			"SELECT COUNT(*) FROM `" . self::REGISTRY_TABLE . "` WHERE created_at > NOW() - INTERVAL ? SECOND"
		);
		$stmt->execute([$ttl]);
		if ((int)$stmt->fetchColumn() >= (int)$this->options['max_sessions']) {
			throw new TreeException('Die Demo ist gerade ausgelastet. Versuch es bitte später noch einmal.');
		}

		$stmt = $this->pdo->prepare(
			"SELECT COUNT(*) FROM `" . self::REGISTRY_TABLE . "`
			  WHERE ip_hash = ? AND created_at > NOW() - INTERVAL 1 HOUR"
		);
		$stmt->execute([$this->ip_hash()]);
		if ((int)$stmt->fetchColumn() >= (int)$this->options['max_sessions_per_ip']) {
			throw new TreeException('Von deiner Adresse wurden in der letzten Stunde zu viele Demos gestartet. Versuch es bitte später noch einmal.');
		}
	}

	/**
	 * Gehashte IP für das Ratenlimit. Mit geheimem Schlüssel (HMAC), damit sich
	 * IPv4-Adressen nicht einfach per Durchprobieren zurückrechnen lassen.
	 */
	private function ip_hash(): string {
		return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', (string)$this->options['ip_secret']);
	}

	private function table_name(string $token): string {
		if (!preg_match(self::TOKEN_PATTERN, $token)) {
			throw new InvalidArgumentException('Ungültiges Demo-Token.');
		}
		return 'demo_' . $token;
	}

	/** Cookie nur für das Verzeichnis der API, z. B. /nested_list/ */
	private function cookie_path(): string {
		$dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
		return rtrim($dir, '/') . '/';
	}

	private function is_https(): bool {
		return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| ($_SERVER['SERVER_PORT'] ?? '') === '443'
			|| strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
	}

	/** Beispielbaum für eine neue Session. */
	private function seed_example_tree(MetaTree $tree): void {
		$root = $tree->insert_node('Wurzel');
		$a    = $tree->insert_node('Kategorie A', $root);
		$b    = $tree->insert_node('Kategorie B', $root);
		$tree->insert_node('Unterpunkt A1', $a);
		$tree->insert_node('Unterpunkt A2', $a);
		$tree->insert_node('Unterpunkt B1', $b);
		$tree->insert_node('Unterpunkt 😊', $b);
		$hidden = $tree->insert_node('Unterpunkt 😴', $b);
		$tree->set_online($hidden, false);

		$tree->update_meta($root, ['beschreibung' => 'Beispielbaum – probier ruhig alles aus.']);
		$tree->update_meta($a, ['beschreibung' => 'Klick einen Knoten an, um ihn zu bearbeiten.']);
	}
}
