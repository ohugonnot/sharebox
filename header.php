<?php
/** Shared app header — include after require_once auth.php
 *  $header_subtitle : string  — subtitle text (default: 'Secure file sharing & streaming')
 *  $header_back     : bool    — show '← Fichiers' instead of 'Admin' link (default: false)
 */
$header_subtitle ??= 'Secure file sharing & streaming';
$header_back     ??= false;
?>
<header class="app-header" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.5rem">
    <div style="display:flex;align-items:center;gap:.7rem;min-width:0">
        <div class="app-logo" style="flex-shrink:0">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M21 3h-8v2h5.59L11 12.59 12.41 14 20 6.41V12h2V3z" fill="#0c0e14"/>
                <path d="M3 5v16h16v-7h-2v5H5V7h5V5H3z" fill="#0c0e14"/>
            </svg>
        </div>
        <div style="min-width:0">
            <div class="app-title">Share<span style="color:var(--accent)">Box</span></div>
            <div class="app-subtitle" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($header_subtitle) ?></div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;justify-content:flex-end">
        <?php if (is_executable('/usr/local/bin/seedbox-adduser')): ?>
            <a href="https://<?= $_SERVER['HTTP_HOST'] ?>:8443" target="_blank" style="color:var(--text-secondary);font-size:.75rem;text-decoration:none;padding:.25rem .5rem;border:1px solid var(--border);border-radius:var(--radius-sm)">qBit</a>
        <?php endif; ?>
        <?php if ($header_back): ?>
            <a href="/share/" style="color:var(--text-secondary);font-size:.75rem;text-decoration:none;padding:.25rem .5rem;border:1px solid var(--border);border-radius:var(--radius-sm)">← Fichiers</a>
        <?php elseif (($_SESSION['sharebox_role'] ?? '') === 'admin'): ?>
            <a href="/share/admin.php" style="color:var(--accent);font-size:.75rem;text-decoration:none;padding:.25rem .5rem;border:1px solid rgba(240,160,48,.2);border-radius:var(--radius-sm)">Admin</a>
        <?php endif; ?>
        <span style="display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .5rem;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:.75rem">
            <span style="color:var(--text-secondary)"><?= htmlspecialchars(get_current_user_name() ?? '') ?></span>
            <?php if (!defined('TRUSTED_AUTH_HEADER') || TRUSTED_AUTH_HEADER === ''): ?>
                <a href="/share/logout.php" style="color:var(--text-muted);text-decoration:none">Logout</a>
            <?php endif; ?>
        </span>
    </div>
</header>
