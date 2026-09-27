(function() {
    'use strict';

    const wrap = document.getElementById('overlay-wrap');
    const frame = document.getElementById('dispatch-frame');
    const loading = document.getElementById('loading');
    const btnReload = document.getElementById('btn-reload');
    const btnClose = document.getElementById('btn-close');
    const btnOp = document.getElementById('btn-opacity');
    const verEl = document.getElementById('title-version');
    const hkEl = document.getElementById('title-hotkey');

    let dispatchUrl = 'https://exemple.tld/dispatch/dispatch.html?nui=1';

    const opacities = [100, 85, 70, 50];
    let opIdx = 0;

    function applyOpacity() {
        const op = opacities[opIdx];
        wrap.style.opacity = op / 100;
        btnOp.textContent = op + '%';
    }

    btnOp.addEventListener('click', () => {
        opIdx = (opIdx + 1) % opacities.length;
        applyOpacity();
    });

    btnReload.addEventListener('click', () => {
        loading.classList.remove('hidden');
        frame.src = 'about:blank';
        setTimeout(() => { frame.src = dispatchUrl + '&t=' + Date.now(); }, 50);
    });

    btnClose.addEventListener('click', () => {
        if (window.mdtOverlay) window.mdtOverlay.close();
    });

    const btnSettings = document.getElementById('btn-settings');
    if (btnSettings) {
        btnSettings.addEventListener('click', () => {
            if (window.mdtOverlay) window.mdtOverlay.openSettings();
        });
    }

    frame.addEventListener('load', () => {
        if (frame.src && frame.src.indexOf('about:blank') === -1) {
            loading.classList.add('hidden');
        }
    });

    if (window.mdtOverlay) {
        window.mdtOverlay.onConfig((cfg) => {
            if (cfg.dispatchUrl) dispatchUrl = cfg.dispatchUrl;
            if (cfg.version) verEl.textContent = 'v' + cfg.version;
            if (cfg.hotkey) hkEl.textContent = cfg.hotkey;
            frame.src = dispatchUrl;
        });
    } else {
        frame.src = dispatchUrl;
    }

    setTimeout(() => {
        if (!loading.classList.contains('hidden')) {
            loading.querySelector('.loading-text').textContent = 'Chargement long...';
            loading.querySelector('.loading-hint').innerHTML = 'Vérifie ta connexion. Tu peux aussi : <br>• <a href="#" id="link-reload" style="color:#67e8f9;">Recharger</a> • <a href="#" id="link-browser" style="color:#67e8f9;">Ouvrir sur le site</a>';
            setTimeout(() => {
                const lr = document.getElementById('link-reload');
                const lb = document.getElementById('link-browser');
                if (lr) lr.onclick = (e) => { e.preventDefault(); btnReload.click(); };
                if (lb) lb.onclick = (e) => { e.preventDefault(); if (window.mdtOverlay) window.mdtOverlay.openExternal(dispatchUrl); };
            }, 50);
        }
    }, 15000);

    applyOpacity();
})();
