(function() {
    'use strict';

    var GTAV_CLASS_ID = 8032;
    var HOTKEY_NAME = 'mdt_dispatch_showhide';
    var WIN_DESKTOP = 'desktop';
    var WIN_INGAME = 'in_game';

    var currentlyRunning = false;

    function log() {
        try { console.log.apply(console, ['[MDT-BG]'].concat(Array.prototype.slice.call(arguments))); } catch(e) {}
    }

    function obtainWindow(name, cb) {
        overwolf.windows.obtainDeclaredWindow(name, function(r) {
            if (r.success) cb(null, r.window);
            else cb(r);
        });
    }

    function restoreWindow(name) {
        obtainWindow(name, function(err, win) {
            if (err) return log('obtain ' + name + ' fail', err);
            overwolf.windows.restore(win.id, function(rr) { log('restore ' + name, rr); });
        });
    }

    function hideWindow(name) {
        obtainWindow(name, function(err, win) {
            if (err) return;
            overwolf.windows.hide(win.id);
        });
    }

    function toggleWindow(name) {
        obtainWindow(name, function(err, win) {
            if (err) return;
            if (win.isVisible) overwolf.windows.hide(win.id);
            else overwolf.windows.restore(win.id);
        });
    }

    function onGameLaunched() {
        log('GTA V detected');
        hideWindow(WIN_DESKTOP);
        restoreWindow(WIN_INGAME);
    }

    function onGameClosed() {
        log('GTA V closed');
        hideWindow(WIN_INGAME);
        restoreWindow(WIN_DESKTOP);
    }

    function onGameInfoUpdated(e) {
        if (!e || !e.gameInfo) return;
        if (e.gameInfo.classId !== GTAV_CLASS_ID) return;
        var nowRunning = !!e.gameInfo.isRunning;
        if (!currentlyRunning && nowRunning) { currentlyRunning = true; onGameLaunched(); }
        else if (currentlyRunning && !nowRunning) { currentlyRunning = false; onGameClosed(); }
    }

    function onHotkeyPressed(e) {
        if (!e || e.name !== HOTKEY_NAME) return;
        if (currentlyRunning) toggleWindow(WIN_INGAME);
        else toggleWindow(WIN_DESKTOP);
    }

    log('Background started');
    overwolf.games.onGameInfoUpdated.addListener(onGameInfoUpdated);
    overwolf.settings.hotkeys.onPressed.addListener(onHotkeyPressed);

    overwolf.games.getRunningGameInfo(function(info) {
        if (info && info.isRunning && info.classId === GTAV_CLASS_ID) {
            currentlyRunning = true;
            onGameLaunched();
        } else {
            restoreWindow(WIN_DESKTOP);
        }
    });
})();
