<?php
// +---------------------------------------------------------------------+
// | TreeException                                                       |
// | Fachliche Fehler der Baumklassen (Knoten fehlt, Root existiert, …). |
// | Die Meldung ist für Endnutzer gedacht und darf angezeigt werden –   |
// | im Gegensatz zu PDOException, die interne Details enthalten kann.   |
// +---------------------------------------------------------------------+

declare(strict_types=1);

class TreeException extends RuntimeException {
}
