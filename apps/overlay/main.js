const { app, BrowserWindow, globalShortcut, Tray, Menu, ipcMain, shell, screen, dialog, Notification } = require('electron');
const { autoUpdater } = require('electron-updater');
const path = require('path');
const fs = require('fs');

const CFG = {
    DISPATCH_URL: 'https://exemple.tld/dispatch/dispatch.html?nui=1',
    LOGIN_URL: 'https://exemple.tld/connexion',
    DEFAULT_HOTKEY: 'F8',
    FALLBACK_HOTKEYS: ['F8', 'F9', 'F10', 'F12', 'CommandOrControl+Alt+D', 'CommandOrControl+Alt+F'],
    WIDTH: 1280,
    HEIGHT: 800,
    SETTINGS_FILE: path.join(app.getPath('userData'), 'overlay-settings.json'),
    UPDATE_CHECK_DELAY_MS: 4000
};

let overlayWindow = null;
let settingsWindow = null;
let tray = null;
let isVisible = false;
let settings = {
    x: null,
    y: null,
    width: CFG.WIDTH,
    height: CFG.HEIGHT,
    hotkey: CFG.DEFAULT_HOTKEY,
    autoUpdate: true,
    alwaysOnTop: true
};

function log() {
    try { console.log.apply(console, ['[MDT]'].concat(Array.from(arguments))); } catch(e) {}
}

function loadSettings() {
    try {
        if (fs.existsSync(CFG.SETTINGS_FILE)) {
            const data = JSON.parse(fs.readFileSync(CFG.SETTINGS_FILE, 'utf8'));
            settings = Object.assign(settings, data);
        }
    } catch (e) { log('loadSettings error', e.message); }
}

function saveSettings() {
    try {
        fs.writeFileSync(CFG.SETTINGS_FILE, JSON.stringify(settings, null, 2));
    } catch (e) { log('saveSettings error', e.message); }
}

function createOverlayWindow() {
    const display = screen.getPrimaryDisplay();
    const { width: sw, height: sh } = display.workAreaSize;

    const winX = settings.x !== null ? settings.x : Math.round((sw - settings.width) / 2);
    const winY = settings.y !== null ? settings.y : Math.round((sh - settings.height) / 2);

    overlayWindow = new BrowserWindow({
        x: winX, y: winY,
        width: settings.width, height: settings.height,
        minWidth: 900, minHeight: 600,
        title: 'RP MDT Dispatch',
        frame: false,
        transparent: true,
        resizable: true,
        movable: true,
        show: false,
        skipTaskbar: false,
        alwaysOnTop: settings.alwaysOnTop,
        fullscreenable: false,
        backgroundColor: '#00000000',
        icon: path.join(__dirname, 'assets', 'icon.ico'),
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: false,
            backgroundThrottling: false
        }
    });

    if (settings.alwaysOnTop) overlayWindow.setAlwaysOnTop(true, 'screen-saver');
    overlayWindow.setVisibleOnAllWorkspaces(true, { visibleOnFullScreen: true });

    overlayWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));

    overlayWindow.webContents.on('did-finish-load', () => {
        overlayWindow.webContents.send('config', {
            dispatchUrl: CFG.DISPATCH_URL,
            hotkey: settings.hotkey,
            version: app.getVersion()
        });
    });

    overlayWindow.webContents.setWindowOpenHandler(({ url }) => {
        try {
            const u = new URL(url);
            if (u.hostname.endsWith('exemple.tld')) return { action: 'allow' };
            shell.openExternal(url);
        } catch(e) {}
        return { action: 'deny' };
    });

    overlayWindow.on('moved', () => {
        const [x, y] = overlayWindow.getPosition();
        settings.x = x; settings.y = y; saveSettings();
    });
    overlayWindow.on('resized', () => {
        const [w, h] = overlayWindow.getSize();
        settings.width = w; settings.height = h; saveSettings();
    });

    overlayWindow.on('close', (e) => {
        if (!app.isQuiting) { e.preventDefault(); overlayWindow.hide(); isVisible = false; }
    });
}

function toggleOverlay() {
    if (!overlayWindow) return;
    if (isVisible) {
        overlayWindow.hide(); isVisible = false;
    } else {
        showOverlay();
    }
}

function showOverlay() {
    if (!overlayWindow) return;
    overlayWindow.show();
    overlayWindow.focus();
    if (settings.alwaysOnTop) overlayWindow.setAlwaysOnTop(true, 'screen-saver');
    isVisible = true;
}

function openSettingsWindow() {
    if (settingsWindow) { settingsWindow.show(); settingsWindow.focus(); return; }

    settingsWindow = new BrowserWindow({
        width: 480, height: 580,
        title: 'RP MDT Dispatch - Parametres',
        resizable: false,
        minimizable: false,
        maximizable: false,
        alwaysOnTop: true,
        show: false,
        backgroundColor: '#0f172a',
        icon: path.join(__dirname, 'assets', 'icon.ico'),
        webPreferences: {
            preload: path.join(__dirname, 'preload-settings.js'),
            contextIsolation: true,
            nodeIntegration: false
        }
    });

    settingsWindow.removeMenu();
    settingsWindow.loadFile(path.join(__dirname, 'renderer', 'settings.html'));

    settingsWindow.webContents.on('did-finish-load', () => {
        settingsWindow.webContents.send('settings-data', {
            hotkey: settings.hotkey,
            autoUpdate: settings.autoUpdate,
            alwaysOnTop: settings.alwaysOnTop,
            version: app.getVersion(),
            autoLaunch: app.getLoginItemSettings().openAtLogin
        });
        settingsWindow.show();
    });

    settingsWindow.on('closed', () => { settingsWindow = null; });
}

function registerHotkey(newHotkey) {
    globalShortcut.unregisterAll();
    let toRegister = newHotkey || settings.hotkey;
    let ok = false;
    try { ok = globalShortcut.register(toRegister, toggleOverlay); } catch (e) {}

    if (!ok) {
        for (const fb of CFG.FALLBACK_HOTKEYS) {
            if (fb === toRegister) continue;
            try {
                ok = globalShortcut.register(fb, toggleOverlay);
                if (ok) { toRegister = fb; log('Fallback hotkey used:', fb); break; }
            } catch (e) {}
        }
    }

    if (ok) {
        settings.hotkey = toRegister;
        saveSettings();
        rebuildTrayMenu();
        if (overlayWindow && overlayWindow.webContents) {
            overlayWindow.webContents.send('config', {
                dispatchUrl: CFG.DISPATCH_URL,
                hotkey: settings.hotkey,
                version: app.getVersion()
            });
        }
    }
    return { ok: ok, hotkey: toRegister };
}

function rebuildTrayMenu() {
    if (!tray) return;
    const menu = Menu.buildFromTemplate([
        { label: 'Afficher / Masquer  (' + settings.hotkey + ')', click: toggleOverlay },
        { label: 'Toujours au premier plan', type: 'checkbox', checked: settings.alwaysOnTop, click: (mi) => {
            settings.alwaysOnTop = mi.checked; saveSettings();
            if (overlayWindow) overlayWindow.setAlwaysOnTop(mi.checked, 'screen-saver');
        }},
        { type: 'separator' },
        { label: 'Parametres...', click: openSettingsWindow },
        { label: 'Recharger le dispatch', click: () => overlayWindow && overlayWindow.reload() },
        { label: 'Verifier les mises a jour', click: () => checkForUpdates(true) },
        { type: 'separator' },
        { label: 'Ouvrir exemple.tld', click: () => shell.openExternal('https://exemple.tld') },
        { label: 'Connexion Discord', click: () => shell.openExternal(CFG.LOGIN_URL) },
        { type: 'separator' },
        { label: 'Version ' + app.getVersion(), enabled: false },
        { label: 'Quitter RP MDT Dispatch', click: () => { app.isQuiting = true; app.quit(); } }
    ]);
    tray.setContextMenu(menu);
    tray.setToolTip('RP MDT Dispatch Overlay (' + settings.hotkey + ')');
}

function createTray() {
    const iconPath = path.join(__dirname, 'assets', 'icon.ico');
    try { tray = new Tray(iconPath); }
    catch (e) {
        try { tray = new Tray(path.join(__dirname, 'assets', 'icon.png')); }
        catch (e2) { log('Tray icon load failed'); return; }
    }
    rebuildTrayMenu();
    tray.on('click', toggleOverlay);
    tray.on('double-click', showOverlay);
}

function setupAutoUpdater() {
    autoUpdater.autoDownload = false;
    autoUpdater.autoInstallOnAppQuit = true;

    autoUpdater.on('error', (err) => {
        log('Update error:', err && err.message);
        if (settingsWindow) settingsWindow.webContents.send('update-status', { status: 'error', message: err && err.message });
    });

    autoUpdater.on('checking-for-update', () => {
        log('Checking for updates...');
        if (settingsWindow) settingsWindow.webContents.send('update-status', { status: 'checking' });
    });

    autoUpdater.on('update-available', (info) => {
        log('Update available:', info.version);
        if (settingsWindow) settingsWindow.webContents.send('update-status', { status: 'available', version: info.version });

        const choice = dialog.showMessageBoxSync(overlayWindow || null, {
            type: 'info',
            title: 'Mise a jour disponible',
            message: 'RP MDT Dispatch v' + info.version + ' est disponible.',
            detail: 'Tu utilises actuellement la version v' + app.getVersion() + '.\n\nVeux-tu telecharger la mise a jour maintenant ?',
            buttons: ['Telecharger maintenant', 'Plus tard'],
            defaultId: 0,
            cancelId: 1
        });
        if (choice === 0) {
            autoUpdater.downloadUpdate();
            if (settingsWindow) settingsWindow.webContents.send('update-status', { status: 'downloading' });
        }
    });

    autoUpdater.on('update-not-available', (info) => {
        log('No update available, current is up to date:', info.version);
        if (settingsWindow) settingsWindow.webContents.send('update-status', { status: 'not-available', version: app.getVersion() });
    });

    autoUpdater.on('download-progress', (p) => {
        const pct = Math.round(p.percent);
        log('Download progress:', pct + '%');
        if (settingsWindow) settingsWindow.webContents.send('update-status', { status: 'progress', percent: pct, transferred: p.transferred, total: p.total });
        if (tray) tray.setToolTip('RP MDT Dispatch - Telechargement ' + pct + '%');
    });

    autoUpdater.on('update-downloaded', (info) => {
        log('Update downloaded:', info.version);
        if (settingsWindow) settingsWindow.webContents.send('update-status', { status: 'downloaded', version: info.version });
        if (tray) tray.setToolTip('RP MDT Dispatch - Mise a jour prete');

        const choice = dialog.showMessageBoxSync(overlayWindow || null, {
            type: 'info',
            title: 'Mise a jour prete',
            message: 'La mise a jour v' + info.version + ' a ete telechargee.',
            detail: 'Redemarrer maintenant pour appliquer la mise a jour, ou plus tard a la prochaine fermeture.',
            buttons: ['Redemarrer maintenant', 'Plus tard'],
            defaultId: 0,
            cancelId: 1
        });
        if (choice === 0) {
            app.isQuiting = true;
            setImmediate(() => autoUpdater.quitAndInstall(true, true));
        }
    });
}

function checkForUpdates(showResultIfNone) {
    if (!app.isPackaged) {
        log('Not packaged, skip update check');
        if (showResultIfNone) {
            dialog.showMessageBoxSync({
                type: 'info',
                title: 'Mises a jour',
                message: 'Verification non disponible en mode developpement.'
            });
        }
        return;
    }
    autoUpdater.checkForUpdates().then((res) => {
        if (showResultIfNone && res && (!res.updateInfo || res.updateInfo.version === app.getVersion())) {
            new Notification({
                title: 'RP MDT Dispatch',
                body: 'Tu utilises deja la derniere version (v' + app.getVersion() + ')',
                icon: path.join(__dirname, 'assets', 'icon.ico')
            }).show();
        }
    }).catch((err) => {
        log('Update check failed:', err.message);
        if (showResultIfNone) {
            dialog.showMessageBoxSync({
                type: 'warning',
                title: 'Verification mise a jour',
                message: 'Impossible de verifier les mises a jour.',
                detail: err.message
            });
        }
    });
}

ipcMain.on('overlay-close',         () => { if (overlayWindow) { overlayWindow.hide(); isVisible = false; } });
ipcMain.on('overlay-reload',        () => { if (overlayWindow) overlayWindow.reload(); });
ipcMain.on('overlay-quit',          () => { app.isQuiting = true; app.quit(); });
ipcMain.on('overlay-open-external', (e, url) => { if (url && url.startsWith('http')) shell.openExternal(url); });
ipcMain.on('overlay-open-settings', () => openSettingsWindow());

ipcMain.on('settings-close', () => { if (settingsWindow) settingsWindow.close(); });
ipcMain.handle('settings-set-hotkey', (e, newHotkey) => {
    const r = registerHotkey(newHotkey);
    return r;
});
ipcMain.handle('settings-set-toggle', (e, key, value) => {
    if (key === 'autoUpdate' || key === 'alwaysOnTop') {
        settings[key] = !!value;
        if (key === 'alwaysOnTop' && overlayWindow) overlayWindow.setAlwaysOnTop(!!value, 'screen-saver');
        saveSettings();
        rebuildTrayMenu();
    }
    return { ok: true };
});
ipcMain.handle('settings-set-autolaunch', (e, value) => {
    app.setLoginItemSettings({ openAtLogin: !!value, openAsHidden: true });
    return { ok: true, autoLaunch: app.getLoginItemSettings().openAtLogin };
});
ipcMain.handle('settings-check-updates', () => {
    checkForUpdates(false);
    return { ok: true };
});

const lock = app.requestSingleInstanceLock();
if (!lock) {
    app.quit();
} else {
    app.on('second-instance', () => { showOverlay(); });

    app.whenReady().then(() => {
        log('Starting RP MDT Dispatch v' + app.getVersion());
        loadSettings();
        createOverlayWindow();
        createTray();
        registerHotkey(settings.hotkey);
        setupAutoUpdater();

        const firstRun = !fs.existsSync(CFG.SETTINGS_FILE);
        if (firstRun) {
            setTimeout(showOverlay, 500);
            saveSettings();
        }

        if (settings.autoUpdate) {
            setTimeout(() => checkForUpdates(false), CFG.UPDATE_CHECK_DELAY_MS);
        }
    });

    app.on('window-all-closed', (e) => { e.preventDefault(); });

    app.on('will-quit', () => {
        globalShortcut.unregisterAll();
        saveSettings();
    });
}
