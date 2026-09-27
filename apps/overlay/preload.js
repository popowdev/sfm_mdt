const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('mdtOverlay', {
    close:        () => ipcRenderer.send('overlay-close'),
    reload:       () => ipcRenderer.send('overlay-reload'),
    quit:         () => ipcRenderer.send('overlay-quit'),
    openExternal: (url) => ipcRenderer.send('overlay-open-external', url),
    openSettings: () => ipcRenderer.send('overlay-open-settings'),
    onConfig:     (cb) => ipcRenderer.on('config', (_, cfg) => cb(cfg))
});
