(function(){
  'use strict';

  function getSortValue(row, key){
    if (!row || !key) return '';
    const attr = row.getAttribute(`data-sort-${key}`);
    return attr !== null ? attr : '';
  }

  function enableColumnResizing(table, options){
    if (!table) return;
    const colgroup = table.querySelector('colgroup');
    if (!colgroup) return;
    const cols = Array.from(colgroup.children);
    const headers = Array.from(table.querySelectorAll('thead th'));
    if (!headers.length) return;
    const minWidth = (options && options.minColumnWidth) || 56;
    const doubleClickBuffer = (options && typeof options.resizeEdgeBuffer === 'number')
      ? Math.max(2, options.resizeEdgeBuffer)
      : 6;
    const doubleClickDelay = (options && typeof options.resizeDoubleClickDelay === 'number')
      ? Math.max(200, options.resizeDoubleClickDelay)
      : 400;
    const parsePx = (value) => {
      const num = parseFloat(value);
      return Number.isNaN(num) ? 0 : num;
    };

    function gatherColumnCells(colIndex) {
      const cells = [];
      if (headers[colIndex]) cells.push(headers[colIndex]);
      Array.from(table.tBodies || []).forEach((tbody) => {
        Array.from(tbody.rows || []).forEach((row) => {
          if (row.dataset && row.dataset.noMatches === '1') return;
          const cell = row.cells && row.cells[colIndex];
          if (!cell) return;
          if (cell.offsetParent === null) return;
          cells.push(cell);
        });
      });
      return cells;
    }

    function autoSizeColumn(colIndex) {
      const columnCells = gatherColumnCells(colIndex);
      if (!columnCells.length) return;
      const col = cols[colIndex] || null;
      const headerCell = headers[colIndex];

      if (col) {
        col.style.width = '';
        col.style.minWidth = '';
      }
      if (headerCell) {
        headerCell.style.width = '';
        headerCell.style.minWidth = '';
      }
      table.getBoundingClientRect();

      let targetWidth = 0;
      columnCells.forEach((cell) => {
        const cellStyle = window.getComputedStyle(cell);
        const borders = parsePx(cellStyle.borderLeftWidth) + parsePx(cellStyle.borderRightWidth);
        const width = cell.scrollWidth + borders;
        targetWidth = Math.max(targetWidth, Math.ceil(width));
      });

      targetWidth = Math.max(minWidth, targetWidth || 0);

      if (col) {
        col.style.width = `${targetWidth}px`;
        col.style.minWidth = `${targetWidth}px`;
      }
      if (headerCell) {
        headerCell.style.width = `${targetWidth}px`;
        headerCell.style.minWidth = `${targetWidth}px`;
      }
    }

    let lastHandleClickTime = 0;
    let lastHandleClickIndex = -1;

    function beginResize(ev, columnIndex) {
      const header = headers[columnIndex];
      if (!header) return;
      if (ev.button !== 0) return;

      const now = ev.timeStamp || Date.now();
      const isDoubleClick =
        lastHandleClickIndex === columnIndex &&
        (now - lastHandleClickTime) <= doubleClickDelay;
      lastHandleClickTime = now;
      lastHandleClickIndex = columnIndex;

      if (isDoubleClick || (ev.detail && ev.detail > 1)) {
        ev.preventDefault();
        ev.stopPropagation();
        autoSizeColumn(columnIndex);
        lastHandleClickTime = 0;
        lastHandleClickIndex = -1;
        return;
      }

      ev.preventDefault();
      ev.stopPropagation();

      const startX = ev.clientX;
      const col = cols[columnIndex] || null;
      const rect = header.getBoundingClientRect();
      const startWidth = col && col.style.width
        ? parseFloat(col.style.width)
        : rect.width;

      const applyWidth = (width) => {
        if (col) {
          col.style.width = `${width}px`;
          col.style.minWidth = `${width}px`;
        }
        header.style.width = `${width}px`;
        header.style.minWidth = `${width}px`;
      };

      function onMove(e){
        const delta = e.clientX - startX;
        const newWidth = Math.max(minWidth, startWidth + delta);
        applyWidth(newWidth);
      }

      function onUp(){
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
      }

      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
      document.body.style.cursor = 'col-resize';
      document.body.style.userSelect = 'none';
    }

    headers.forEach((th, index) => {
      if (th.querySelector('.col-resize-handle')) return;
      th.style.position = th.style.position || 'relative';
      const handle = document.createElement('span');
      handle.className = 'col-resize-handle';
      if (!handle.style.touchAction) handle.style.touchAction = 'none';
      if (!handle.style.userSelect) handle.style.userSelect = 'none';
      if (!handle.style.zIndex) handle.style.zIndex = '5';
      if (!handle.style.position) handle.style.position = 'absolute';
      if (!handle.style.top) handle.style.top = '0';
      if (!handle.style.bottom) handle.style.bottom = '0';
      if (!handle.style.right) handle.style.right = '-3px';
      if (!handle.style.width) handle.style.width = '8px';
      handle.setAttribute('role', 'separator');
      handle.setAttribute('aria-hidden', 'true');
      handle.tabIndex = -1;
      th.appendChild(handle);

      handle.addEventListener('mousedown', (ev) => {
        beginResize(ev, index);
      });
      handle.addEventListener('dblclick', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        autoSizeColumn(index);
      });

      th.addEventListener('mousedown', (ev) => {
        if (ev.button !== 0) return;
        if (ev.target && ev.target.closest('.col-resize-handle')) return;
        const rect = th.getBoundingClientRect();
        const offsetX = ev.clientX - rect.left;
        const distanceLeft = offsetX;
        const distanceRight = rect.width - offsetX;
        if (distanceRight <= doubleClickBuffer) {
          beginResize(ev, index);
        } else if (distanceLeft <= doubleClickBuffer && index > 0) {
          beginResize(ev, index - 1);
        }
      });

      th.addEventListener('dblclick', (ev) => {
        if (ev.target && ev.target.closest('.col-resize-handle')) return;
        const rect = th.getBoundingClientRect();
        const offsetX = ev.clientX - rect.left;
        const distanceLeft = offsetX;
        const distanceRight = rect.width - offsetX;
        if (distanceRight <= doubleClickBuffer) {
          ev.preventDefault();
          ev.stopPropagation();
          autoSizeColumn(index);
        } else if (distanceLeft <= doubleClickBuffer && index > 0) {
          ev.preventDefault();
          ev.stopPropagation();
          autoSizeColumn(index - 1);
        }
      });
    });
  }

  function enhanceTable(tableOrSelector, opts){
    const table = typeof tableOrSelector === 'string'
      ? document.querySelector(tableOrSelector)
      : tableOrSelector;
    if (!table) return null;

    const options = Object.assign({
      rowSelector: 'tbody tr',
      sortTypes: {},
      defaultSort: null,
      expansionSelector: '.expand',
      noMatchesText: 'No matching records.',
      noMatchesClass: 'muted',
      noMatchesPadding: '18px'
    }, opts || {});

    const tbody = table.querySelector('tbody');
    if (!tbody) return null;

    const rows = Array.from(tbody.querySelectorAll(options.rowSelector));
    if (!rows.length) {
      enableColumnResizing(table, options);
      return null;
    }

    const expansions = new Map();
    rows.forEach(row => {
      let exp = null;
      if (typeof options.getExpansion === 'function') {
        exp = options.getExpansion(row) || null;
      } else if (options.expansionSelector) {
        const next = row.nextElementSibling;
        if (next && next.matches(options.expansionSelector)) {
          exp = next;
        }
      }
      if (exp) expansions.set(row, exp);
    });

    const originalOrder = rows.map((row, index) => ({
      row,
      exp: expansions.get(row) || null,
      order: index
    }));

    const sortButtons = Array.from(table.querySelectorAll('.sort-btn'));
    const sortState = { key: null, dir: null };
    const sortTypes = options.sortTypes || {};

    const noMatchesRow = document.createElement('tr');
    noMatchesRow.dataset.noMatches = '1';
    const placeholderCell = document.createElement('td');
    placeholderCell.colSpan = table.querySelectorAll('thead th').length || 1;
    placeholderCell.className = options.noMatchesClass || '';
    placeholderCell.style.padding = options.noMatchesPadding;
    placeholderCell.textContent = options.noMatchesText;
    noMatchesRow.appendChild(placeholderCell);
    noMatchesRow.style.display = 'none';
    tbody.appendChild(noMatchesRow);

    const searchCache = new Map();
    function computeSearchText(row){
      if (typeof options.computeSearch === 'function') {
        return options.computeSearch(row, expansions.get(row) || null) || '';
      }
      let text = row.textContent || '';
      const exp = expansions.get(row);
      if (exp) text += ' ' + (exp.textContent || '');
      return text.replace(/\s+/g, ' ').trim().toLowerCase();
    }

    rows.forEach(row => {
      searchCache.set(row, computeSearchText(row));
    });

    function compareRows(aRow, bRow, key, dir){
      const type = sortTypes[key] || 'string';
      const aVal = getSortValue(aRow, key);
      const bVal = getSortValue(bRow, key);

      if (type === 'number') {
        const aNum = aVal === '' ? NaN : parseFloat(aVal);
        const bNum = bVal === '' ? NaN : parseFloat(bVal);
        if (Number.isNaN(aNum) && Number.isNaN(bNum)) return 0;
        if (Number.isNaN(aNum)) return dir === 'asc' ? 1 : -1;
        if (Number.isNaN(bNum)) return dir === 'asc' ? -1 : 1;
        return dir === 'asc' ? aNum - bNum : bNum - aNum;
      }

      const aStr = String(aVal || '').toLowerCase();
      const bStr = String(bVal || '').toLowerCase();
      const cmp = aStr.localeCompare(bStr, undefined, { numeric: true, sensitivity: 'base' });
      return dir === 'asc' ? cmp : -cmp;
    }

    function applySort(key, dir){
      const items = originalOrder.map(item => Object.assign({}, item));
      if (key && dir) {
        items.sort((a, b) => {
          const primary = compareRows(a.row, b.row, key, dir);
          if (primary !== 0) return primary;
          return a.order - b.order;
        });
      } else {
        items.sort((a, b) => a.order - b.order);
      }

      items.forEach(item => {
        tbody.appendChild(item.row);
        if (item.exp) tbody.appendChild(item.exp);
      });
      tbody.appendChild(noMatchesRow);
    }

    function updateSortIndicators(activeKey, dir){
      sortButtons.forEach(btn => {
        const key = btn.dataset.sortKey;
        if (key === activeKey && dir) {
          btn.dataset.state = dir;
        } else {
          btn.dataset.state = 'off';
        }
      });
    }

    function cycleSort(btn){
      const key = btn.dataset.sortKey;
      if (!key) return;
      let nextDir = 'asc';
      if (sortState.key === key) {
        if (sortState.dir === 'asc') nextDir = 'desc';
        else if (sortState.dir === 'desc') nextDir = null;
      }
      sortState.key = nextDir ? key : null;
      sortState.dir = nextDir;
      updateSortIndicators(sortState.key, sortState.dir);
      applySort(sortState.key, sortState.dir);
      if (currentSearchQuery !== null) {
        applySearch(currentSearchQuery);
      }
    }

    sortButtons.forEach(btn => {
      btn.addEventListener('click', () => cycleSort(btn));
    });

    let currentSearchQuery = null;

    function applySearch(query){
      currentSearchQuery = query;
      const q = (query || '').trim().toLowerCase();
      let matches = 0;
      rows.forEach(row => {
        const text = searchCache.get(row) ?? computeSearchText(row);
        const isMatch = !q || (text && text.includes(q));
        if (isMatch) {
          row.style.display = '';
          matches++;
        } else {
          row.style.display = 'none';
          const exp = expansions.get(row);
          if (exp) exp.style.display = 'none';
        }
      });
      noMatchesRow.style.display = matches ? 'none' : '';
    }

    if (options.searchInput) {
      options.searchInput.addEventListener('input', (event) => {
        applySearch(event.target.value || '');
      });
      currentSearchQuery = options.searchInput.value || '';
      applySearch(currentSearchQuery);
    }

    if (options.defaultSort && options.defaultSort.key && options.defaultSort.dir) {
      sortState.key = options.defaultSort.key;
      sortState.dir = options.defaultSort.dir;
      updateSortIndicators(sortState.key, sortState.dir);
      applySort(sortState.key, sortState.dir);
      if (currentSearchQuery !== null) {
        applySearch(currentSearchQuery);
      }
    } else {
      applySort(null, null);
    }

    enableColumnResizing(table, options);

    return {
      applySearch,
      applySort,
      sortState
    };
  }

  window.ppfEnhanceTable = enhanceTable;
})();
