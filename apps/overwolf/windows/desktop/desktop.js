(function() {
    'use strict';

    var DISPATCH_URL = 'https://exemple.tld/dispatch/dispatch.html?nui=1';
    var GTAV_CLASS_ID = 8032;

    var frame = document.getElementById('dispatch-frame');
    var loading = document.getElementById('loading');
    var modeLabel = document.getElementById('title-mode');
    var currentWindowId = null;

    overwolf.windows.getCurrentWindow(function(r) {
        if (r.success) currentWindowId = r.window.id;
    });

    frame.addEventListener('load', function() { loading.classList.add('hidden'); });
    frame.src = DISPATCH_URL;

    document.getElementById('btn-reload').addEventListener('click', function() {
        loading.classList.remove('hidden');
        frame.src = DISPATCH_URL + '&t=' + Date.now();
    });

    document.getElementById('btn-min').addEventListener('click', function() {
        if (currentWindowId) overwolf.windows.minimize(currentWindowId);
    });

    document.getElementById('btn-close').addEventListener('click', function() {
        if (currentWindowId) overwolf.windows.hide(currentWindowId);
    });

    overwolf.games.getRunningGameInfo(function(info) {
        if (info && info.isRunning && info.classId === GTAV_CLASS_ID) {
            modeLabel.textContent = 'GTA V detecte';
            modeLabel.style.color = '#10b981';
        }
    });
})();
