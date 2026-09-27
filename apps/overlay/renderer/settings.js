(function() {
    'use strict';

    const hkDisplay = document.getElementById('hk-display');
    const hkBtn = document.getElementById('hk-btn');
    const hkHelp = document.getElementById('hk-help');
    const togAot = document.getElementById('toggle-aot');
    const togAuto = document.getElementById('toggle-autolaunch');
    const togUpd = document.getElementById('toggle-autoupdate');
    const updIcon = document.getElementById('update-icon');
    const updText = document.getElementById('update-text');
    const progBar = document.getElementById('progress-bar');
    const progFill = document.getElementById('progress-fill');
    const checkNow = document.getElementById('check-now');
    const verText = document.getElementById('version-text');
    const btnClose = document.getElementById('btn-close');

    let capturing = false;
    let currentHotkey = 'F8';

    function setToggle(el, on) {
        if (on) el.classList.add('on');
        else el.classList.remove('on');
    }

    function startCapture() {
        capturing = true;
        hkDisplay.textContent = 'Appuie sur une touche...';
        hkDisplay.classList.add('capturing');
        hkDisplay.classList.remove('error');
        hkBtn.textContent = 'Annuler';
        hkBtn.classList.add('cancel');
        hkHelp.style.display = 'block';
    }

    function stopCapture(restoreText) {
        capturing = false;
        if (restoreText) hkDisplay.textContent = currentHotkey;
        hkDisplay.classList.remove('capturing');
        hkBtn.textContent = 'Modifier';
        hkBtn.classList.remove('cancel');
        hkHelp.style.display = 'none';
    }

    hkBtn.addEventListener('click', () => {
        if (capturing) { stopCapture(true); return; }
        startCapture();
    });

    document.addEventListener('keydown', (e) => {
        if (!capturing) return;
        e.preventDefault();

        const justModifier = ['Control','Shift','Alt','Meta','OS'].includes(e.key);
        if (justModifier) return;

        if (e.key === 'Escape') { stopCapture(true); return; }

        const parts = [];
        if (e.ctrlKey) parts.push('CommandOrControl');
        if (e.altKey) parts.push('Alt');
        if (e.shiftKey) parts.push('Shift');
        if (e.metaKey) parts.push('Super');

        let key = e.key;
        if (/^F\d+$/.test(key)) {
        } else if (key === ' ') key = 'Space';
        else if (key === 'ArrowUp') key = 'Up';
        else if (key === 'ArrowDown') key = 'Down';
        else if (key === 'ArrowLeft') key = 'Left';
        else if (key === 'ArrowRight') key = 'Right';
        else if (key.length === 1) key = key.toUpperCase();

        parts.push(key);
        const hotkeyStr = parts.join('+');

        hkDisplay.textContent = 'Verification...';
        window.mdtSettings.setHotkey(hotkeyStr).then((res) => {
            if (res.ok) {
                currentHotkey = res.hotkey;
                hkDisplay.textContent = currentHotkey;
                if (res.hotkey !== hotkeyStr) {
                    hkDisplay.classList.add('error');
                    setTimeout(() => hkDisplay.classList.remove('error'), 2000);
                }
            } else {
                hkDisplay.textContent = currentHotkey;
                hkDisplay.classList.add('error');
                setTimeout(() => hkDisplay.classList.remove('error'), 2000);
            }
            stopCapture(false);
        });
    });

    togAot.addEventListener('click', () => {
        const on = !togAot.classList.contains('on');
        setToggle(togAot, on);
        window.mdtSettings.setToggle('alwaysOnTop', on);
    });
    togAuto.addEventListener('click', () => {
        const on = !togAuto.classList.contains('on');
        setToggle(togAuto, on);
        window.mdtSettings.setAutoLaunch(on);
    });
    togUpd.addEventListener('click', () => {
        const on = !togUpd.classList.contains('on');
        setToggle(togUpd, on);
        window.mdtSettings.setToggle('autoUpdate', on);
    });

    checkNow.addEventListener('click', () => {
        updIcon.className = 'status-icon checking';
        updText.textContent = 'Verification en cours...';
        progBar.style.display = 'none';
        window.mdtSettings.checkUpdates();
    });

    window.mdtSettings.onUpdateStatus((s) => {
        updIcon.className = 'status-icon ' + (s.status || 'idle');
        progBar.style.display = 'none';
        switch (s.status) {
            case 'checking':
                updText.textContent = 'Verification...';
                break;
            case 'available':
                updText.textContent = 'Mise a jour disponible : v' + s.version;
                break;
            case 'not-available':
                updText.textContent = 'Tu utilises la derniere version';
                break;
            case 'downloading':
                updText.textContent = 'Telechargement en cours...';
                progBar.style.display = 'block';
                progFill.style.width = '0%';
                break;
            case 'progress':
                updText.textContent = 'Telechargement : ' + s.percent + '% (' + Math.round(s.transferred/1024/1024) + ' / ' + Math.round(s.total/1024/1024) + ' MB)';
                progBar.style.display = 'block';
                progFill.style.width = s.percent + '%';
                break;
            case 'downloaded':
                updText.textContent = 'Mise a jour prete a installer (v' + s.version + ')';
                break;
            case 'error':
                updText.textContent = 'Erreur : ' + (s.message || 'inconnue');
                break;
            default:
                updText.textContent = 'Aucune verification en cours';
        }
    });

    window.mdtSettings.onSettingsData((d) => {
        currentHotkey = d.hotkey || 'F8';
        hkDisplay.textContent = currentHotkey;
        setToggle(togAot, !!d.alwaysOnTop);
        setToggle(togAuto, !!d.autoLaunch);
        setToggle(togUpd, !!d.autoUpdate);
        if (d.version) verText.textContent = 'RP MDT Dispatch v' + d.version;
    });

    btnClose.addEventListener('click', () => window.mdtSettings.close());
})();
