<?php
// +---------------------------------------------------------------------+
// | Klasse MetaTree                                                     |
// | Erweitert AdjacencyList um frei definierbare Zusatzfelder je Knoten |
// +---------------------------------------------------------------------+
// | Die Felder liegen in einer 1:1-Tabelle <tabelle>_meta               |
// | (id, node_id UNIQUE, FK mit ON DELETE CASCADE). Neue Spalten        |
// | entstehen bei Bedarf per ALTER TABLE … ADD COLUMN … TEXT.           |
// | Die Systemspalten id und node_id sind geschützt.                    |
// +---------------------------------------------------------------------+

declare(strict_types=1);

require_once __DIR__ . '/AdjacencyList.php';

class MetaTree extends AdjacencyList {

	public const VERSION = '2.0.0';

	/** Systemspalten der Meta-Tabelle – nie anlegen, ändern oder löschen. */
	protected const PROTECTED_COLUMNS = ['id', 'node_id'];

	/** Erlaubte Spaltennamen (MySQL-Limit 64 Zeichen). */
	protected const COLUMN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

	/** Suffix der Meta-Tabelle: kategorien → kategorien_meta */
	protected const META_SUFFIX = '_meta';

	private bool $meta_checked = false;

	/** Cache der Spaltennamen der Meta-Tabelle (inkl. Systemspalten). */
	private ?array $column_cache = null;

	// -------------------------------------------------------------------------
	// Interne Hilfsmethoden
	// -------------------------------------------------------------------------

	protected function meta_table(): string {
		return $this->table . self::META_SUFFIX;
	}

	/**
	 * Legt die Meta-Tabelle an, falls sie fehlt. Läuft pro Instanz nur einmal.
	 * Wie ensure_table(): wegen implizitem Commit nie in einer Transaktion aufrufen.
	 */
	protected function ensure_meta_table(): void {
		if ($this->meta_checked) {
			return;
		}
		$this->ensure_table();

		$meta = $this->meta_table();
		if (!$this->table_exists($meta)) {
			$this->pdo->exec("
				CREATE TABLE `{$meta}` (
					`id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`node_id` INT UNSIGNED NOT NULL UNIQUE,
					CONSTRAINT `fk_{$meta}_node`
						FOREIGN KEY (`node_id`) REFERENCES `{$this->table}` (`id`) ON DELETE CASCADE
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
			");
			$this->column_cache = null;
		}
		$this->meta_checked = true;
	}

	/** Alle Spalten der Meta-Tabelle inkl. id und node_id. */
	private function all_meta_columns(): array {
		if ($this->column_cache === null) {
			$this->column_cache = $this->table_columns($this->meta_table());
		}
		return $this->column_cache;
	}

	private function is_protected(string $column): bool {
		return in_array(strtolower($column), self::PROTECTED_COLUMNS, true);
	}

	/**
	 * Sucht eine vorhandene Meta-Spalte ohne Rücksicht auf Groß-/Kleinschreibung
	 * (MySQL unterscheidet bei Spaltennamen nicht) und gibt ihren echten Namen
	 * zurück, sonst null.
	 */
	private function find_column(string $column): ?string {
		foreach ($this->all_meta_columns() as $existing) {
			if (strcasecmp($existing, $column) === 0) {
				return $existing;
			}
		}
		return null;
	}

	/** @throws InvalidArgumentException bei ungültigem oder geschütztem Namen */
	private function validate_column(string $column): void {
		if (!preg_match(self::COLUMN_PATTERN, $column)) {
			throw new InvalidArgumentException(
				"Ungültiger Spaltenname „{$column}“. Erlaubt sind Buchstaben, Ziffern und _ (max. 64 Zeichen, nicht mit Ziffer beginnen)."
			);
		}
		if ($this->is_protected($column)) {
			throw new InvalidArgumentException("„{$column}“ ist eine Systemspalte und nicht erlaubt.");
		}
	}

	// -------------------------------------------------------------------------
	// Spalten
	// -------------------------------------------------------------------------

	/**
	 * Namen aller Meta-Spalten ohne Systemspalten, in Tabellenreihenfolge.
	 *
	 * @return string[]
	 */
	public function get_meta_columns(): array {
		$this->ensure_meta_table();
		return array_values(array_filter(
			$this->all_meta_columns(),
			fn(string $c): bool => !$this->is_protected($c)
		));
	}

	/**
	 * Legt eine Meta-Spalte an (Typ TEXT NULL). Gibt den tatsächlichen
	 * Spaltennamen zurück – existiert die Spalte schon (auch in anderer
	 * Schreibweise), wird deren Name geliefert und nichts geändert.
	 */
	public function add_meta_column(string $column): string {
		$column = trim($column);
		$this->validate_column($column);
		$this->ensure_meta_table();

		$existing = $this->find_column($column);
		if ($existing !== null) {
			return $existing;
		}

		$this->pdo->exec("ALTER TABLE `{$this->meta_table()}` ADD COLUMN `{$column}` TEXT NULL");
		$this->column_cache = null;
		return $column;
	}

	/**
	 * Löscht eine Meta-Spalte samt aller Werte.
	 *
	 * @throws InvalidArgumentException bei Systemspalten
	 * @throws TreeException wenn die Spalte nicht existiert
	 */
	public function delete_meta_column(string $column): void {
		$this->validate_column($column);
		$this->ensure_meta_table();

		$existing = $this->find_column($column);
		if ($existing === null) {
			throw new TreeException("Die Spalte „{$column}“ gibt es nicht.");
		}

		$this->pdo->exec("ALTER TABLE `{$this->meta_table()}` DROP COLUMN `{$existing}`");
		$this->column_cache = null;
	}

	// -------------------------------------------------------------------------
	// Werte
	// -------------------------------------------------------------------------

	/**
	 * Teilbaum wie get_subtree(), jeder Knoten zusätzlich mit dem Schlüssel
	 * 'meta' => ['spalte' => wert|null, …] für alle Meta-Spalten.
	 */
	public function get_subtree_with_meta(int $node_id, bool $online_only = false): array {
		$this->ensure_meta_table();
		$nodes = $this->get_subtree($node_id, $online_only);
		if (!$nodes) {
			return [];
		}

		$columns = $this->get_meta_columns();
		$empty   = array_fill_keys($columns, null);
		if (!$columns) {
			return array_map(fn(array $n): array => $n + ['meta' => []], $nodes);
		}

		$ids          = array_column($nodes, 'id');
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$col_list     = implode(', ', array_map(fn(string $c): string => "`{$c}`", $columns));

		$stmt = $this->pdo->prepare(
			"SELECT node_id, {$col_list} FROM `{$this->meta_table()}` WHERE node_id IN ({$placeholders})"
		);
		$stmt->execute($ids);

		$meta_rows = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$id = (int)$row['node_id'];
			unset($row['node_id']);
			$meta_rows[$id] = $row;
		}

		return array_map(
			fn(array $n): array => $n + ['meta' => $meta_rows[$n['id']] ?? $empty],
			$nodes
		);
	}

	/**
	 * Meta-Werte eines Knotens; fehlende Werte sind null.
	 *
	 * @throws TreeException wenn der Knoten nicht existiert
	 */
	public function get_meta(int $node_id): array {
		$this->ensure_meta_table();
		$this->fetch_node($node_id);

		$columns = $this->get_meta_columns();
		if (!$columns) {
			return [];
		}
		$col_list = implode(', ', array_map(fn(string $c): string => "`{$c}`", $columns));
		$stmt = $this->pdo->prepare("SELECT {$col_list} FROM `{$this->meta_table()}` WHERE node_id = ?");
		$stmt->execute([$node_id]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: array_fill_keys($columns, null);
	}

	/**
	 * Speichert Meta-Werte eines Knotens. Unbekannte Spalten werden angelegt.
	 * Erlaubt sind nur skalare Werte und null; true/false wird als '1'/'0'
	 * gespeichert, Zahlen als Text.
	 *
	 * @param array<string, string|int|float|bool|null> $data
	 * @throws InvalidArgumentException bei ungültigen Spalten oder Werten
	 * @throws TreeException wenn der Knoten nicht existiert
	 */
	public function update_meta(int $node_id, array $data): void {
		if (!$data) {
			return;
		}

		// Erst alles prüfen, dann schreiben – keine halben Änderungen
		$values = [];
		foreach ($data as $column => $value) {
			$column = trim((string)$column);
			$this->validate_column($column);
			if ($value !== null && !is_scalar($value)) {
				throw new InvalidArgumentException("Wert für „{$column}“ muss Text, Zahl oder leer sein.");
			}
			$values[$column] = is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string)$value);
		}

		$this->ensure_meta_table();
		$this->fetch_node($node_id);

		// Spalten anlegen (DDL, deshalb außerhalb einer Transaktion) und echte Namen holen
		$row = [];
		foreach ($values as $column => $value) {
			$row[$this->add_meta_column($column)] = $value;
		}

		$columns      = array_keys($row);
		$col_list     = implode(', ', array_map(fn(string $c): string => "`{$c}`", $columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		// VALUES() ist ab MySQL 8.0.20 als veraltet markiert, die Alias-Syntax
		// (… AS new ON DUPLICATE KEY UPDATE c = new.c) kann MariaDB aber nicht.
		// Deshalb bleibt es vorerst bei VALUES(), das beide Systeme verstehen.
		$updates = implode(', ', array_map(fn(string $c): string => "`{$c}` = VALUES(`{$c}`)", $columns));

		$stmt = $this->pdo->prepare(
			"INSERT INTO `{$this->meta_table()}` (node_id, {$col_list})
			 VALUES (?, {$placeholders})
			 ON DUPLICATE KEY UPDATE {$updates}"
		);
		$stmt->execute([$node_id, ...array_values($row)]);
	}

	/**
	 * Entfernt Meta-Zeilen ohne zugehörigen Knoten. Die gab es bis Version 1.x,
	 * weil del_knoten() mit FOREIGN_KEY_CHECKS = 0 den CASCADE ausgehebelt hat.
	 * Einmalig nach dem Umstieg aufrufen; danach sollte das Ergebnis immer 0 sein.
	 *
	 * @return int Anzahl entfernter Zeilen
	 */
	public function purge_orphaned_meta(): int {
		$this->ensure_meta_table();
		return (int)$this->pdo->exec(
			"DELETE m FROM `{$this->meta_table()}` m
			   LEFT JOIN `{$this->table}` t ON t.id = m.node_id
			  WHERE t.id IS NULL"
		);
	}
}
