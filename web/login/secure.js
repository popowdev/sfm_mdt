
(function() {
    var PAGE_LOGIN = "/login/connexion";
    var PAGE_MENU  = "/";
    var PERMS_API  = "/login/permissions_api.php";
    var AUTH_API   = "/login/auth_api.php";
    var CACHE_TTL  = 300;
    var REFRESH_INTERVAL = 5 * 60 * 1000;
    var SUPER_ADMINS = ["543211066805452805"];

    var MODULE_RULES = [
        { path: '/', exact: true, public: true },
        { path: '/index', public: true },
        { path: '/login/', public: true },

        { path: '/td/documentation', public: true },
        { path: '/td/suivi', public: true },
        { path: '/td/', module: 'td' },

        { path: '/mdt/', module: 'mdt' },
        { path: '/sd/', module: 'sd' },
        { path: '/upload', public: true },
        { path: '/admin', module: 'admin' },
        { path: '/plainte/', module: 'plainte' },
        { path: '/saisies/', module: 'saisies' },
        { path: '/dispatch/', module: 'dispatch' },
        { path: '/carte/', module: 'dispatch' },
        { path: '/recherche/', module: 'dispatch' },
        { path: '/npu/', module: 'npu' },

        { path: '/cid/', public: true },
        { path: '/annonces/', public: true },
        { path: '/documents/', public: true },
        { path: '/penal/', public: true },
        { path: '/messagerie/', public: true },
        { path: '/heures', public: true },
        { path: '/parametres', public: true },
        { path: '/dev', public: true }
    ];

    var currentPath = window.location.pathname;
    var isLoginPage = currentPath.indexOf("/login/connexion") !== -1;
    var isProfilPage = currentPath.indexOf("/login/profil") !== -1;

    var currentRule = matchRule(currentPath);
    var currentModule = (currentRule && currentRule.module) ? currentRule.module : null;
    var isUnmapped = !currentRule;

    if (isUnmapped) {
        console.warn('[secure.js] Page non declaree : ' + currentPath +
            ' — acces refuse par defaut. Ajoutez une regle dans MODULE_RULES.');
    }

    if ((currentModule || isUnmapped) && !isLoginPage && !isProfilPage) {
        document.documentElement.style.opacity = '0';
        document.documentElement.style.transition = 'opacity .15s';
    }

    var token = localStorage.getItem("mdt_auth_token");
    var user = null;
    try { var raw = localStorage.getItem("mdt_user"); if (raw) user = JSON.parse(raw); } catch(e) { user = null; }

    if (!token) {
        try {
            var oldToken = localStorage.getItem("mdt_token");
            if (oldToken) {
                var parsed = JSON.parse(oldToken);
                var now = Math.floor(Date.now() / 1000);
                if (parsed.expires_at && parsed.expires_at > now) {
                    user = {
                        id: parsed.id, username: parsed.username, discord_id: parsed.id,
                        discord_username: parsed.username, discord_avatar: parsed.avatar,
                        discord_roles: parsed.roles || []
                    };
                    if (isLoginPage) { window.location.href = PAGE_MENU; return; }
                    checkModuleAccess(user);
                    exposeUser(user);
                    return;
                } else {
                    localStorage.removeItem("mdt_token");
                    localStorage.removeItem("mdt_session");
                }
            }
        } catch(e) {
            localStorage.removeItem("mdt_token");
            localStorage.removeItem("mdt_session");
        }
    }

    if (!token) {
        if (!isLoginPage) window.location.replace(PAGE_LOGIN);
        return;
    }

    if (isLoginPage) {
        window.location.href = PAGE_MENU;
        return;
    }

    if (user) {
        exposeUser(user);
        checkModuleAccess(user);
    } else {
        fetch(AUTH_API + "?action=validate", { headers: { "Authorization": "Bearer " + token } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.valid && data.user) {
                    user = data.user;
                    localStorage.setItem("mdt_user", JSON.stringify(user));
                    syncLegacy(user);
                    exposeUser(user);
                    checkModuleAccess(user);
                } else {
                    clearSession();
                    window.location.replace(PAGE_LOGIN);
                }
            })
            .catch(function() {
                revealPage();
            });
    }

    var lastValidation = parseInt(localStorage.getItem("mdt_last_validate") || "0", 10);
    var nowSec = Math.floor(Date.now() / 1000);
    if (token && nowSec - lastValidation > 300) {
        fetch(AUTH_API + "?action=validate", { headers: { "Authorization": "Bearer " + token } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.valid && data.user) {
                    localStorage.setItem("mdt_user", JSON.stringify(data.user));
                    localStorage.setItem("mdt_last_validate", String(Math.floor(Date.now() / 1000)));
                    syncLegacy(data.user);
                } else {
                    clearSession();
                    window.location.replace(PAGE_LOGIN);
                }
            })
            .catch(function() {});
    }

    if (token && !isLoginPage) {
        setTimeout(function() { refreshUserRoles(); }, 3000);
        setInterval(function() { refreshUserRoles(); }, REFRESH_INTERVAL);
    }

    function matchRule(path) {
        for (var i = 0; i < MODULE_RULES.length; i++) {
            var r = MODULE_RULES[i];
            if (r.exact ? (path === r.path) : (path.indexOf(r.path) === 0)) return r;
        }
        return null;
    }

    function checkModuleAccess(u) {
        if (isUnmapped) { denyAccess(); return; }
        if (!currentModule) { revealPage(); return; }

        var uid = u.discord_id || String(u.id);
        if (SUPER_ADMINS.indexOf(uid) !== -1) { revealPage(); return; }

        var devCache = sessionStorage.getItem('mdt_is_dev');
        if (devCache === 'true') { revealPage(); return; }
        if (devCache === null && uid) {
            fetch("/maintenance_api.php?action=is_dev&discord_id=" + encodeURIComponent(uid))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    sessionStorage.setItem('mdt_is_dev', data.is_dev ? 'true' : 'false');
                    if (data.is_dev) { revealPage(); return; }
                    doModuleCheck(u);
                })
                .catch(function() { doModuleCheck(u); });
            return;
        }

        doModuleCheck(u);
    }

    function doModuleCheck(u) {
        var cacheKey = 'mdt_perm_' + currentModule;
        var cached = sessionStorage.getItem(cacheKey);
        if (cached) {
            try {
                var c = JSON.parse(cached);
                var age = Math.floor(Date.now() / 1000) - c.ts;
                if (age < CACHE_TTL) {
                    if (c.access) { revealPage(); return; }
                    else { denyAccess(); return; }
                }
            } catch(e) {}
        }

        var localResult = checkLocalAccess(u);
        if (localResult === true) {
            revealPage();
            serverCheckAccess(false);
        } else if (localResult === false) {
            serverCheckAccess(true);
        } else {
            serverCheckAccess(true);
        }
    }

    function checkLocalAccess(u) {
        var permsCache = sessionStorage.getItem('mdt_all_perms');
        if (!permsCache) return null;

        try {
            var perms = JSON.parse(permsCache);
            var modulePerms = perms[currentModule];
            if (modulePerms === undefined) return null;
            if (modulePerms === true) return true;

            var userRoles = u.discord_roles || [];
            if (!Array.isArray(userRoles)) return false;
            for (var i = 0; i < userRoles.length; i++) {
                if (modulePerms.indexOf(userRoles[i]) !== -1) return true;
            }
            return false;
        } catch(e) { return null; }
    }

    function serverCheckAccess(blocking) {
        fetch(PERMS_API + "?action=check_access&module_key=" + encodeURIComponent(currentModule), { headers: { "Authorization": "Bearer " + token } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                sessionStorage.setItem('mdt_perm_' + currentModule, JSON.stringify({
                    access: data.access, ts: Math.floor(Date.now() / 1000)
                }));

                if (data.access) {
                    revealPage();
                } else {
                    denyAccess();
                }
            })
            .catch(function() {
                if (blocking) revealPage();
            });
    }

    function denyAccess() {
        if (currentPath === PAGE_MENU || currentPath === '/index' || currentPath === '/index.html') {
            revealPage();
            return;
        }
        sessionStorage.setItem('mdt_access_denied', currentModule || currentPath);
        window.location.replace(PAGE_MENU);
    }

    function revealPage() {
        document.documentElement.style.opacity = '1';
    }

    function exposeUser(u) {
        window.MDT_USER = u;
    }

    function refreshUserRoles() {
        if (!token) return;
        fetch(PERMS_API + "?action=refresh_user_roles", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Authorization": "Bearer " + token },
            body: JSON.stringify({})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.user) {
                localStorage.setItem("mdt_user", JSON.stringify(data.user));
                syncLegacy(data.user);
                window.MDT_USER = data.user;
                for (var i = sessionStorage.length - 1; i >= 0; i--) {
                    var key = sessionStorage.key(i);
                    if (key && key.indexOf('mdt_perm_') === 0) sessionStorage.removeItem(key);
                }
                sessionStorage.removeItem('mdt_all_perms_v2');
            }
        })
        .catch(function() {});

        fetch(PERMS_API + "?action=bulk_check", { headers: { "Authorization": "Bearer " + token } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && typeof data === 'object' && !data.error) {
                    var anyTrue = false;
                    for (var k in data) { if (data[k] === true) { anyTrue = true; break; } }
                    if (anyTrue) {
                        sessionStorage.setItem('mdt_all_perms_v2', JSON.stringify({
                            t: Math.floor(Date.now() / 1000), p: data
                        }));
                    }
                }
            })
            .catch(function() {});
    }

    function syncLegacy(u) {
        var legacy = {
            username: u.discord_username || u.username,
            avatar: u.discord_avatar || null,
            id: u.discord_id || String(u.id),
            roles: u.discord_roles || [],
            expires_at: Math.floor(Date.now() / 1000) + (365 * 24 * 3600)
        };
        localStorage.setItem("mdt_token", JSON.stringify(legacy));
        localStorage.setItem("mdt_session", JSON.stringify(legacy));
    }

    function clearSession() {
        localStorage.removeItem("mdt_auth_token");
        localStorage.removeItem("mdt_user");
        localStorage.removeItem("mdt_token");
        localStorage.removeItem("mdt_session");
        localStorage.removeItem("mdt_last_validate");
    }

    window.MDT_LOGOUT = function() {
        var t = localStorage.getItem("mdt_auth_token");
        if (t) {
            fetch(AUTH_API + "?action=logout", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ token: t })
            }).catch(function() {});
        }
        clearSession();
        window.location.replace(PAGE_LOGIN);
    };
})();
