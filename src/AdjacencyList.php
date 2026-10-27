<?php
// +---------------------------------------------------------------------+
// | Klasse AdjacencyList                                                |
// | Baumstruktur als Adjacency List mit rekursiven CTEs                 |
// | (MySQL 8.0+ / MariaDB 10.2+)                                        |
// +---------------------------------------------------------------------+
// | Einfügen, Verschieben und Löschen ohne Massenupdate aller Knoten.   |
// | Ganze Teilbäume werden per WITH RECURSIVE in einer Abfrage gelesen. |
// +---------------------------------------------------------------------+
// | Idee und Begriffe angelehnt an die Klasse NESTEDSET von             |
// | Oliver Blum (Blumithek, Version 0.9, 2007). Die Technik ist         |
// | komplett neu, die Benennung seit Version 2.0 ebenfalls.             |
// +---------------------------------------------------------------------+

declare(strict_types=1);

require_once __DIR__ . '/TreeException.php';

class AdjacencyList {

	public const VERSION = '2.0.0';
	public const AUTHOR  = 'Jan Overwaul';

	/** Pflichtspalten jeder verwalteten Tabelle. Zusatzspalten sind erlaubt. */
	protected const REQUIRED_COLUMNS = ['id', 'name', 'parent_id', 'sort_order', 'online'];

	/**
	 * Erlaubte Tabellennamen. Maximal 59 Zeichen, damit der Name der
	 * Meta-Tabelle (+ "_meta") unter dem MySQL-Limit von 64 Zeichen bleibt.
	 */
	protected const TABLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,58}$/';

	/** Maximale Länge eines Knotennamens (Spalte VARCHAR(255)). */
	protected const MAX_NAME_LENGTH = 255;

	protected PDO $pdo;
	protected string $table;

	/** Merkt sich, ob ensure_table() in dieser Instanz schon gelaufen ist. */
	private bool $table_checked = false;

	/**
	 * @param PDO    $pdo   Bestehende Verbindung (siehe AdjacencyList::connect())
	 * @param string $table Name der Baumtabelle; wird bei Bedarf angelegt
	 * @throws InvalidArgumentException bei ungültigem Tabellennamen
	 */
	public function __construct(PDO $pdo, string $table) {
		if (!preg_match(self::TABLE_PATTERN, $table)) {
			throw new InvalidArgumentException(
				"Ungültiger Tabellenname '{$table}'. Erlaubt sind Buchstaben, Ziffern und _ (max. 59 Zeichen)."
			);
		}
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo   = $pdo;
		$this->table = $table;
	}

	/**
	 * Baut eine passend konfigurierte PDO-Verbindung auf.
	 *
	 * @throws PDOException bei Verbindungsfehler
	 */
	public static function connect(
		string $host,
		string $dbname,
		string $user,
		string $password,
		string $charset = 'utf8mb4'
	): PDO {
		return new PDO(
			"mysql:host={$host};dbname={$dbname};charset={$charset}",
			$user,
			$password,
			[
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false,
			]
		);
	}

	public function get_table(): string {
		return $this->table;
	}

	// -------------------------------------------------------------------------
	// Interne Hilfsmethoden
	// -------------------------------------------------------------------------

	/**
	 * Prüft, ob eine Tabelle in der aktuellen Datenbank existiert.
	 * Exakter Vergleich über information_schema (bei SHOW TABLES LIKE wäre
	 * "_" ein Platzhalter).
	 */
	protected function table_exists(string $table): bool {
		$stmt = $this->pdo->prepare(
			"SELECT 1 FROM information_schema.TABLES
			  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
		);
		$stmt->execute([$table]);
		return $stmt->fetchColumn() !== false;
	}

	/**
	 * Liefert die Spaltennamen einer Tabelle in ihrer Reihenfolge.
	 *
	 * @return string[]
	 */
	protected function table_columns(string $table): array {
		$stmt = $this->pdo->prepare(
			"SELECT COLUMN_NAME FROM information_schema.COLUMNS
			  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
			  ORDER BY ORDINAL_POSITION"
		);
		$stmt->execute([$table]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * Stellt sicher, dass die Baumtabelle existiert und die Pflichtspalten hat.
	 * Fehlt sie, wird sie angelegt. Läuft pro Instanz nur einmal.
	 *
	 * Achtung: CREATE TABLE beendet in MySQL eine offene Transaktion
	 * (impliziter Commit). Deshalb immer VOR beginTransaction() aufrufen.
	 *
	 * @throws TreeException wenn eine vorhandene Tabelle Pflichtspalten vermisst
	 */
	protected function ensure_table(): void {
		if ($this->table_checked) {
			return;
		}

		if (!$this->table_exists($this->table)) {
			$this->pdo->exec("
				CREATE TABLE `{$this->table}` (
					`id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`name`       VARCHAR(255) NOT NULL,
					`parent_id`  INT UNSIGNED NOT NULL DEFAULT 0,
					`sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
					`online`     TINYINT(1)   NOT NULL DEFAULT 1,
					INDEX `idx_parent_sort` (`parent_id`, `sort_order`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
			");
		} else {
			$existing = array_map('strtolower', $this->table_columns($this->table));
			$missing  = array_diff(self::REQUIRED_COLUMNS, $existing);
			if ($missing) {
				throw new TreeException(
					"Tabelle '{$this->table}' hat kein gültiges Schema. Fehlende Spalten: "
					. implode(', ', $missing)
				);
			}
		}

		$this->table_checked = true;
	}

	/**
	 * Führt $work in einer Transaktion aus. Läuft bereits eine Transaktion
	 * (z. B. weil der Aufrufer mehrere Schritte bündelt), wird sie mitbenutzt.
	 *
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	protected function transaction(callable $work): mixed {
		if ($this->pdo->inTransaction()) {
			return $work();
		}
		$this->pdo->beginTransaction();
		try {
			$result = $work();
			$this->pdo->commit();
			return $result;
		} catch (Throwable $e) {
			$this->pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Liest eine Zeile der Baumtabelle.
	 *
	 * @param bool $for_update Zeile bis zum Ende der Transaktion sperren
	 * @throws InvalidArgumentException bei ID <= 0
	 * @throws TreeException wenn der Knoten nicht existiert
	 */
	protected function fetch_node(int $node_id, bool $for_update = false): array {
		if ($node_id <= 0) {
			throw new InvalidArgumentException("Ungültige Knoten-ID: {$node_id}.");
		}
		$lock = $for_update ? ' FOR UPDATE' : '';
		$stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE id = ?{$lock}");
		$stmt->execute([$node_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			throw new TreeException("Knoten #{$node_id} existiert nicht.");
		}
		return $this->normalize_node($row);
	}

	/**
	 * Wandelt die Pflichtfelder einer DB-Zeile in passende PHP-Typen um.
	 * Zusatzspalten bleiben unverändert.
	 */
	protected function normalize_node(array $row): array {
		foreach (['id', 'parent_id', 'sort_order', 'level', 'child_count'] as $key) {
			if (array_key_exists($key, $row)) {
				$row[$key] = (int)$row[$key];
			}
		}
		foreach (['online', 'has_prev_sibling', 'has_next_sibling'] as $key) {
			if (array_key_exists($key, $row)) {
				$row[$key] = (bool)$row[$key];
			}
		}
		if (array_key_exists('child_count', $row)) {
			$row['has_children'] = $row['child_count'] > 0;
		}
		return $row;
	}

	/** Nächste freie Sortierposition (= letztes Kind) unter $parent_id. */
	protected function next_sort_order(int $parent_id): int {
		$stmt = $this->pdo->prepare(
			"SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `{$this->table}` WHERE parent_id = ?"
		);
		$stmt->execute([$parent_id]);
		return (int)$stmt->fetchColumn();
	}

	/**
	 * Alle IDs des Teilbaums ab $node_id (inklusive $node_id).
	 *
	 * @return int[]
	 */
	protected function subtree_ids(int $node_id): array {
		$stmt = $this->pdo->prepare("
			WITH RECURSIVE subtree AS (
				SELECT id FROM `{$this->table}` WHERE id = :start_id
				UNION ALL
				SELECT n.id FROM `{$this->table}` n
				INNER JOIN subtree s ON n.parent_id = s.id
			)
			SELECT id FROM subtree
		");
		$stmt->execute([':start_id' => $node_id]);
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/** Prüft und bereinigt einen Knotennamen. */
	protected function clean_name(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException('Der Name darf nicht leer sein.');
		}
		if (mb_strlen($name) > static::MAX_NAME_LENGTH) {
			throw new InvalidArgumentException(
				'Der Name ist zu lang (max. ' . static::MAX_NAME_LENGTH . ' Zeichen).'
			);
		}
		return $name;
	}

	// -------------------------------------------------------------------------
	// Lesen
	// -------------------------------------------------------------------------

	/**
	 * Ein einzelner Knoten als assoziatives Array.
	 *
	 * @throws TreeException wenn der Knoten nicht existiert
	 */
	public function get_node(int $node_id): array {
		$this->ensure_table();
		return $this->fetch_node($node_id);
	}

	/**
	 * ID des Root-Knotens (parent_id = 0) oder null, wenn die Tabelle leer ist.
	 * Der Root ist über parent_id = 0 definiert, nicht über eine feste ID.
	 */
	public function get_root_id(): ?int {
		$this->ensure_table();
		$stmt = $this->pdo->query(
			"SELECT id FROM `{$this->table}` WHERE parent_id = 0 ORDER BY id LIMIT 1"
		);
		$id = $stmt->fetchColumn();
		return $id !== false ? (int)$id : null;
	}

	/**
	 * Holt den Teilbaum ab $node_id (inklusive $node_id).
	 *
	 * Jeder Knoten enthält zusätzlich:
	 *   level            Tiefe relativ zu $node_id (0 = Startknoten)
	 *   child_count      Anzahl direkter Kinder
	 *   has_children     child_count > 0
	 *   has_prev_sibling es gibt ein Geschwister davor
	 *   has_next_sibling es gibt ein Geschwister dahinter
	 *
	 * Mit $online_only = true werden Offline-Knoten samt ihren Teilbäumen
	 * ausgeblendet; die Zusatzfelder zählen dann ebenfalls nur Online-Knoten.
	 * Ist der Startknoten selbst offline, ist das Ergebnis leer.
	 *
	 * @return array<int, array> sortiert nach level, parent_id, sort_order
	 * @throws TreeException wenn $node_id nicht existiert
	 */
	public function get_subtree(int $node_id, bool $online_only = false): array {
		$this->ensure_table();
		$this->fetch_node($node_id);

		$filter_n = $online_only ? 'AND n.online = 1' : '';
		$filter_c = $online_only ? 'WHERE c.online = 1' : '';
		$filter_s = $online_only ? 'AND s.online = 1' : '';

		$stmt = $this->pdo->prepare("
			WITH RECURSIVE tree AS (
				SELECT n.*, 0 AS `level`
				  FROM `{$this->table}` n
				 WHERE n.id = :start_id {$filter_n}
				UNION ALL
				SELECT n.*, t.`level` + 1
				  FROM `{$this->table}` n
				 INNER JOIN tree t ON n.parent_id = t.id
				 WHERE 1 = 1 {$filter_n}
			)
			SELECT
				t.*,
				COALESCE(cc.child_count, 0) AS child_count,
				EXISTS (
					SELECT 1 FROM `{$this->table}` s
					 WHERE s.parent_id = t.parent_id AND t.parent_id <> 0
					   AND s.sort_order < t.sort_order {$filter_s}
				) AS has_prev_sibling,
				EXISTS (
					SELECT 1 FROM `{$this->table}` s
					 WHERE s.parent_id = t.parent_id AND t.parent_id <> 0
					   AND s.sort_order > t.sort_order {$filter_s}
				) AS has_next_sibling
			  FROM tree t
			  LEFT JOIN (
				SELECT c.parent_id, COUNT(*) AS child_count
				  FROM `{$this->table}` c
				  {$filter_c}
				 GROUP BY c.parent_id
			  ) cc ON cc.parent_id = t.id
			 ORDER BY t.`level`, t.parent_id, t.sort_order
		");
		$stmt->execute([':start_id' => $node_id]);

		return array_map([$this, 'normalize_node'], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	// -------------------------------------------------------------------------
	// Schreiben
	// -------------------------------------------------------------------------

	/**
	 * Fügt einen Knoten als letztes Kind von $parent_id ein.
	 * Ohne $parent_id (oder mit 0) wird der Root-Knoten angelegt – das geht
	 * nur, solange die Tabelle noch keinen Root hat.
	 *
	 * @return int ID des neuen Knotens
	 * @throws TreeException wenn schon ein Root existiert oder der Elternknoten fehlt
	 */
	public function insert_node(string $name, ?int $parent_id = null): int {
		$name      = $this->clean_name($name);
		$parent_id = $parent_id ?? 0;
		$this->ensure_table();

		return $this->transaction(function () use ($name, $parent_id): int {
			if ($parent_id === 0) {
				$stmt = $this->pdo->query(
					"SELECT id FROM `{$this->table}` WHERE parent_id = 0 LIMIT 1 FOR UPDATE"
				);
				if ($stmt->fetchColumn() !== false) {
					throw new TreeException(
						'Es gibt schon einen Root-Knoten. Pro Tabelle ist nur ein Baum erlaubt.'
					);
				}
			} else {
				// Elternknoten sperren: serialisiert parallele Inserts unter demselben Eltern
				$this->fetch_node($parent_id, true);
			}

			$stmt = $this->pdo->prepare(
				"INSERT INTO `{$this->table}` (name, parent_id, sort_order) VALUES (?, ?, ?)"
			);
			$stmt->execute([$name, $parent_id, $this->next_sort_order($parent_id)]);
			return (int)$this->pdo->lastInsertId();
		});
	}

	/**
	 * Löscht einen Knoten samt aller Nachfahren.
	 * Verknüpfte Zeilen mit ON DELETE CASCADE (z. B. die Meta-Tabelle von
	 * MetaTree) werden von der Datenbank mitgelöscht.
	 *
	 * @return int Anzahl gelöschter Knoten
	 * @throws TreeException wenn der Knoten nicht existiert
	 */
	public function delete_node(int $node_id): int {
		$this->ensure_table();

		return $this->transaction(function () use ($node_id): int {
			$this->fetch_node($node_id, true);
			$ids = $this->subtree_ids($node_id);

			$placeholders = implode(',', array_fill(0, count($ids), '?'));
			$stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE id IN ({$placeholders})");
			$stmt->execute($ids);
			return $stmt->rowCount();
		});
	}

	/**
	 * Tauscht die Position eines Knotens mit seinem vorherigen ('up') bzw.
	 * nächsten ('down') Geschwisterknoten.
	 *
	 * @param string $direction 'up' oder 'down'
	 * @throws InvalidArgumentException bei unbekannter Richtung
	 * @throws TreeException wenn es in dieser Richtung kein Geschwister gibt
	 */
	public function reorder_node(int $node_id, string $direction): void {
		if (!in_array($direction, ['up', 'down'], true)) {
			throw new InvalidArgumentException("Ungültige Richtung '{$direction}' (erlaubt: up, down).");
		}
		$this->ensure_table();

		$this->transaction(function () use ($node_id, $direction): void {
			$node = $this->fetch_node($node_id, true);

			[$cmp, $order] = $direction === 'up' ? ['<', 'DESC'] : ['>', 'ASC'];
			$stmt = $this->pdo->prepare(
				"SELECT id, sort_order FROM `{$this->table}`
				  WHERE parent_id = ? AND sort_order {$cmp} ?
				  ORDER BY sort_order {$order} LIMIT 1 FOR UPDATE"
			);
			$stmt->execute([$node['parent_id'], $node['sort_order']]);
			$sibling = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($sibling === false) {
				$where = $direction === 'up' ? 'ganz oben' : 'ganz unten';
				throw new TreeException("„{$node['name']}“ steht schon {$where}.");
			}

			$upd = $this->pdo->prepare("UPDATE `{$this->table}` SET sort_order = ? WHERE id = ?");
			$upd->execute([(int)$sibling['sort_order'], $node_id]);
			$upd->execute([$node['sort_order'], (int)$sibling['id']]);
		});
	}

	/**
	 * Hängt einen Knoten samt Teilbaum als letztes Kind unter $new_parent_id.
	 *
	 * @throws InvalidArgumentException wenn das Ziel der Knoten selbst ist
	 * @throws TreeException wenn das Ziel fehlt oder im eigenen Teilbaum liegt
	 */
	public function move_node(int $node_id, int $new_parent_id): void {
		if ($node_id === $new_parent_id) {
			throw new InvalidArgumentException('Ein Knoten kann nicht unter sich selbst verschoben werden.');
		}
		$this->ensure_table();

		$this->transaction(function () use ($node_id, $new_parent_id): void {
			$node = $this->fetch_node($node_id, true);
			// Ziel muss existieren. Ziel 0 (= zweiter Root) ist damit ausgeschlossen.
			$this->fetch_node($new_parent_id, true);

			if (in_array($new_parent_id, $this->subtree_ids($node_id), true)) {
				throw new TreeException(
					"Knoten #{$new_parent_id} liegt im Teilbaum von „{$node['name']}“ – das Ziel darf kein Nachfahre sein."
				);
			}

			$stmt = $this->pdo->prepare(
				"UPDATE `{$this->table}` SET parent_id = ?, sort_order = ? WHERE id = ?"
			);
			$stmt->execute([$new_parent_id, $this->next_sort_order($new_parent_id), $node_id]);
		});
	}

	/**
	 * Benennt einen Knoten um.
	 *
	 * @throws TreeException wenn der Knoten nicht existiert
	 */
	public function rename_node(int $node_id, string $name): void {
		$name = $this->clean_name($name);
		$this->ensure_table();
		$this->fetch_node($node_id);

		$stmt = $this->pdo->prepare("UPDATE `{$this->table}` SET name = ? WHERE id = ?");
		$stmt->execute([$name, $node_id]);
	}

	/**
	 * Schaltet die Sichtbarkeit eines Knotens. Offline-Knoten (und damit ihr
	 * ganzer Teilbaum) fehlen in get_subtree(..., online_only: true).
	 *
	 * @throws TreeException wenn der Knoten nicht existiert
	 */
	public function set_online(int $node_id, bool $online): void {
		$this->ensure_table();
		$this->fetch_node($node_id);

		$stmt = $this->pdo->prepare("UPDATE `{$this->table}` SET online = ? WHERE id = ?");
		$stmt->execute([$online ? 1 : 0, $node_id]);
	}
}
