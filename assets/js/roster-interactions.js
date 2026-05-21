(function () {
    'use strict';
    var avatarCache = {};
    var mythicCache = {};
    var mythicPending = {};
    var LIVE_MYTHIC_ENABLED = !(window.guilroimAvatarApi && window.guilroimAvatarApi.enableLiveMythic === false);
    var MYTHIC_CACHE_KEY_PREFIX = 'guilroim_mythic_v2_';
    var AVATAR_CACHE_TTL_MS = (window.guilroimAvatarApi && window.guilroimAvatarApi.cacheTtlMs) ? Number(window.guilroimAvatarApi.cacheTtlMs) : (7 * 24 * 60 * 60 * 1000);
    var MYTHIC_CACHE_TTL_MS = (window.guilroimAvatarApi && window.guilroimAvatarApi.mythicCacheTtlMs) ? Number(window.guilroimAvatarApi.mythicCacheTtlMs) : (12 * 60 * 60 * 1000);

    function readAvatarFromStorage(cacheKey) {
        try {
            var raw = window.localStorage.getItem('guilroim_avatar_' + cacheKey);
            if (!raw) { return ''; }
            var parsed = JSON.parse(raw);
            if (!parsed || !parsed.url || !parsed.expiresAt) { return ''; }
            if (Date.now() > parsed.expiresAt) {
                window.localStorage.removeItem('guilroim_avatar_' + cacheKey);
                return '';
            }
            return String(parsed.url);
        } catch (e) {
            return '';
        }
    }

    function writeAvatarToStorage(cacheKey, url) {
        try {
            window.localStorage.setItem(
                'guilroim_avatar_' + cacheKey,
                JSON.stringify({
                    url: url,
                    expiresAt: Date.now() + AVATAR_CACHE_TTL_MS
                })
            );
        } catch (e) {
            // ignore storage failures
        }
    }

    function readMythicFromStorage(cacheKey) {
        try {
            var raw = window.localStorage.getItem(MYTHIC_CACHE_KEY_PREFIX + cacheKey);
            if (!raw) { return null; }
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.score === 'undefined' || !parsed.expiresAt) { return null; }
            if (Date.now() > parsed.expiresAt) {
                window.localStorage.removeItem(MYTHIC_CACHE_KEY_PREFIX + cacheKey);
                return null;
            }
            return Number(parsed.score) || 0;
        } catch (e) {
            return null;
        }
    }

    function writeMythicToStorage(cacheKey, score) {
        try {
            if ((Number(score) || 0) <= 0) {
                window.localStorage.removeItem(MYTHIC_CACHE_KEY_PREFIX + cacheKey);
                return;
            }
            window.localStorage.setItem(
                MYTHIC_CACHE_KEY_PREFIX + cacheKey,
                JSON.stringify({
                    score: score,
                    expiresAt: Date.now() + MYTHIC_CACHE_TTL_MS
                })
            );
        } catch (e) {
            // ignore storage failures
        }
    }

    function toNumber(value) {
        var n = parseInt(value, 10);
        return Number.isNaN(n) ? 0 : n;
    }

    function compareValues(a, b, numeric) {
        if (numeric) {
            return toNumber(a) - toNumber(b);
        }
        return String(a).localeCompare(String(b), undefined, { sensitivity: 'base' });
    }

    function getMythicScoreClass(score) {
        if (score >= 3500) { return 'wgr-mythic-score-legendary'; }
        if (score >= 3000) { return 'wgr-mythic-score-epic'; }
        if (score >= 2500) { return 'wgr-mythic-score-azure'; }
        if (score >= 2000) { return 'wgr-mythic-score-rare'; }
        if (score >= 1500) { return 'wgr-mythic-score-uncommon'; }
        if (score >= 1000) { return 'wgr-mythic-score-emerald'; }
        if (score > 0) { return 'wgr-mythic-score-common'; }
        return 'wgr-mythic-score-none';
    }

    function getCheckedFilterValues(container) {
        if (!container) {
            return [];
        }

        return Array.prototype.slice.call(container.querySelectorAll('input[type="checkbox"]:checked')).map(function (input) {
            return input.value;
        });
    }

    function updateMultiFilterLabel(container) {
        if (!container) {
            return;
        }

        var label = container.querySelector('.wgr-filter-toggle-label');
        if (!label) {
            return;
        }

        var selected = getCheckedFilterValues(container);
        if (!selected.length) {
            label.textContent = container.getAttribute('data-label-all') || '';
            return;
        }

        if (selected.length === 1) {
            label.textContent = selected[0];
            return;
        }

        var pluralLabel = container.getAttribute('data-label-plural') || '';
        label.textContent = selected.length + ' ' + pluralLabel;
    }

    function closeMultiFilter(container) {
        if (!container) {
            return;
        }

        var button = container.querySelector('.wgr-filter-toggle');
        var panel = container.querySelector('.wgr-filter-panel');
        container.classList.remove('is-open');
        if (button) {
            button.setAttribute('aria-expanded', 'false');
        }
        if (panel) {
            panel.hidden = true;
        }
    }

    function openMultiFilter(container) {
        if (!container) {
            return;
        }

        var button = container.querySelector('.wgr-filter-toggle');
        var panel = container.querySelector('.wgr-filter-panel');
        container.classList.add('is-open');
        if (button) {
            button.setAttribute('aria-expanded', 'true');
        }
        if (panel) {
            panel.hidden = false;
        }
    }

    function getCellSortValue(row, idx, numeric) {
        var cell = row.children[idx];
        if (!cell) {
            return numeric ? '0' : '';
        }

        var dataValue = cell.getAttribute('data-sort-value');
        if (dataValue !== null && dataValue !== '') {
            return dataValue;
        }

        return cell.textContent.trim();
    }

    function initRoster(root) {
        var table = root.querySelector('.wgr-table');
        var tbody = table ? table.querySelector('tbody') : null;
        var pager = root.querySelector('.wgr-pagination');
        var firstBtn = pager ? pager.querySelector('.wgr-page-first') : null;
        var prevBtn = pager ? pager.querySelector('.wgr-page-prev') : null;
        var nextBtn = pager ? pager.querySelector('.wgr-page-next') : null;
        var lastBtn = pager ? pager.querySelector('.wgr-page-last') : null;
        var pageInfo = pager ? pager.querySelector('.wgr-page-info') : null;
        var nameInput = root.querySelector('.wgr-filter-name');
        var classFilter = root.querySelector('.wgr-filter-multiselect.wgr-filter-class');
        var roleFilter = root.querySelector('.wgr-filter-multiselect.wgr-filter-role');

        if (!table || !tbody || !pager || !firstBtn || !prevBtn || !nextBtn || !lastBtn || !pageInfo) {
            return;
        }

        var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        var filteredRows = allRows.slice();

        var state = {
            sortBy: root.getAttribute('data-initial-sort-by') || 'rank',
            sortOrder: root.getAttribute('data-initial-sort-order') || 'asc',
            pageSize: toNumber(root.getAttribute('data-page-size') || '25'),
            currentPage: 1
        };

        if ([10, 25, 50].indexOf(state.pageSize) === -1) {
            state.pageSize = 25;
        }

        var columnIndexes = { name: 0, class: 1, role: 2, race: 3, level: 4, rank: 5, mythic_score: 6 };

        function fetchAvatar(imgEl) {
            if (!window.guilroimAvatarApi || !guilroimAvatarApi.ajaxUrl || !guilroimAvatarApi.nonce) {
                return Promise.resolve();
            }

            var character = imgEl.getAttribute('data-avatar-character') || '';
            var realm = imgEl.getAttribute('data-avatar-realm') || '';
            var region = imgEl.getAttribute('data-avatar-region') || '';
            var placeholder = imgEl.getAttribute('data-avatar-placeholder') || '';
            if (!character || !realm || !region) {
                return Promise.resolve();
            }

            var cacheKey = (region + '|' + realm + '|' + character).toLowerCase();
            if (avatarCache[cacheKey]) {
                imgEl.src = avatarCache[cacheKey];
                return Promise.resolve();
            }
            var storedUrl = readAvatarFromStorage(cacheKey);
            if (storedUrl) {
                avatarCache[cacheKey] = storedUrl;
                imgEl.src = storedUrl;
                return Promise.resolve();
            }

            var params = new URLSearchParams();
            params.set('action', 'guilroim_get_avatar');
            params.set('nonce', guilroimAvatarApi.nonce);
            params.set('character', character);
            params.set('realm', realm);
            params.set('region', region);

            return fetch(guilroimAvatarApi.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            }).then(function (res) {
                if (!res.ok) { return null; }
                return res.json();
            }).then(function (json) {
                if (json && json.success && json.data && json.data.avatar_url) {
                    avatarCache[cacheKey] = json.data.avatar_url;
                    writeAvatarToStorage(cacheKey, json.data.avatar_url);
                    imgEl.src = json.data.avatar_url;
                    return;
                }
                if (placeholder) { imgEl.src = placeholder; }
            }).catch(function () {
                if (placeholder) { imgEl.src = placeholder; }
            });
        }

        function applyMythicScore(scoreEl, score) {
            var scoreValue = Number(score) || 0;
            var cell = scoreEl.closest('td');
            scoreEl.textContent = scoreValue > 0 ? scoreValue.toLocaleString() : '-';
            scoreEl.setAttribute('data-mythic-loaded', 'true');
            scoreEl.className = 'wgr-mythic-score ' + getMythicScoreClass(scoreValue);
            if (cell) {
                cell.setAttribute('data-sort-value', String(scoreValue));
            }
        }

        function collectPendingMythicScores(scoreElements) {
            var pending = [];

            scoreElements.forEach(function (scoreEl) {
                if (!scoreEl || scoreEl.getAttribute('data-mythic-loaded') === 'true') {
                    return;
                }

                var character = scoreEl.getAttribute('data-mythic-character') || '';
                var realm = scoreEl.getAttribute('data-mythic-realm') || '';
                var region = scoreEl.getAttribute('data-mythic-region') || '';
                if (!character || !realm || !region) {
                    return;
                }

                var cacheKey = (region + '|' + realm + '|' + character).toLowerCase();
                if (typeof mythicCache[cacheKey] !== 'undefined') {
                    applyMythicScore(scoreEl, mythicCache[cacheKey]);
                    return;
                }

                var storedScore = readMythicFromStorage(cacheKey);
                if (storedScore !== null) {
                    mythicCache[cacheKey] = storedScore;
                    applyMythicScore(scoreEl, storedScore);
                    return;
                }

                pending.push({
                    cacheKey: cacheKey,
                    character: character,
                    realm: realm,
                    region: region,
                    element: scoreEl
                });
            });

            return pending;
        }

        function fetchMythicScoresBatch(scoreElements) {
            if (!window.guilroimAvatarApi || !guilroimAvatarApi.ajaxUrl || !guilroimAvatarApi.nonce) {
                return Promise.resolve();
            }

            var pendingEntries = collectPendingMythicScores(scoreElements);
            if (!pendingEntries.length) {
                return Promise.resolve();
            }

            var requestRegion = pendingEntries[0].region;
            var requestEntries = [];
            var requestKeys = [];
            pendingEntries.forEach(function (entry) {
                if (entry.region !== requestRegion) {
                    return;
                }

                requestEntries.push({
                    character: entry.character,
                    realm: entry.realm
                });
                requestKeys.push(entry.cacheKey);
            });

            if (!requestEntries.length) {
                return Promise.resolve();
            }

            var batchKey = requestRegion + '|' + requestKeys.sort().join('|');
            if (mythicPending[batchKey]) {
                return mythicPending[batchKey];
            }

            var params = new URLSearchParams();
            params.set('action', 'guilroim_get_mythic_scores');
            params.set('nonce', guilroimAvatarApi.nonce);
            params.set('region', requestRegion);
            params.set('characters', JSON.stringify(requestEntries));

            mythicPending[batchKey] = fetch(guilroimAvatarApi.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            }).then(function (res) {
                if (!res.ok) { return null; }
                return res.json();
            }).then(function (json) {
                var resultScores = (json && json.success && json.data && json.data.scores) ? json.data.scores : {};

                pendingEntries.forEach(function (entry) {
                    var score = Number(resultScores[entry.cacheKey]) || 0;
                    mythicCache[entry.cacheKey] = score;
                    writeMythicToStorage(entry.cacheKey, score);
                    applyMythicScore(entry.element, score);
                });
            }).catch(function () {
                pendingEntries.forEach(function (entry) {
                    applyMythicScore(entry.element, 0);
                });
            }).finally(function () {
                delete mythicPending[batchKey];
            });

            return mythicPending[batchKey];
        }

        function hydrateVisibleAvatars() {
            var visible = filteredRows.filter(function (row) { return row.style.display !== 'none'; });
            var images = [];
            visible.forEach(function (row) {
                var img = row.querySelector('.wgr-avatar-icon[data-avatar-character]');
                if (!img) { return; }
                var placeholder = img.getAttribute('data-avatar-placeholder') || '';
                if (img.getAttribute('src') !== placeholder) { return; }
                images.push(img);
            });

            if (!images.length) { return; }
            var index = 0;
            var maxConcurrent = 4;
            function runNext() {
                if (index >= images.length) { return Promise.resolve(); }
                var imgEl = images[index];
                index += 1;
                return fetchAvatar(imgEl).then(runNext);
            }
            var workers = [];
            var workerCount = Math.min(maxConcurrent, images.length);
            for (var i = 0; i < workerCount; i += 1) {
                workers.push(runNext());
            }
            Promise.all(workers).catch(function () {});
        }

        function hydrateVisibleMythicScores() {
            if (!LIVE_MYTHIC_ENABLED) { return; }
            var totalRows = filteredRows.length;
            var totalPages = Math.max(1, Math.ceil(totalRows / state.pageSize));
            var prefetchPages = Math.min(totalPages, state.currentPage + 1);
            var preloadLimit = prefetchPages * state.pageSize;
            var scores = [];
            filteredRows.slice(0, preloadLimit).forEach(function (row) {
                var scoreEl = row.querySelector('.wgr-mythic-score[data-mythic-character]');
                if (!scoreEl || scoreEl.getAttribute('data-mythic-loaded') === 'true') { return; }
                scores.push(scoreEl);
            });

            if (!scores.length) { return; }
            fetchMythicScoresBatch(scores).then(function () {
                if (state.sortBy === 'mythic_score') {
                    sortRows();
                    renderPage();
                }
            }).catch(function () {});
        }

        function updateSortButtons() {
            var sortButtons = root.querySelectorAll('.wgr-sort-btn');
            Array.prototype.forEach.call(sortButtons, function (btn) {
                var isActive = btn.getAttribute('data-sort') === state.sortBy && btn.getAttribute('data-order') === state.sortOrder;
                btn.classList.toggle('is-active', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function sortRows() {
            var idx = columnIndexes[state.sortBy];
            var numeric = state.sortBy === 'level' || state.sortBy === 'rank' || state.sortBy === 'mythic_score';

            allRows.sort(function (rowA, rowB) {
                var a = getCellSortValue(rowA, idx, numeric);
                var b = getCellSortValue(rowB, idx, numeric);
                var result = compareValues(a, b, numeric);
                if (result === 0) {
                    var nameA = getCellSortValue(rowA, 0, false);
                    var nameB = getCellSortValue(rowB, 0, false);
                    result = compareValues(nameA, nameB, false);
                }
                return state.sortOrder === 'desc' ? (result * -1) : result;
            });

            allRows.forEach(function (row) { tbody.appendChild(row); });
        }

        function applyFilters() {
            var nameFilter = (nameInput ? nameInput.value.trim().toLowerCase() : '');
            var selectedClasses = getCheckedFilterValues(classFilter);
            var selectedRoles = getCheckedFilterValues(roleFilter);

            filteredRows = allRows.filter(function (row) {
                var rowName = (row.getAttribute('data-name') || '').toLowerCase();
                var rowClass = row.getAttribute('data-class') || '';
                var rowRole = row.getAttribute('data-role') || '';

                var okName = nameFilter === '' || rowName.indexOf(nameFilter) !== -1;
                var okClass = !selectedClasses.length || selectedClasses.indexOf(rowClass) !== -1;
                var okRole = !selectedRoles.length || selectedRoles.indexOf(rowRole) !== -1;
                return okName && okClass && okRole;
            });

            state.currentPage = 1;
            renderPage();
        }

        function renderPage() {
            var totalRows = filteredRows.length;
            var totalPages = Math.max(1, Math.ceil(totalRows / state.pageSize));
            if (state.currentPage > totalPages) {
                state.currentPage = totalPages;
            }

            var start = (state.currentPage - 1) * state.pageSize;
            var end = start + state.pageSize;

            allRows.forEach(function (row) { row.style.display = 'none'; });
            allRows.forEach(function (row) {
                row.classList.remove('wgr-row-odd');
                row.classList.remove('wgr-row-even');
            });

            var visibleIndex = 0;
            filteredRows.forEach(function (row, index) {
                if (index >= start && index < end) {
                    row.style.display = '';
                    if ((visibleIndex % 2) === 0) {
                        row.classList.add('wgr-row-odd');
                    } else {
                        row.classList.add('wgr-row-even');
                    }
                    visibleIndex += 1;
                }
            });

            pageInfo.textContent = 'Page ' + state.currentPage + ' of ' + totalPages;
            firstBtn.disabled = state.currentPage <= 1;
            prevBtn.disabled = state.currentPage <= 1;
            nextBtn.disabled = state.currentPage >= totalPages;
            lastBtn.disabled = state.currentPage >= totalPages;
            hydrateVisibleAvatars();
            hydrateVisibleMythicScores();
        }

        firstBtn.addEventListener('click', function () {
            if (state.currentPage > 1) {
                state.currentPage = 1;
                renderPage();
            }
        });

        prevBtn.addEventListener('click', function () {
            if (state.currentPage > 1) {
                state.currentPage -= 1;
                renderPage();
            }
        });

        nextBtn.addEventListener('click', function () {
            var totalPages = Math.max(1, Math.ceil(filteredRows.length / state.pageSize));
            if (state.currentPage < totalPages) {
                state.currentPage += 1;
                renderPage();
            }
        });

        lastBtn.addEventListener('click', function () {
            var totalPages = Math.max(1, Math.ceil(filteredRows.length / state.pageSize));
            if (state.currentPage < totalPages) {
                state.currentPage = totalPages;
                renderPage();
            }
        });

        var sortButtons = root.querySelectorAll('.wgr-sort-btn');
        Array.prototype.forEach.call(sortButtons, function (btn) {
            btn.addEventListener('click', function (event) {
                event.stopPropagation();
                state.sortBy = btn.getAttribute('data-sort') || 'rank';
                state.sortOrder = btn.getAttribute('data-order') || 'asc';
                sortRows();
                updateSortButtons();
                applyFilters();
            });
        });

        var headerColumns = root.querySelectorAll('.wgr-table thead tr:not(.wgr-filter-row) th[data-column]');
        Array.prototype.forEach.call(headerColumns, function (th) {
            th.addEventListener('click', function () {
                var col = th.getAttribute('data-column') || 'rank';
                if (state.sortBy === col) {
                    state.sortOrder = (state.sortOrder === 'asc') ? 'desc' : 'asc';
                } else {
                    state.sortBy = col;
                    state.sortOrder = 'asc';
                }
                sortRows();
                updateSortButtons();
                applyFilters();
            });
        });

        [classFilter, roleFilter].forEach(function (filterContainer) {
            if (!filterContainer) {
                return;
            }

            var toggle = filterContainer.querySelector('.wgr-filter-toggle');
            var checkboxes = filterContainer.querySelectorAll('input[type="checkbox"]');
            var actionButtons = filterContainer.querySelectorAll('.wgr-filter-action');

            updateMultiFilterLabel(filterContainer);

            if (toggle) {
                toggle.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();

                    [classFilter, roleFilter].forEach(function (otherFilter) {
                        if (otherFilter && otherFilter !== filterContainer) {
                            closeMultiFilter(otherFilter);
                        }
                    });

                    if (filterContainer.classList.contains('is-open')) {
                        closeMultiFilter(filterContainer);
                    } else {
                        openMultiFilter(filterContainer);
                    }
                });
            }

            Array.prototype.forEach.call(actionButtons, function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();

                    var action = button.getAttribute('data-filter-action') || '';
                    Array.prototype.forEach.call(checkboxes, function (checkbox) {
                        checkbox.checked = action === 'all';
                    });

                    updateMultiFilterLabel(filterContainer);
                    applyFilters();
                });
            });

            Array.prototype.forEach.call(checkboxes, function (checkbox) {
                checkbox.addEventListener('change', function () {
                    updateMultiFilterLabel(filterContainer);
                    applyFilters();
                });
            });
        });

        document.addEventListener('click', function (event) {
            [classFilter, roleFilter].forEach(function (filterContainer) {
                if (!filterContainer || filterContainer.contains(event.target)) {
                    return;
                }
                closeMultiFilter(filterContainer);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            [classFilter, roleFilter].forEach(function (filterContainer) {
                closeMultiFilter(filterContainer);
            });
        });

        if (nameInput) { nameInput.addEventListener('input', applyFilters); }

        sortRows();
        updateSortButtons();
        applyFilters();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var rosters = document.querySelectorAll('.wgr-roster');
        Array.prototype.forEach.call(rosters, initRoster);
    });
})();
