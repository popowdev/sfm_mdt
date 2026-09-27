const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('mdtSettings', {
    close:           () => ipcRenderer.send('settings-close'),
    setHotkey:       (hk) => ipcRenderer.invoke('settings-set-hotkey', hk),
    setToggle:       (key, value) => ipcRenderer.invoke('settings-set-toggle', key, value),
    setAutoLaunch:   (v) => ipcRenderer.invoke('settings-set-autolaunch', v),
    checkUpdates:    () => ipcRenderer.invoke('settings-check-updates'),
    onSettingsData:  (cb) => ipcRenderer.on('settings-data', (_, d) => cb(d)),
    onUpdateStatus:  (cb) => ipcRenderer.on('update-status', (_, d) => cb(d))
});
