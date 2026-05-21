(function () {
    'use strict';

    function updateLocalePreview(root) {
        var regionSelect = root.querySelector('[data-region-select]');
        var preview = root.querySelector('[data-locale-preview]');
        var localeMap = window.guilroimAdminSettings && window.guilroimAdminSettings.localeMap ? window.guilroimAdminSettings.localeMap : {};

        if (!regionSelect || !preview) {
            return;
        }

        preview.textContent = localeMap[regionSelect.value] || 'en_US';
    }

    function updateDisplayNames(root) {
        var tiles = root.querySelectorAll('[data-display-tile]');
        var list = root.querySelector('[data-display-list]');
        var empty = root.querySelector('[data-display-empty]');

        if (!tiles.length && list && !empty) {
            empty = document.createElement('div');
            empty.className = 'wgr-admin-empty-state';
            empty.setAttribute('data-display-empty', '');
            empty.textContent = 'No displays created yet. Click "Create Display" to add one.';
            list.appendChild(empty);
        } else if (tiles.length && empty) {
            empty.remove();
        }

        Array.prototype.forEach.call(tiles, function (tile, index) {
            var titleInput = tile.querySelector('[data-field="title"]');
            var title = titleInput ? titleInput.value.trim() : '';
            var heading = tile.querySelector('.wgr-display-tile__header h3');
            if (heading) {
                heading.textContent = title || 'New Display';
            }

            Array.prototype.forEach.call(tile.querySelectorAll('[data-field]'), function (field) {
                var fieldName = field.getAttribute('data-field');
                if (!fieldName) {
                    return;
                }
                field.name = 'guilroim_options[displays][' + index + '][' + fieldName + ']';
            });

            reindexCharacterRows(tile, index);

            updateShortcodePreview(tile);
        });
    }

    function reindexCharacterRows(tile, displayIndex) {
        var rows = tile.querySelectorAll('[data-character-row]');

        Array.prototype.forEach.call(rows, function (row, characterIndex) {
            Array.prototype.forEach.call(row.querySelectorAll('[data-character-field]'), function (field) {
                var fieldName = field.getAttribute('data-character-field');
                if (!fieldName) {
                    return;
                }

                field.name = 'guilroim_options[displays][' + displayIndex + '][single_characters][' + characterIndex + '][' + fieldName + ']';
            });
        });
    }

    function updateShortcodePreview(tile) {
        var preview = tile.querySelector('[data-shortcode-preview]');
        var idField = tile.querySelector('[data-field="id"]');
        var shortcodeTag = window.guilroimAdminSettings && window.guilroimAdminSettings.shortcodeTag ? window.guilroimAdminSettings.shortcodeTag : 'guilroim_roster';

        if (!preview || !idField) {
            return;
        }

        preview.textContent = '[' + shortcodeTag + ' display="' + idField.value + '"]';
    }

    function ensureDisplayId(tile) {
        var idField = tile.querySelector('[data-field="id"]');
        if (!idField) {
            return;
        }

        if (idField.value && idField.value !== '__display_id__') {
            return;
        }

        idField.value = 'display-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
    }

    function addDisplay(root) {
        var template = root.querySelector('#wgr-display-template');
        var list = root.querySelector('[data-display-list]');
        var empty = root.querySelector('[data-display-empty]');

        if (!template || !list) {
            return;
        }

        if (empty) {
            empty.remove();
        }

        var wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.trim();
        var tile = wrapper.firstElementChild;
        if (!tile) {
            return;
        }

        list.appendChild(tile);
        ensureDisplayId(tile);
        updateDisplayNames(root);
    }

    function addCharacterRow(tile) {
        var template = tile.querySelector('[data-character-template]');
        var list = tile.querySelector('[data-character-list]');
        if (!template || !list) {
            return;
        }

        var wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.trim();
        var row = wrapper.firstElementChild;
        if (!row) {
            return;
        }

        list.appendChild(row);
    }

    function setupTabs(root) {
        var tabs = root.querySelectorAll('.wgr-admin-tab');
        var panels = root.querySelectorAll('.wgr-admin-panel');

        Array.prototype.forEach.call(tabs, function (tab) {
            tab.addEventListener('click', function () {
                var panelId = tab.getAttribute('data-tab');

                Array.prototype.forEach.call(tabs, function (item) {
                    item.classList.toggle('is-active', item === tab);
                });

                Array.prototype.forEach.call(panels, function (panel) {
                    var matches = panel.getAttribute('data-panel') === panelId;
                    panel.classList.toggle('is-active', matches);
                    panel.hidden = !matches;
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('.wgr-admin-page');
        if (!root) {
            return;
        }

        setupTabs(root);
        updateLocalePreview(root);
        updateDisplayNames(root);

        var regionSelect = root.querySelector('[data-region-select]');
        if (regionSelect) {
            regionSelect.addEventListener('change', function () {
                updateLocalePreview(root);
            });
        }

        root.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-add-display]');
            if (addButton) {
                addDisplay(root);
                return;
            }

            var addCharacterButton = event.target.closest('[data-add-character]');
            if (addCharacterButton) {
                var addTile = addCharacterButton.closest('[data-display-tile]');
                if (addTile) {
                    addCharacterRow(addTile);
                    updateDisplayNames(root);
                }
                return;
            }

            var removeButton = event.target.closest('[data-remove-display]');
            if (removeButton) {
                var tile = removeButton.closest('[data-display-tile]');
                if (tile) {
                    tile.remove();
                    updateDisplayNames(root);
                }
                return;
            }

            var removeCharacterButton = event.target.closest('[data-remove-character]');
            if (removeCharacterButton) {
                var row = removeCharacterButton.closest('[data-character-row]');
                if (row) {
                    row.remove();
                    updateDisplayNames(root);
                }
            }
        });

        root.addEventListener('input', function (event) {
            if (event.target.matches('[data-field="title"]')) {
                updateDisplayNames(root);
            }
        });

        root.addEventListener('change', function (event) {
            if (event.target.matches('[data-field]')) {
                updateDisplayNames(root);
            }
        });
    });
})();
