// Starts the vendored reader with sharebox settings (asset path, storage key, book to open).
(function () {
    'use strict';
    var root = document.getElementById('asc-reader');
    var data = document.getElementById('sb-reader-data');
    if (!root || !data || typeof AscReader === 'undefined') return;
    var cfg;
    try { cfg = JSON.parse(data.textContent); } catch (e) { return; }
    // A book opened from its row goes back to the listing it came from, not to the reader's library.
    var reader = AscReader.init({ root: root, token: cfg.storageToken, books: cfg.books, libBase: cfg.libBase, exitUrl: cfg.open ? cfg.backUrl : null });
    if (cfg.open) reader.open(cfg.open);
})();
