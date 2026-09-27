const { app, BrowserWindow, Tray, Menu, shell, dialog, Notification } = require('electron');
const { autoUpdater } = require('electron-updater');
const path = require('path');
const fs = require('fs');

const CFG = {
    HOME_URL: 'https://exemple.tld',
    HOSTS_OK: ['exemple.tld', 'exemple.tld'],
    WIDTH: 1400,
    HEIGHT: 900,
    STATE_FILE: path.join(app.getPath('userData'), 'window-state.json'),
    UPDATE_CHECK_DELAY_MS: 5000
};

let win = null;
let tray = null;
let state = { x: null, y: null, width: CFG.WIDTH, height: CFG.HEIGHT, maximized: false, autoUpdate: true };

function log() { try { console.log.apply(console, ['[MDT-App]'].concat(Array.from(arguments))); } catch (e) {} }

function loadState() {
    try { if (fs.existsSync(CFG.STATE_FILE)) state = Object.assign(state, JSON.parse(fs.readFileSync(CFG.STATE_FILE, 'utf8'))); }
    catch (e) { log('loadState', e.message); }
}
function saveState() {
    try { fs.writeFileSync(CFG.STATE_FILE, JSON.stringify(state, null, 2)); }
    catch (e) { log('saveState', e.message); }
}

function hostAllowed(url) {
    try { return CFG.HOSTS_OK.includes(new URL(url).hostname); } catch (e) { return false; }
}

const PAUSE_STYLE_JS =
    "(function(){var id='__mdt_pause_style';if(!document.getElementById(id)){var s=document.createElement('style');" +
    "s.id=id;s.textContent='html.mdt-bg-paused *,html.mdt-bg-paused *::before,html.mdt-bg-paused *::after{animation-play-state:paused !important;}';" +
    "(document.head||document.documentElement).appendChild(s);}})();";

function applyBackgroundState(bg) {
    if (!win || win.isDestroyed()) return;
    const js =
        "try{document.documentElement.classList.toggle('mdt-bg-paused'," + (bg ? 'true' : 'false') + ");" +
        "window.__MDT_BG=" + (bg ? 'true' : 'false') + ";" +
        "window.dispatchEvent(new CustomEvent('mdt-bg-change',{detail:{background:" + (bg ? 'true' : 'false') + "}}));}catch(e){}";
    win.webContents.executeJavaScript(js, true).catch(function () {});
}

function createWindow() {
    win = new BrowserWindow({
        x: state.x, y: state.y,
        width: state.width, height: state.height,
        minWidth: 900, minHeight: 600,
        title: 'RP MDT',
        backgroundColor: '#0b1220',
        show: false,
        autoHideMenuBar: true,
        icon: path.join(__dirname, 'assets', 'icon.ico'),
        webPreferences: {
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
            backgroundThrottling: true,
            spellcheck: false
        }
    });

    if (state.maximized) win.maximize();
    win.removeMenu();
    win.loadURL(CFG.HOME_URL);

    win.once('ready-to-show', function () { win.show(); });

    win.webContents.on('did-finish-load', function () {
        win.webContents.executeJavaScript(PAUSE_STYLE_JS, true).catch(function () {});
        applyBackgroundState(!win.isFocused());
    });

    win.webContents.setWindowOpenHandler(function (d) {
        if (hostAllowed(d.url)) { win.loadURL(d.url); }
        else if (/^https?:/.test(d.url)) { shell.openExternal(d.url); }
        return { action: 'deny' };
    });
    win.webContents.on('will-navigate', function (e, url) {
        if (!hostAllowed(url) && /^https?:/.test(url)) { e.preventDefault(); shell.openExternal(url); }
    });

    win.webContents.on('before-input-event', function (e, input) {
        if (input.type !== 'keyDown') return;
        const k = (input.key || '').toLowerCase();
        if (k === 'f5' || (input.control && k === 'r')) { win.reload(); e.preventDefault(); }
        if (k === 'f11') { win.setFullScreen(!win.isFullScreen()); e.preventDefault(); }
    });

    win.on('blur', function () { applyBackgroundState(true); });
    win.on('focus', function () { applyBackgroundState(false); });
    win.on('minimize', function () { applyBackgroundState(true); });
    win.on('restore', function () { applyBackgroundState(false); });

    const persist = function () {
        if (!win || win.isDestroyed()) return;
        state.maximized = win.isMaximized();
        if (!state.maximized && !win.isMinimized()) {
            const b = win.getBounds();
            state.x = b.x; state.y = b.y; state.width = b.width; state.height = b.height;
        }
        saveState();
    };
    win.on('resize', persist);
    win.on('move', persist);
    win.on('maximize', persist);
    win.on('unmaximize', persist);

    win.on('close', function (e) {
        if (!app.isQuiting) { e.preventDefault(); win.hide(); }
        else { persist(); }
    });
}

function showWin() {
    if (!win) return;
    if (win.isMinimized()) win.restore();
    win.show();
    win.focus();
}

function createTray() {
    try { tray = new Tray(path.join(__dirname, 'assets', 'icon.ico')); }
    catch (e) {
        try { tray = new Tray(path.join(__dirname, 'assets', 'icon.png')); }
        catch (e2) { log('Tray icon load failed'); return; }
    }
    const menu = Menu.buildFromTemplate([
        { label: 'Ouvrir RP MDT', click: showWin },
        { label: 'Recharger', click: function () { if (win) win.reload(); } },
        { label: 'Accueil (exemple.tld)', click: function () { if (win) win.loadURL(CFG.HOME_URL); } },
        { type: 'separator' },
        { label: 'Verifier les mises a jour', click: function () { checkForUpdates(true); } },
        { label: 'Ouvrir dans le navigateur', click: function () { shell.openExternal(CFG.HOME_URL); } },
        { type: 'separator' },
        { label: 'Version ' + app.getVersion(), enabled: false },
        { label: 'Quitter', click: function () { app.isQuiting = true; app.quit(); } }
    ]);
    tray.setContextMenu(menu);
    tray.setToolTip('RP MDT');
    tray.on('double-click', showWin);
}

function setupAutoUpdater() {
    autoUpdater.autoDownload = false;
    autoUpdater.autoInstallOnAppQuit = true;

    autoUpdater.on('update-available', function (info) {
        const choice = dialog.showMessageBoxSync(win || null, {
            type: 'info',
            title: 'Mise a jour disponible',
            message: 'RP MDT v' + info.version + ' est disponible.',
            detail: 'Version actuelle : v' + app.getVersion() + '.\n\nTelecharger maintenant ?',
            buttons: ['Telecharger', 'Plus tard'],
            defaultId: 0, cancelId: 1
        });
        if (choice === 0) autoUpdater.downloadUpdate();
    });

    autoUpdater.on('update-downloaded', function (info) {
        const choice = dialog.showMessageBoxSync(win || null, {
            type: 'info',
            title: 'Mise a jour prete',
            message: 'La mise a jour v' + info.version + ' a ete telechargee.',
            detail: 'Redemarrer maintenant pour l\'appliquer ?',
            buttons: ['Redemarrer', 'Plus tard'],
            defaultId: 0, cancelId: 1
        });
        if (choice === 0) { app.isQuiting = true; setImmediate(function () { autoUpdater.quitAndInstall(true, true); }); }
    });

    autoUpdater.on('error', function (err) { log('Update error:', err && err.message); });
}

function checkForUpdates(showIfNone) {
    if (!app.isPackaged) {
        if (showIfNone) dialog.showMessageBoxSync({ type: 'info', title: 'Mises a jour', message: 'Indisponible en mode developpement.' });
        return;
    }
    autoUpdater.checkForUpdates().then(function (res) {
        if (showIfNone && res && (!res.updateInfo || res.updateInfo.version === app.getVersion())) {
            try {
                new Notification({ title: 'RP MDT', body: 'Tu utilises deja la derniere version (v' + app.getVersion() + ')', icon: path.join(__dirname, 'assets', 'icon.ico') }).show();
            } catch (e) {}
        }
    }).catch(function (err) {
        if (showIfNone) dialog.showMessageBoxSync({ type: 'warning', title: 'Mise a jour', message: 'Impossible de verifier les mises a jour.', detail: err && err.message });
    });
}

const lock = app.requestSingleInstanceLock();
if (!lock) {
    app.quit();
} else {
    app.on('second-instance', function () { showWin(); });

    app.whenReady().then(function () {
        log('Starting RP MDT v' + app.getVersion());
        loadState();
        createWindow();
        createTray();
        setupAutoUpdater();
        if (state.autoUpdate) setTimeout(function () { checkForUpdates(false); }, CFG.UPDATE_CHECK_DELAY_MS);
    });

    app.on('window-all-closed', function (e) { e.preventDefault(); });

    app.on('will-quit', function () { saveState(); });
}
