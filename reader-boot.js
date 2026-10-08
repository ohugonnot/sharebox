// Starts the vendored reader with sharebox settings (asset path, storage key, book to open).
(function () {
    'use strict';
    var root = document.getElementById('asc-reader');
    var data = document.getElementById('sb-reader-data');
    if (!root || !data || typeof AscReader === 'undefined') return;
    var cfg;
    try { cfg = JSON.parse(data.textContent); } catch (e) { return; }
    var reader = AscReader.init({ root: root, token: cfg.storageToken, books: cfg.books, libBase: cfg.libBase });
    if (cfg.open) reader.open(cfg.open);
})();
