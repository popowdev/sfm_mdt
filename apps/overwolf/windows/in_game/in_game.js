(function() {
    'use strict';

    var DISPATCH_URL = 'https://exemple.tld/dispatch/dispatch.html?nui=1';
    var HOTKEY_NAME = 'mdt_dispatch_showhide';

    var frame = document.getElementById('dispatch-frame');
    var loading = document.getElementById('loading');
    var wrap = document.getElementById('frame-wrap');
    var hotkeyLabel = document.getElementById('title-hotkey');
    var currentWindowId = null;

    var opacities = [100, 75, 50];
    var opIdx = 0;

    overwolf.windows.getCurrentWindow(function(r) {
        if (r.success) currentWindowId = r.window.id;
    });

    frame.addEventListener('load', function() { loading.classList.add('hidden'); });
    frame.src = DISPATCH_URL;

    document.getElementById('btn-reload').addEventListener('click', function() {
        loading.classList.remove('hidden');
        frame.src = DISPATCH_URL + '&t=' + Date.now();
    });

    document.getElementById('btn-close').addEventListener('click', function() {
        if (currentWindowId) overwolf.windows.hide(currentWindowId);
    });

    var btnOp = document.getElementById('btn-opacity');
    btnOp.addEventListener('click', function() {
        opIdx = (opIdx + 1) % opacities.length;
        var op = opacities[opIdx];
        wrap.style.opacity = op / 100;
        btnOp.textContent = op + '%';
    });

    overwolf.settings.hotkeys.get(function(r) {
        try {
            if (r && r.hotkeys && r.hotkeys.global) {
                var hk = r.hotkeys.global.filter(function(h) { return h.name === HOTKEY_NAME; })[0];
                if (hk && hk.binding) hotkeyLabel.textContent = hk.binding;
            }
        } catch(e) {}
    });

    overwolf.settings.hotkeys.onChanged.addListener(function(e) {
        if (e && e.name === HOTKEY_NAME && e.binding) hotkeyLabel.textContent = e.binding;
    });
})();
