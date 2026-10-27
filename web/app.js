// +---------------------------------------------------------------------+
// | Tree Editor – Frontend                                              |
// | Spricht mit api.php ({success, data, error}) und zeichnet den Baum  |
// | mit ECharts.                                                        |
// +---------------------------------------------------------------------+

'use strict';

const API_URL = 'api.php';
const LABEL_MAX_LENGTH = 18;       // senkrechter Baum (schräge Beschriftung)
const LABEL_MAX_LENGTH_WIDE = 28;  // waagerechter Baum
const LEAF_GAP_WIDE = 24;          // px je Blatt, waagerecht
const LEVEL_GAP_WIDE = 190;        // px je Ebene, waagerecht
const LEAF_GAP_TALL = 34;          // px je Blatt, senkrecht
const LEVEL_GAP_TALL = 130;        // px je Ebene, senkrecht
const ORIENTATION_KEY = 'nested_list_orientation';
const ZOOM_KEY = 'nested_list_zoom';
const ZOOM_MIN = 0.3;
const ZOOM_MAX = 2.5;
const ZOOM_STEP = 0.1;             // feste 10-%-Schritte je Klick bzw. Mausrad-Raste
const COOKIE_ACCEPTED_KEY = 'nested_list_cookie_accepted';

const state = {
	mode: null,           // 'demo' | 'admin'
	nodes: [],            // flache Knotenliste aus get_tree
	metaColumns: [],
	selectedNode: null,
	moveMode: false,
	busy: false,
	sessionRenewed: false,
	expiresAt: null,      // Unix-Zeit, nur Demo
	orientation: 'horizontal', // 'horizontal' (Standard) | 'vertical'
	scrollToRoot: true,   // nach dem ersten Laden und beim Umschalten
	zoom: 1,              // Vergrößerung der Zeichenfläche (1 = 100 %)
	dragged: false,       // letzte Mausaktion war ein Ziehen, kein Klick
};

/** Wert in Pixeln passend zur aktuellen Vergrößerung. */
const z = px => Math.round(px * state.zoom);

const $ = id => document.getElementById(id);
const css = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

// ── Hilfsfunktionen ─────────────────────────────────────────────────────

/** Maskiert Text für die Ausgabe in HTML (auch in Attributen). */
function escapeHtml(value) {
	return String(value ?? '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

function truncate(text, max) {
	const chars = Array.from(text); // zählt Emojis als ein Zeichen
	return chars.length > max ? chars.slice(0, max).join('') + '…' : text;
}

function storageGet(key) {
	try {
		return localStorage.getItem(key);
	} catch {
		return null;
	}
}

function storageSet(key, value) {
	try {
		localStorage.setItem(key, value);
	} catch {
		// privater Modus o. Ä. – dann fragt der Dialog eben beim nächsten Mal wieder
	}
}

let toastTimer;
function toast(message, type = 'ok') {
	const el = $('toast');
	el.textContent = message;
	el.className = 'show ' + type;
	clearTimeout(toastTimer);
	toastTimer = setTimeout(() => el.classList.remove('show'), 3500);
}

/**
 * Ruft eine API-Aktion per POST auf. Ist die Demo-Session abgelaufen
 * (HTTP 401), wird einmalig eine neue gestartet und der Aufruf wiederholt.
 */
async function api(action, params = {}, retry = true) {
	const body = new FormData();
	body.append('action', action);
	for (const [key, value] of Object.entries(params)) {
		if (value !== null && value !== undefined) body.append(key, value);
	}

	let response;
	try {
		response = await fetch(API_URL, {
			method: 'POST',
			body,
			headers: { 'X-Tree-Editor': '1' },
			credentials: 'same-origin',
		});
	} catch (e) {
		return { success: false, data: null, error: 'Netzwerkfehler: ' + e.message };
	}

	if (response.status === 401 && retry && state.mode === 'demo') {
		const init = await api('init_session', {}, false);
		if (!init.success) return init;
		applySession(init.data);
		state.sessionRenewed = true; // Hinweis zeigt loadTree(), sonst überschreibt ihn der nächste Toast
		return api(action, params, false);
	}

	try {
		return await response.json();
	} catch {
		return { success: false, data: null, error: `Ungültige Antwort vom Server (HTTP ${response.status}).` };
	}
}

/** Führt eine schreibende Aktion aus, zeigt Fehler an und lädt danach den Baum neu. */
async function mutate(action, params, successMessage) {
	if (state.busy) return null;
	state.busy = true;
	try {
		const res = await api(action, params);
		if (!res.success) {
			toast(res.error, 'err');
			return null;
		}
		if (successMessage) toast(successMessage, 'ok');
		await loadTree(false);
		return res;
	} finally {
		state.busy = false;
	}
}

// ── ECharts ─────────────────────────────────────────────────────────────

const chart = echarts.init($('chart'), null, { renderer: 'canvas' });
window.addEventListener('resize', () => renderChart());

function nodeLabel(params, maxLength) {
	const node = params.data.raw;
	return `${truncate(node.name, maxLength)}  (#${node.id})`;
}

function nodeTooltip(params) {
	const node = params.data.raw;
	let html = `<b>${escapeHtml(node.name)}</b>`
		+ `<br>ID: ${node.id} · parent: ${node.parent_id}`
		+ `<br>Kinder: ${node.child_count} · sort: ${node.sort_order}`;
	if (!node.online) html += '<br><i>offline</i>';

	const meta = Object.entries(node.meta || {}).filter(([, v]) => v !== null && v !== '');
	if (meta.length) {
		html += `<hr style="border:0;border-top:1px solid ${css('--border')};margin:6px 0">`
			+ meta.map(([k, v]) => `${escapeHtml(k)}: ${escapeHtml(truncate(String(v), 120))}`).join('<br>');
	}
	return html;
}

function buildTreeOption(nodes) {
	if (!nodes.length) return null;

	const map = new Map(nodes.map(n => [n.id, { ...n, children: [] }]));
	let root = null;
	for (const node of map.values()) {
		const parent = map.get(node.parent_id);
		if (parent) parent.children.push(node);
		else root = node;
	}
	if (!root) return null;

	const sortChildren = node => {
		node.children.sort((a, b) => a.sort_order - b.sort_order);
		node.children.forEach(sortChildren);
	};
	sortChildren(root);

	const toSeries = (node, parentOffline = false) => {
		const offline = parentOffline || !node.online;
		const selected = state.selectedNode && state.selectedNode.id === node.id;
		const hasChildren = node.child_count > 0;
		return {
			name: `${node.name} (#${node.id})`,
			raw: node,
			itemStyle: {
				color: selected ? css('--accent') : offline ? css('--node-offline') : hasChildren ? css('--node-parent') : css('--node'),
				borderColor: selected ? css('--accent-light') : hasChildren ? css('--node-parent-border') : css('--node-border'),
				borderWidth: selected ? 2 : 1,
				borderType: offline ? 'dashed' : 'solid',
			},
			label: {
				color: selected ? '#fff' : offline ? css('--muted') : '#c8d0e8',
				fontWeight: selected ? 700 : 400,
			},
			children: node.children.map(child => toSeries(child, offline)),
		};
	};

	return {
		backgroundColor: 'transparent',
		tooltip: {
			trigger: 'item',
			backgroundColor: css('--panel'),
			borderColor: css('--border'),
			textStyle: { color: css('--text'), fontFamily: css('--font-mono'), fontSize: 12 },
			formatter: nodeTooltip,
		},
		series: [{
			type: 'tree',
			data: [toSeries(root)],
			...(state.orientation === 'horizontal' ? horizontalLayout() : verticalLayout()),
			symbolSize: Math.max(6, z(16)),
			symbol: 'roundRect',
			expandAndCollapse: false,
			lineStyle: { color: css('--line'), width: 1.5, curveness: .5 },
			// Sicherheitsnetz: Beschriftungen, die sich trotzdem überdecken, ausblenden
			// (Name steht weiterhin im Tooltip und links nach dem Anklicken)
			labelLayout: { hideOverlap: true },
			animationDuration: 300,
			animationEasingUpdate: 'cubicOut',
			// ECharts selbst zoomt/zieht nicht: Die Zeichenfläche wird über
			// setZoom() vergrößert und über die Scrollbalken bewegt.
			roam: false,
		}],
	};
}

/** Waagerechter Baum: Wurzel links, Ebenen nach rechts, Beschriftung waagerecht. */
function horizontalLayout() {
	const label = {
		fontSize: Math.max(7, z(12)),
		fontFamily: css('--font-ui'),
		distance: z(6),
		formatter: params => nodeLabel(params, LABEL_MAX_LENGTH_WIDE),
	};
	return {
		orient: 'LR',
		top: z(30),
		bottom: z(30),
		left: z(140),
		right: z(220),
		label: { ...label, position: 'left', verticalAlign: 'middle', align: 'right' },
		leaves: { label: { ...label, position: 'right', verticalAlign: 'middle', align: 'left' } },
	};
}

/** Senkrechter Baum wie bisher: Wurzel oben, Beschriftung schräg. */
function verticalLayout() {
	const label = {
		fontSize: Math.max(7, z(12)),
		fontFamily: css('--font-ui'),
		distance: z(6),
		rotate: 45,
		formatter: params => nodeLabel(params, LABEL_MAX_LENGTH),
	};
	return {
		orient: 'TB',
		top: z(90),
		bottom: z(130),
		left: z(60),
		right: z(60),
		label: { ...label, position: 'top', verticalAlign: 'bottom', align: 'left' },
		leaves: { label: { ...label, position: 'bottom', verticalAlign: 'top', align: 'right' } },
	};
}

/**
 * Größe der Zeichenfläche passend zum Baum: Jedes Blatt bekommt eine feste
 * Zeile (waagerecht) bzw. Spalte (senkrecht), jede Ebene einen festen Abstand.
 * Ist der Baum kleiner als der sichtbare Bereich, füllt er ihn einfach aus;
 * ist er größer, erscheinen Scrollbalken statt überlappender Beschriftungen.
 */
function chartSize(nodes) {
	const main = document.querySelector('main');
	const leaves = Math.max(1, nodes.filter(n => n.child_count === 0).length);
	const depth = Math.max(0, ...nodes.map(n => n.level)) + 1;
	const needed = state.orientation === 'horizontal'
		? { width: z((depth - 1) * LEVEL_GAP_WIDE + 360), height: z(leaves * LEAF_GAP_WIDE + 60) }
		: { width: z(leaves * LEAF_GAP_TALL + 120), height: z((depth - 1) * LEVEL_GAP_TALL + 220) };
	return {
		width: Math.max(main.clientWidth, needed.width),
		height: Math.max(main.clientHeight, needed.height),
	};
}

function renderChart(replace = false) {
	const option = buildTreeOption(state.nodes);
	if (!option) {
		chart.clear();
		return;
	}
	const size = chartSize(state.nodes);
	const el = $('chart');
	if (el.offsetWidth !== size.width || el.offsetHeight !== size.height) {
		el.style.width = size.width + 'px';
		el.style.height = size.height + 'px';
		chart.resize();
	}
	chart.setOption(option, replace);

	if (state.scrollToRoot) {
		state.scrollToRoot = false;
		chart.on('finished', function scrollOnce() {
			chart.off('finished', scrollOnce);
			scrollToRoot();
		});
	}
}

/** Scrollt den Baumbereich so, dass der Root-Knoten sichtbar ist. */
function scrollToRoot() {
	const main = document.querySelector('main');
	// tree.root ist ein unsichtbarer Hilfsknoten von ECharts, sein erstes Kind der echte Root
	const root = chart.getModel().getSeriesByIndex(0)?.getData().tree.root.children[0];
	const layout = root && root.getLayout();
	if (!layout) return;
	if (state.orientation === 'horizontal') {
		main.scrollTo({ left: 0, top: Math.max(0, layout.y - main.clientHeight / 2) });
	} else {
		main.scrollTo({ top: 0, left: Math.max(0, layout.x - main.clientWidth / 2) });
	}
}

/**
 * Ändert die Vergrößerung. Mit Mausposition (clientX/clientY) bleibt die
 * Stelle unter der Maus stehen, sonst die Mitte des sichtbaren Bereichs.
 */
function setZoom(zoom, clientX = null, clientY = null) {
	// auf ganze 10 % runden, damit keine krummen Werte entstehen
	zoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Math.round(zoom * 10) / 10));
	const main = document.querySelector('main');
	const rect = main.getBoundingClientRect();
	const offsetX = clientX === null ? main.clientWidth / 2 : clientX - rect.left;
	const offsetY = clientY === null ? main.clientHeight / 2 : clientY - rect.top;
	const el = $('chart');
	const ratioX = (main.scrollLeft + offsetX) / Math.max(1, el.offsetWidth);
	const ratioY = (main.scrollTop + offsetY) / Math.max(1, el.offsetHeight);

	state.zoom = zoom;
	storageSet(ZOOM_KEY, String(zoom));
	$('btn-zoom-reset').textContent = Math.round(zoom * 100) + ' %';
	renderChart();

	main.scrollLeft = ratioX * el.offsetWidth - offsetX;
	main.scrollTop = ratioY * el.offsetHeight - offsetY;
}

function setOrientation(orientation) {
	state.orientation = orientation === 'vertical' ? 'vertical' : 'horizontal';
	storageSet(ORIENTATION_KEY, state.orientation);
	state.scrollToRoot = true;
	$('btn-orientation').textContent = state.orientation === 'horizontal' ? '⇅ Senkrecht' : '⇄ Waagerecht';
	$('btn-orientation').title = state.orientation === 'horizontal'
		? 'Baum von oben nach unten zeichnen'
		: 'Baum von links nach rechts zeichnen';
	renderChart(true);
}

chart.on('click', params => {
	// Ein Klick am Ende eines Ziehens wählt keinen Knoten aus
	if (state.dragged) {
		state.dragged = false;
		return;
	}
	const node = params.data && params.data.raw;
	if (!node) return;
	if (state.moveMode) {
		if (node.id === state.selectedNode.id) {
			toast('Ein Knoten kann nicht unter sich selbst verschoben werden.', 'err');
			return;
		}
		moveNode(state.selectedNode.id, node.id);
	} else {
		selectNode(node);
	}
});

// ── Laden ───────────────────────────────────────────────────────────────

async function loadTree(announce = true) {
	const res = await api('get_tree');
	if (!res.success) {
		toast(res.error, 'err');
		return;
	}

	state.nodes = res.data.nodes || [];
	state.metaColumns = res.data.meta_columns || [];
	renderColumnSelect();

	// Auswahl mit frischen Daten aktualisieren oder aufheben
	const selectedId = state.selectedNode && state.selectedNode.id;
	const updated = state.nodes.find(n => n.id === selectedId);
	if (updated) selectNode(updated, false);
	else clearSelection(false);

	renderChart();

	if (state.sessionRenewed) {
		state.sessionRenewed = false;
		toast('Deine Demo-Session war abgelaufen – du hast eine neue mit dem Beispielbaum bekommen.', 'inf');
	} else if (!state.nodes.length) {
		toast('Der Baum ist leer – leg zuerst einen Root-Knoten an.', 'inf');
	} else if (announce) {
		toast(`Baum geladen (${state.nodes.length} Knoten).`, 'ok');
	}
}

function applySession(data) {
	state.mode = data.mode;
	state.expiresAt = data.expires_at || null;
	renderSessionInfo();
}

function renderSessionInfo() {
	const el = $('session-info');
	if (state.mode === 'demo' && state.expiresAt) {
		const time = new Date(state.expiresAt * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
		el.innerHTML = `Demo · deine Daten werden um <strong>${escapeHtml(time)}</strong> Uhr gelöscht`;
	} else if (state.mode === 'admin') {
		el.textContent = 'Admin-Modus';
	} else {
		el.textContent = '';
	}
}

// ── Auswahl ─────────────────────────────────────────────────────────────

function selectNode(node, redraw = true) {
	state.selectedNode = node;
	const isRoot = node.parent_id === 0;

	$('sel-name').textContent = node.name;
	$('sel-id').textContent = node.id;
	$('sel-parent').textContent = node.parent_id;
	$('sel-sort').textContent = node.sort_order;
	$('sel-children').textContent = node.child_count;
	$('sel-online').checked = node.online;
	$('sel-online').disabled = false;

	$('edit-name').value = node.name;
	$('edit-name').disabled = false;
	$('btn-save-name').disabled = false;
	$('btn-delete').disabled = false;
	$('btn-move-start').disabled = isRoot;
	$('btn-up').disabled = !node.has_prev_sibling;
	$('btn-down').disabled = !node.has_next_sibling;
	$('new-parent').placeholder = `automatisch (= #${node.id})`;

	renderMetaFields(node);
	if (redraw) renderChart();
}

function clearSelection(redraw = true) {
	state.selectedNode = null;
	$('sel-name').textContent = '— nichts ausgewählt —';
	['sel-id', 'sel-parent', 'sel-sort', 'sel-children'].forEach(id => $(id).textContent = '–');
	$('sel-online').checked = false;
	$('sel-online').disabled = true;
	['btn-delete', 'btn-move-start', 'btn-up', 'btn-down', 'btn-save-name', 'btn-save-meta']
		.forEach(id => $(id).disabled = true);
	$('edit-name').value = '';
	$('edit-name').disabled = true;
	$('new-parent').placeholder = 'automatisch';
	$('meta-node-name').textContent = 'Wähl einen Knoten aus …';
	renderMetaFields(null);
	if (redraw) renderChart();
}

// ── Meta-Daten ──────────────────────────────────────────────────────────

function renderMetaFields(node) {
	const container = $('meta-fields');
	container.replaceChildren();

	if (node) $('meta-node-name').textContent = `#${node.id} · ${node.name}`;
	$('btn-save-meta').disabled = !node || state.metaColumns.length === 0;

	if (state.metaColumns.length === 0) {
		const empty = document.createElement('div');
		empty.className = 'meta-empty';
		empty.textContent = 'Noch keine Meta-Spalten angelegt.';
		container.append(empty);
		return;
	}
	if (!node) return;

	const meta = node.meta || {};
	for (const column of state.metaColumns) {
		const field = document.createElement('div');
		field.className = 'meta-field';

		const label = document.createElement('label');
		label.className = 'meta-field-label';
		label.textContent = column;

		const textarea = document.createElement('textarea');
		textarea.rows = 2;
		textarea.dataset.column = column;
		textarea.value = meta[column] ?? '';

		const id = 'meta-' + column;
		textarea.id = id;
		label.htmlFor = id;

		field.append(label, textarea);
		container.append(field);
	}
}

function renderColumnSelect() {
	const select = $('delete-column-select');
	select.replaceChildren(new Option('— wählen —', ''));
	state.metaColumns.forEach(column => select.append(new Option(column, column)));
}

async function saveMeta() {
	if (!state.selectedNode) return;
	const data = {};
	document.querySelectorAll('#meta-fields textarea[data-column]').forEach(ta => {
		data[ta.dataset.column] = ta.value;
	});
	if (!Object.keys(data).length) {
		toast('Es gibt keine Meta-Felder.', 'inf');
		return;
	}
	await mutate('update_meta', { node_id: state.selectedNode.id, data: JSON.stringify(data) }, 'Meta-Daten gespeichert.');
}

async function addMetaColumn() {
	const input = $('new-column');
	const column = input.value.trim();
	if (!/^[A-Za-z_][A-Za-z0-9_]{0,63}$/.test(column)) {
		toast('Ungültiger Spaltenname: nur Buchstaben, Ziffern und _, nicht mit Ziffer beginnen.', 'err');
		return;
	}
	const res = await mutate('add_meta_column', { column });
	if (!res) return;
	input.value = '';
	toast(res.data.column === column
		? `Spalte „${column}“ angelegt.`
		: `Die Spalte gibt es schon als „${res.data.column}“.`, res.data.column === column ? 'ok' : 'inf');
}

async function deleteMetaColumn() {
	const column = $('delete-column-select').value;
	if (!column) {
		toast('Wähl zuerst eine Spalte aus.', 'err');
		return;
	}
	if (!confirm(`Spalte „${column}“ mit allen Werten endgültig löschen?`)) return;
	await mutate('delete_meta_column', { column }, `Spalte „${column}“ gelöscht.`);
}

// ── Knoten-Aktionen ─────────────────────────────────────────────────────

async function renameNode() {
	const node = state.selectedNode;
	if (!node) return;
	const name = $('edit-name').value.trim();
	if (!name) {
		toast('Der Name darf nicht leer sein.', 'err');
		return;
	}
	if (name === node.name) {
		toast('Keine Änderung.', 'inf');
		return;
	}
	await mutate('rename_node', { node_id: node.id, name }, `Umbenannt in „${name}“.`);
}

async function setOnline(online) {
	const node = state.selectedNode;
	if (!node) return;
	const res = await mutate('set_online', { node_id: node.id, online: online ? '1' : '0' },
		online ? `„${node.name}“ ist jetzt online.` : `„${node.name}“ ist jetzt offline.`);
	if (!res) $('sel-online').checked = node.online; // Häkchen zurücksetzen
}

async function insertNode() {
	const name = $('new-name').value.trim();
	if (!name) {
		toast('Gib einen Namen ein.', 'err');
		$('new-name').focus();
		return;
	}

	const manual = $('new-parent').value.trim();
	let parentId = null;
	if (manual !== '') {
		if (!/^\d+$/.test(manual)) {
			toast('Die Eltern-ID muss eine Zahl sein.', 'err');
			return;
		}
		parentId = Number(manual);
	} else if (state.selectedNode) {
		parentId = state.selectedNode.id;
	} else if (state.nodes.length) {
		toast('Wähl einen Elternknoten aus oder gib eine Eltern-ID ein.', 'err');
		return;
	}

	const res = await mutate('insert_node', { name, parent_id: parentId ?? '' }, `„${name}“ eingefügt.`);
	if (!res) return;
	$('new-name').value = '';
	$('new-name').focus();
}

async function deleteNode() {
	const node = state.selectedNode;
	if (!node) return;
	const question = node.child_count > 0
		? `„${node.name}“ samt ganzem Teilbaum löschen?`
		: `„${node.name}“ löschen?`;
	if (!confirm(question)) return;

	const res = await mutate('delete_node', { node_id: node.id });
	if (res) toast(`${res.data.deleted} Knoten gelöscht.`, 'ok');
}

async function reorderNode(direction) {
	const node = state.selectedNode;
	if (!node) return;
	await mutate('reorder_node', { node_id: node.id, direction }, 'Reihenfolge geändert.');
}

function startMove() {
	if (!state.selectedNode) return;
	state.moveMode = true;
	$('move-overlay').classList.add('active');
	$('move-hint').classList.add('active');
}

function cancelMove() {
	state.moveMode = false;
	$('move-overlay').classList.remove('active');
	$('move-hint').classList.remove('active');
}

async function moveNode(nodeId, newParentId) {
	cancelMove();
	await mutate('move_node', { node_id: nodeId, new_parent_id: newParentId }, 'Knoten verschoben.');
}

// ── Eltern-Auswahl mit Vorschlägen ──────────────────────────────────────

function filterParentDropdown(value) {
	const dropdown = $('node-dropdown');
	const query = value.trim().toLowerCase();
	if (!query) {
		closeParentDropdown();
		return;
	}

	const matches = state.nodes
		.filter(n => String(n.id).startsWith(query) || n.name.toLowerCase().includes(query))
		.slice(0, 20);
	if (!matches.length) {
		closeParentDropdown();
		return;
	}

	dropdown.replaceChildren(...matches.map(node => {
		const item = document.createElement('div');
		item.className = 'dropdown-item';
		item.textContent = `#${node.id} · ${node.name}`;
		item.addEventListener('mousedown', () => {
			$('new-parent').value = node.id;
			closeParentDropdown();
		});
		return item;
	}));
	dropdown.classList.add('open');
}

function closeParentDropdown() {
	$('node-dropdown').classList.remove('open');
}

// ── Start ───────────────────────────────────────────────────────────────

async function startSession() {
	const res = await api('init_session', {}, false);
	if (!res.success) {
		toast(res.error, 'err');
		return;
	}
	applySession(res.data);
	await loadTree();
}

async function init() {
	const status = await api('get_status', {}, false);
	if (!status.success) {
		toast(status.error, 'err');
		return;
	}
	applySession(status.data);

	if (status.data.mode === 'demo' && !status.data.session && storageGet(COOKIE_ACCEPTED_KEY) !== '1') {
		$('cookie-ttl').textContent = String(status.data.ttl_hours ?? 2).replace('.', ',');
		$('cookie-modal').classList.add('active');
		$('btn-cookie-accept').focus();
		return;
	}
	await startSession();
}

/**
 * Baum mit gedrückter linker Maustaste verschieben (scrollt den Baumbereich).
 * Erst ab DRAG_THRESHOLD Pixeln Bewegung gilt es als Ziehen – ein normaler
 * Klick auf einen Knoten wählt ihn weiter aus.
 */
const DRAG_THRESHOLD = 4;

function bindDragToScroll(area) {
	let drag = null;

	area.addEventListener('mousedown', e => {
		if (e.button !== 0 || e.target.closest('button')) return;
		state.dragged = false;
		drag = { x: e.clientX, y: e.clientY, left: area.scrollLeft, top: area.scrollTop, moving: false };
	});

	window.addEventListener('mousemove', e => {
		if (!drag) return;
		const dx = e.clientX - drag.x;
		const dy = e.clientY - drag.y;
		if (!drag.moving && Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
		drag.moving = true;
		area.classList.add('dragging');
		area.scrollLeft = drag.left - dx;
		area.scrollTop = drag.top - dy;
		e.preventDefault();
	});

	window.addEventListener('mouseup', () => {
		if (!drag) return;
		state.dragged = drag.moving;
		drag = null;
		area.classList.remove('dragging');
	});
}

function bindEvents() {
	$('btn-reload').addEventListener('click', () => loadTree());
	$('btn-zoom-in').addEventListener('click', () => setZoom(state.zoom + ZOOM_STEP));
	$('btn-zoom-out').addEventListener('click', () => setZoom(state.zoom - ZOOM_STEP));
	$('btn-zoom-reset').addEventListener('click', () => setZoom(1));
	bindDragToScroll(document.querySelector('main'));
	// Strg + Mausrad zoomt (statt der ganzen Seite), das Mausrad allein scrollt
	document.querySelector('main').addEventListener('wheel', e => {
		if (!e.ctrlKey) return;
		e.preventDefault();
		setZoom(e.deltaY < 0 ? state.zoom + ZOOM_STEP : state.zoom - ZOOM_STEP, e.clientX, e.clientY);
	}, { passive: false });
	$('btn-orientation').addEventListener('click', () => {
		setOrientation(state.orientation === 'horizontal' ? 'vertical' : 'horizontal');
	});
	$('btn-save-name').addEventListener('click', renameNode);
	$('sel-online').addEventListener('change', e => setOnline(e.target.checked));
	$('btn-up').addEventListener('click', () => reorderNode('up'));
	$('btn-down').addEventListener('click', () => reorderNode('down'));
	$('btn-move-start').addEventListener('click', startMove);
	$('btn-move-cancel').addEventListener('click', cancelMove);
	$('btn-delete').addEventListener('click', deleteNode);
	$('btn-insert').addEventListener('click', insertNode);
	$('btn-save-meta').addEventListener('click', saveMeta);
	$('btn-add-column').addEventListener('click', addMetaColumn);
	$('btn-delete-column').addEventListener('click', deleteMetaColumn);

	$('btn-cookie-accept').addEventListener('click', () => {
		storageSet(COOKIE_ACCEPTED_KEY, '1');
		$('cookie-modal').classList.remove('active');
		startSession();
	});

	$('edit-name').addEventListener('keydown', e => {
		if (e.key === 'Enter') renameNode();
	});
	$('new-column').addEventListener('keydown', e => {
		if (e.key === 'Enter') addMetaColumn();
	});
	$('new-name').addEventListener('keydown', e => {
		if (e.key === 'Enter') insertNode();
	});

	const parentInput = $('new-parent');
	parentInput.addEventListener('input', e => filterParentDropdown(e.target.value));
	parentInput.addEventListener('blur', () => setTimeout(closeParentDropdown, 150));
	parentInput.addEventListener('keydown', e => {
		if (e.key === 'Enter') {
			closeParentDropdown();
			insertNode();
		}
		if (e.key === 'Escape') closeParentDropdown();
	});

	document.addEventListener('keydown', e => {
		if (e.key === 'Escape' && state.moveMode) cancelMove();
	});
}

bindEvents();
state.zoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Math.round((Number(storageGet(ZOOM_KEY)) || 1) * 10) / 10));
$('btn-zoom-reset').textContent = Math.round(state.zoom * 100) + ' %';
setOrientation(storageGet(ORIENTATION_KEY) || 'horizontal');
clearSelection(false);
init();
