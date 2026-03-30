/**
 * Navigateur de fichiers — JavaScript vanilla, zéro dépendance
 * Gère la navigation dans les dossiers, le partage de fichiers, et la copie des liens
 */

// Chemin courant dans le navigateur (relatif à BASE_PATH côté serveur)
let currentPath = '';

// Options d'expiration prédéfinies (valeur en heures, label affiché)
const DUREES_EXPIRATION = [
    { value: '',    label: 'Jamais' },
    { value: '24',  label: '1 jour' },
    { value: '168', label: '1 semaine' },
    { value: '720', label: '1 mois' },
    { value: 'custom', label: 'Perso…' },
];

// Token CSRF pour les requêtes POST
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

// Au chargement de la page
document.addEventListener('DOMContentLoaded', () => {
    navigateTo('');
});

/**
 * Navigue vers un répertoire donné et met à jour l'affichage
 */
async function navigateTo(path) {
    currentPath = path;

    try {
        const resp = await fetch('/share/ctrl.php?cmd=browse&path=' + encodeURIComponent(path));
        if (!resp.ok) {
            alert('Erreur HTTP ' + resp.status);
            return;
        }
        const data = await resp.json();

        if (data.error) {
            alert('Erreur : ' + data.error);
            return;
        }

        afficherBreadcrumb(path);
        afficherFichiers(data.entries);
    } catch (e) {
        console.error('navigateTo error:', e);
        alert('Erreur de connexion au serveur');
    }
}

/**
 * Construit le fil d'Ariane cliquable
 */
function afficherBreadcrumb(path) {
    const container = document.getElementById('breadcrumb');
    container.innerHTML = '';

    // Lien racine
    const rootLink = document.createElement('a');
    rootLink.textContent = 'Fichiers';
    rootLink.href = '#';
    rootLink.addEventListener('click', (e) => { e.preventDefault(); navigateTo(''); });
    container.appendChild(rootLink);

    if (!path) return;

    const parts = path.split('/').filter(Boolean);
    let cumul = '';

    parts.forEach((part, i) => {
        const sep = document.createElement('span');
        sep.className = 'sep';
        sep.textContent = '/';
        container.appendChild(sep);

        cumul += (cumul ? '/' : '') + part;

        if (i < parts.length - 1) {
            const link = document.createElement('a');
            link.textContent = part;
            link.href = '#';
            const target = cumul;
            link.addEventListener('click', (e) => { e.preventDefault(); navigateTo(target); });
            container.appendChild(link);
        } else {
            const span = document.createElement('span');
            span.className = 'current';
            span.textContent = part;
            container.appendChild(span);
        }
    });
}

/**
 * Affiche la liste des fichiers et dossiers dans le panel
 */
function afficherFichiers(entries) {
    const list = document.getElementById('file-list');
    list.innerHTML = '';

    // Filtrer les fichiers cachés (commençant par un point)
    entries = entries.filter(e => !e.name.startsWith('.'));

    // Bouton remonter (..) si on est dans un sous-dossier — toute la ligne est cliquable
    if (currentPath) {
        const li = creerElement('li', 'file-item is-clickable');
        li.addEventListener('click', remonter);

        const info = creerElement('div', 'file-info');
        const icon = creerElement('span', 'file-icon is-up');
        icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';

        const name = creerElement('span', 'file-name is-folder');
        name.textContent = '..';

        info.append(icon, name);
        li.appendChild(info);
        list.appendChild(li);
    }

    // Message si le dossier est vide
    if (entries.length === 0) {
        const li = creerElement('li', 'file-item');
        li.innerHTML = '<div class="empty-msg"><span class="empty-icon">&#x1F4ED;</span>' +
            (currentPath ? 'Dossier vide' : 'Aucun fichier visible') + '</div>';
        list.appendChild(li);
        return;
    }

    // Afficher chaque entrée (fichier ou dossier)
    entries.forEach(entry => {
        const isFolder = entry.type === 'folder';
        const entryPath = currentPath ? currentPath + '/' + entry.name : entry.name;

        const li = creerElement('li', 'file-item' + (isFolder ? ' is-clickable' : ''));

        // Clic sur toute la ligne pour ouvrir un dossier
        if (isFolder) {
            li.addEventListener('click', (e) => {
                // Ne pas naviguer si on clique sur le bouton Partager ou le formulaire
                if (e.target.closest('.file-actions')) return;
                navigateTo(entryPath);
            });
        }

        // Icône
        const icon = creerElement('span', 'file-icon ' + (isFolder ? 'is-folder' : 'is-file'));
        if (isFolder) {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="color:var(--accent)"><path d="M2 6a2 2 0 012-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>';
        } else {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--blue)"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
        }

        // Nom
        const nameSpan = creerElement('span', isFolder ? 'file-name is-folder' : 'file-name');
        nameSpan.textContent = entry.name;

        // Taille
        const sizeSpan = creerElement('span', 'file-size');
        sizeSpan.textContent = isFolder ? '' : formatTaille(entry.size);

        const info = creerElement('div', 'file-info');
        info.append(icon, nameSpan, sizeSpan);

        // Bouton Partager
        const actions = creerElement('div', 'file-actions');
        const shareBtn = creerElement('button', 'btn btn-share-icon');
        shareBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';
        shareBtn.setAttribute('title', 'Partager');
        shareBtn.setAttribute('aria-label', 'Partager');
        shareBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            ouvrirShareSheet(entryPath, entry.name);
        });
        actions.appendChild(shareBtn);

        li.append(info, actions);
        list.appendChild(li);
    });
}

/**
 * Remonte d'un niveau dans l'arborescence
 */
function remonter() {
    const parts = currentPath.split('/').filter(Boolean);
    parts.pop();
    navigateTo(parts.join('/'));
}

/**
 * Ouvre le bottom sheet de création de lien
 */
function ouvrirShareSheet(path, name) {
    fermerShareSheet();

    let selectedExpiry = '';

    const overlay = creerElement('div', 'sheet-overlay');
    overlay.id = 'sheet-overlay';
    overlay.addEventListener('click', fermerShareSheet);

    const sheet = creerElement('div', 'share-sheet');
    sheet.id = 'share-sheet';

    // Handle
    const handle = creerElement('div', 'sheet-handle');

    // Titre + nom du fichier
    const titleLabel = creerElement('div', 'sheet-label');
    titleLabel.textContent = 'Partager';

    const filename = creerElement('div', 'sheet-filename');
    filename.textContent = name;

    // Champ mot de passe
    const pwdLabel = creerElement('div', 'sheet-field-label');
    pwdLabel.textContent = 'Mot de passe (optionnel)';

    const pwdWrap = creerElement('div', 'sheet-pwd-wrap');
    const pwdInput = document.createElement('input');
    pwdInput.type = 'password';
    pwdInput.placeholder = 'Laisser vide si public';
    pwdInput.className = 'sheet-pwd-input';

    const pwdToggle = creerElement('button', 'sheet-pwd-toggle');
    pwdToggle.type = 'button';
    pwdToggle.innerHTML = sheetEyeIcon();
    pwdToggle.addEventListener('click', () => {
        const show = pwdInput.type === 'password';
        pwdInput.type = show ? 'text' : 'password';
        pwdToggle.innerHTML = show ? sheetEyeOffIcon() : sheetEyeIcon();
    });
    pwdWrap.append(pwdInput, pwdToggle);

    // Pills expiration
    const expLabel = creerElement('div', 'sheet-field-label');
    expLabel.textContent = 'Expiration';

    const pillsWrap = creerElement('div', 'sheet-pills');

    // Champ custom (caché par défaut)
    const customWrap = creerElement('div', 'sheet-custom-wrap');
    customWrap.style.display = 'none';

    const customInput = document.createElement('input');
    customInput.type = 'number';
    customInput.min = '1';
    customInput.placeholder = '12';
    customInput.className = 'sheet-pwd-input';
    customInput.style.width = '50%';

    const customUnit = document.createElement('select');
    customUnit.className = 'sheet-custom-unit';
    [['h', 'heures'], ['j', 'jours']].forEach(([v, l]) => {
        const o = document.createElement('option');
        o.value = v; o.textContent = l;
        customUnit.appendChild(o);
    });

    customWrap.append(customInput, customUnit);

    DUREES_EXPIRATION.forEach(opt => {
        const pill = creerElement('button', 'sheet-pill' + (opt.value === '' ? ' is-active' : ''));
        pill.textContent = opt.label;
        pill.addEventListener('click', () => {
            pillsWrap.querySelectorAll('.sheet-pill').forEach(p => p.classList.remove('is-active'));
            pill.classList.add('is-active');
            if (opt.value === 'custom') {
                customWrap.style.display = 'flex';
                selectedExpiry = 'custom';
                customInput.focus();
            } else {
                customWrap.style.display = 'none';
                selectedExpiry = opt.value;
            }
        });
        pillsWrap.appendChild(pill);
    });

    // Bouton créer
    const createBtn = creerElement('button', 'sheet-create-btn');
    createBtn.innerHTML = svgUpload() + ' Créer le lien';
    createBtn.addEventListener('click', () => {
        let expiry = selectedExpiry;
        if (expiry === 'custom') {
            const val = parseInt(customInput.value);
            const unit = customUnit.value;
            expiry = isNaN(val) || val < 1 ? '' : String(unit === 'j' ? val * 24 : val);
        }
        creerLienSheet(path, pwdInput.value, expiry, sheet);
    });

    sheet.append(handle, titleLabel, filename, pwdLabel, pwdWrap, expLabel, pillsWrap, customWrap, createBtn);
    document.body.append(overlay, sheet);

    setTimeout(() => pwdInput.focus(), 320);
}

function fermerShareSheet() {
    document.getElementById('sheet-overlay')?.remove();
    document.getElementById('share-sheet')?.remove();
}

async function creerLienSheet(path, password, expiresStr, sheet) {
    const expires = expiresStr ? parseInt(expiresStr) : null;

    const createBtn = sheet.querySelector('.sheet-create-btn');
    if (createBtn) {
        createBtn.disabled = true;
        createBtn.innerHTML = svgSpinner() + ' Création…';
    }

    try {
        const resp = await fetch('/share/ctrl.php?cmd=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path, password: password || '', expires, csrf_token: CSRF_TOKEN }),
        });
        const data = await resp.json();
        if (data.error) { alert('Erreur : ' + data.error); if (createBtn) createBtn.disabled = false; return; }

        const fullUrl = window.location.origin + data.url;

        // État succès
        sheet.innerHTML = '';
        const handle = creerElement('div', 'sheet-handle');

        const successIcon = creerElement('div', 'sheet-success-icon');
        successIcon.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';

        const successLabel = creerElement('div', 'sheet-label');
        successLabel.textContent = 'Lien créé';
        successLabel.style.textAlign = 'center';
        successLabel.style.marginBottom = '.5rem';

        const urlBox = creerElement('div', 'sheet-url-box');
        urlBox.textContent = fullUrl;

        const copyBtn = creerElement('button', 'sheet-copy-btn');
        copyBtn.innerHTML = svgCopy() + ' Copier le lien';
        copyBtn.addEventListener('click', async () => {
            let text = fullUrl;
            if (password) text += '\nMot de passe\u00a0: ' + password;
            try {
                await navigator.clipboard.writeText(text);
                copyBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Copié\u00a0!';
                copyBtn.classList.add('is-copied');
                setTimeout(() => {
                    copyBtn.innerHTML = svgCopy() + ' Copier le lien';
                    copyBtn.classList.remove('is-copied');
                }, 2500);
            } catch { prompt('Lien :', fullUrl); }
        });

        const closeBtn = creerElement('button', 'sheet-close-btn');
        closeBtn.textContent = 'Fermer';
        closeBtn.addEventListener('click', fermerShareSheet);

        sheet.append(handle, successIcon, successLabel, urlBox, copyBtn, closeBtn);
        rafraichirLiens();

    } catch { alert('Erreur de connexion'); }
}

function sheetEyeIcon() {
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}
function sheetEyeOffIcon() {
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
}
function svgUpload() {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';
}
function svgCopy() {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
}
function svgSpinner() {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:sheet-spin .8s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';
}

/**
 * Appelle l'API pour créer un nouveau lien de partage
 */
async function creerLien(path, password, expiresStr, container) {
    const expires = expiresStr ? parseInt(expiresStr) : null;

    try {
        const resp = await fetch('/share/ctrl.php?cmd=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: path, password: password || '', expires: expires, csrf_token: CSRF_TOKEN }),
        });

        const data = await resp.json();

        if (data.error) {
            alert('Erreur : ' + data.error);
            return;
        }

        // Afficher le lien créé avec bouton copier
        container.innerHTML = '';

        const fullUrl = window.location.origin + data.url;

        const msg = creerElement('span', 'copy-msg');
        msg.innerHTML = '&#x2713; Créé';

        const copyBtn = creerElement('button', 'btn btn-primary btn-sm');
        copyBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copier le lien';
        copyBtn.addEventListener('click', () => copierLien(fullUrl, copyBtn));

        container.append(msg, copyBtn);

        // Rafraîchir le tableau des liens actifs
        rafraichirLiens();

    } catch (e) {
        alert('Erreur de connexion');
    }
}

/**
 * Copie un lien dans le presse-papiers
 */
async function copierLien(url, btn) {
    try {
        await navigator.clipboard.writeText(url);
        const orig = btn.innerHTML;
        btn.innerHTML = '&#x2713; Copié !';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-primary');
        setTimeout(() => {
            btn.innerHTML = orig;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-primary');
        }, 2000);
    } catch {
        // Fallback si clipboard API indisponible
        prompt('Copie ce lien :', url);
    }
}

/**
 * Rafraîchit le tableau des liens actifs sans recharger la page
 */
async function rafraichirLiens() {
    try {
        const resp = await fetch('/share/index.php?fragment=links');
        if (resp.ok) {
            const html = await resp.text();
            const container = document.getElementById('links-container');
            if (container && html.trim()) {
                container.innerHTML = html;
            }
        }
    } catch {
        // Pas grave si ça échoue, la page se recharge au prochain accès
    }
}

/**
 * Supprime un lien de partage après confirmation
 */
async function supprimerLien(id) {
    if (!confirm('Supprimer ce lien de partage ?')) return;

    try {
        const resp = await fetch('/share/ctrl.php?cmd=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, csrf_token: CSRF_TOKEN }),
        });

        const data = await resp.json();
        if (data.error) {
            alert('Erreur : ' + data.error);
            return;
        }

        location.reload();

    } catch {
        alert('Erreur de connexion');
    }
}

/**
 * Formate une taille en octets vers une forme lisible (Ko, Mo, Go)
 */
function formatTaille(bytes) {
    if (bytes === null || bytes === undefined) return '';
    if (bytes < 1024) return bytes + ' o';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' Ko';
    if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' Mo';
    return (bytes / 1073741824).toFixed(2) + ' Go';
}

/**
 * Copie le lien (+ mot de passe si présent) dans le presse-papiers
 * Appelé depuis les cartes de liens actifs
 */
async function copierInfoLien(url, hasPwd, pwd, btn) {
    const fullUrl = window.location.origin + url;
    let text = fullUrl;
    if (hasPwd && pwd) {
        text += '\nMot de passe : ' + pwd;
    }

    try {
        await navigator.clipboard.writeText(text);
        const orig = btn.innerHTML;
        btn.innerHTML = '&#x2713; Copié !';
        setTimeout(() => { btn.innerHTML = orig; }, 2000);
    } catch {
        prompt('Copie ces infos :', text);
    }
}

/**
 * Envoie un lien de partage par email via l'API
 * Demande l'adresse email dans un prompt
 */
async function envoyerEmail(linkId) {
    const email = prompt('Adresse email du destinataire :');
    if (!email || !email.trim()) return;

    try {
        const resp = await fetch('/share/ctrl.php?cmd=send_email', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: linkId, email: email.trim(), csrf_token: CSRF_TOKEN }),
        });

        const data = await resp.json();

        if (data.error) {
            alert('Erreur : ' + data.error);
            return;
        }

        alert('Email envoyé à ' + email.trim() + ' !');
    } catch {
        alert('Erreur de connexion');
    }
}

/**
 * Affiche un QR code en popup pour un lien de partage
 */
function afficherQR(url, btn) {
    // Fermer un popup existant
    const old = document.getElementById('qr-popup');
    if (old) old.remove();

    const fullUrl = window.location.origin + url;

    const popup = creerElement('div', 'qr-popup');
    popup.id = 'qr-popup';

    const card = creerElement('div', 'qr-card');

    const title = creerElement('div', 'qr-title');
    title.textContent = 'Scanner pour accéder';

    const canvas = document.createElement('canvas');
    canvas.id = 'qr-canvas';
    genererQR(canvas, fullUrl);

    const urlText = creerElement('div', 'qr-url');
    urlText.textContent = fullUrl;

    const closeBtn = creerElement('button', 'btn btn-ghost btn-sm');
    closeBtn.textContent = 'Fermer';
    closeBtn.addEventListener('click', () => popup.remove());

    card.append(title, canvas, urlText, closeBtn);
    popup.appendChild(card);

    // Fermer au clic sur le fond
    popup.addEventListener('click', (e) => {
        if (e.target === popup) popup.remove();
    });

    document.body.appendChild(popup);
}

/**
 * Génère un QR code sur un canvas (implémentation minimaliste)
 * Utilise l'API Canvas pour dessiner un QR code via la lib inline
 */
function genererQR(canvas, text) {
    // Encoder les données en QR code (version simplifiée mode byte, ECC L)
    const modules = qrEncode(text);
    const size = modules.length;
    const scale = Math.max(4, Math.floor(240 / size));
    const padding = 16;
    canvas.width = size * scale + padding * 2;
    canvas.height = size * scale + padding * 2;
    const ctx = canvas.getContext('2d');

    // Fond blanc avec coins arrondis
    ctx.fillStyle = '#fff';
    ctx.beginPath();
    ctx.roundRect(0, 0, canvas.width, canvas.height, 12);
    ctx.fill();

    // Modules
    ctx.fillStyle = '#1a1d28';
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            if (modules[y][x]) {
                ctx.fillRect(padding + x * scale, padding + y * scale, scale, scale);
            }
        }
    }
}

/**
 * QR Code encoder minimal — mode byte, ECC L, versions 1-10
 * Suffisant pour des URLs jusqu'à ~270 caractères
 */
function qrEncode(text) {
    const data = new TextEncoder().encode(text);
    // Version capacities (mode byte, ECC L)
    const caps = [0,17,32,53,78,106,134,154,192,230,271];
    let ver = 1;
    while (ver <= 10 && caps[ver] < data.length) ver++;
    if (ver > 10) ver = 10; // clamp

    const size = ver * 4 + 17;
    const grid = Array.from({length: size}, () => new Uint8Array(size));
    const mask = Array.from({length: size}, () => new Uint8Array(size));

    // Finder patterns
    function finderPattern(cx, cy) {
        for (let dy = -3; dy <= 3; dy++) for (let dx = -3; dx <= 3; dx++) {
            const x = cx + dx, y = cy + dy;
            if (x < 0 || y < 0 || x >= size || y >= size) continue;
            const d = Math.max(Math.abs(dx), Math.abs(dy));
            grid[y][x] = (d !== 2) ? 1 : 0;
            mask[y][x] = 1;
        }
        // Separators
        for (let i = -4; i <= 4; i++) {
            [[cx+i,cy-4],[cx+i,cy+4],[cx-4,cy+i],[cx+4,cy+i]].forEach(([x,y]) => {
                if (x >= 0 && y >= 0 && x < size && y < size) { grid[y][x] = 0; mask[y][x] = 1; }
            });
        }
    }
    finderPattern(3, 3);
    finderPattern(size - 4, 3);
    finderPattern(3, size - 4);

    // Alignment pattern (versions 2+)
    if (ver >= 2) {
        const pos = [6, size - 7];
        for (const ay of pos) for (const ax of pos) {
            if (mask[ay]?.[ax]) continue;
            for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) {
                const x = ax + dx, y = ay + dy;
                if (x >= 0 && y >= 0 && x < size && y < size) {
                    const d = Math.max(Math.abs(dx), Math.abs(dy));
                    grid[y][x] = (d === 1) ? 0 : 1;
                    mask[y][x] = 1;
                }
            }
        }
    }

    // Timing patterns
    for (let i = 8; i < size - 8; i++) {
        if (!mask[6][i]) { grid[6][i] = (i % 2 === 0) ? 1 : 0; mask[6][i] = 1; }
        if (!mask[i][6]) { grid[i][6] = (i % 2 === 0) ? 1 : 0; mask[i][6] = 1; }
    }

    // Dark module + reserved areas
    grid[size - 8][8] = 1; mask[size - 8][8] = 1;
    // Reserve format info areas
    for (let i = 0; i < 9; i++) {
        if (!mask[8][i]) mask[8][i] = 1;
        if (!mask[i][8]) mask[i][8] = 1;
        if (i < 8) {
            if (!mask[8][size - 1 - i]) mask[8][size - 1 - i] = 1;
            if (!mask[size - 1 - i][8]) mask[size - 1 - i][8] = 1;
        }
    }

    // Encode data: mode byte (0100) + length + data + terminator + padding
    const ecInfo = [[7,1,19],[10,1,34],[15,1,55],[20,1,80],[26,1,108],[36,2,68],[40,2,78],[48,2,97],[60,2,116],[72,2,68]];
    const [ecPerBlock, numBlocks, dataPerBlock] = ecInfo[ver - 1];
    const totalData = numBlocks * dataPerBlock;
    const bits = [];
    const push = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >> i) & 1); };
    push(0b0100, 4); // byte mode
    push(data.length, ver <= 9 ? 8 : 16);
    for (const b of data) push(b, 8);
    push(0, Math.min(4, totalData * 8 - bits.length)); // terminator
    while (bits.length % 8) bits.push(0);
    while (bits.length < totalData * 8) {
        push(0xEC, 8);
        if (bits.length < totalData * 8) push(0x11, 8);
    }

    // Convert to bytes
    const dataBytes = [];
    for (let i = 0; i < bits.length; i += 8) {
        dataBytes.push(bits.slice(i, i + 8).reduce((a, b) => (a << 1) | b, 0));
    }

    // RS error correction (simplified GF(256))
    function gfMul(a, b) {
        if (a === 0 || b === 0) return 0;
        let r = 0;
        for (let i = 0; i < 8; i++) {
            if (b & 1) r ^= a;
            b >>= 1;
            const hi = a & 0x80;
            a = (a << 1) & 0xFF;
            if (hi) a ^= 0x11D;
        }
        return r;
    }
    function rsEncode(data, ecLen) {
        // Generator polynomial
        let gen = [1];
        for (let i = 0; i < ecLen; i++) {
            const ng = new Array(gen.length + 1).fill(0);
            const factor = (1 << i) > 255 ? (1 << i) ^ 0x11D : (1 << i); // simplified
            let a = 1;
            for (let j = 0; j < i; j++) a = gfMul(a, 2);
            for (let j = 0; j < gen.length; j++) {
                ng[j] ^= gen[j];
                ng[j + 1] ^= gfMul(gen[j], a);
            }
            gen = ng;
        }
        const msg = [...data, ...new Array(ecLen).fill(0)];
        for (let i = 0; i < data.length; i++) {
            const coef = msg[i];
            if (coef === 0) continue;
            for (let j = 0; j < gen.length; j++) {
                msg[i + j] ^= gfMul(gen[j], coef);
            }
        }
        return msg.slice(data.length);
    }

    // Split into blocks and compute EC
    const blocks = [];
    let offset = 0;
    for (let b = 0; b < numBlocks; b++) {
        const blockData = dataBytes.slice(offset, offset + dataPerBlock);
        offset += dataPerBlock;
        const ec = rsEncode(blockData, ecPerBlock);
        blocks.push({ data: blockData, ec });
    }

    // Interleave
    const final = [];
    for (let i = 0; i < dataPerBlock; i++) for (const b of blocks) if (i < b.data.length) final.push(b.data[i]);
    for (let i = 0; i < ecPerBlock; i++) for (const b of blocks) final.push(b.ec[i]);

    // Place data on grid
    const allBits = [];
    for (const byte of final) for (let i = 7; i >= 0; i--) allBits.push((byte >> i) & 1);

    let bitIdx = 0;
    for (let right = size - 1; right >= 1; right -= 2) {
        if (right === 6) right = 5;
        for (let vert = 0; vert < size; vert++) {
            for (const dx of [0, -1]) {
                const x = right + dx;
                const y = ((Math.floor((size - 1 - right) / 2)) % 2 === 0) ? vert : size - 1 - vert;
                if (x < 0 || y < 0 || x >= size || y >= size) continue;
                if (mask[y][x]) continue;
                grid[y][x] = bitIdx < allBits.length ? allBits[bitIdx] : 0;
                bitIdx++;
            }
        }
    }

    // Apply mask pattern 0 (checkerboard) and XOR
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
        if (!mask[y][x] && (y + x) % 2 === 0) grid[y][x] ^= 1;
    }

    // Write format info (mask 0, ECC L = 01, format bits = 0b111011111000100)
    const fmtBits = [1,1,1,0,1,1,1,1,1,0,0,0,1,0,0];
    const fmtPositions = [];
    for (let i = 0; i < 6; i++) fmtPositions.push([8, i]);
    fmtPositions.push([8, 7], [8, 8], [7, 8]);
    for (let i = 9; i < 15; i++) fmtPositions.push([14 - i, 8]);
    for (let i = 0; i < 8; i++) fmtPositions.push([8, size - 8 + i]);
    for (let i = 0; i < 7; i++) fmtPositions.push([size - 1 - i, 8]);
    // Write first 15 to first set, second 15 to second set
    for (let i = 0; i < 15; i++) {
        const [y, x] = fmtPositions[i];
        grid[y][x] = fmtBits[i];
    }
    for (let i = 0; i < 15; i++) {
        const [y, x] = fmtPositions[15 + i];
        if (y >= 0 && y < size && x >= 0 && x < size) grid[y][x] = fmtBits[i];
    }

    return grid;
}

/**
 * Crée un élément DOM avec une classe CSS
 */
function creerElement(tag, className) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    return el;
}
