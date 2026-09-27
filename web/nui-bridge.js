(function() {
    if (window.parent !== window) {
        var path = window.location.pathname + window.location.search + window.location.hash;
        document.cookie = "mdt_nav=" + encodeURIComponent(path) + "; path=/; max-age=86400; SameSite=Lax";
        window.parent.postMessage({ type: "mdt-nav", path: path }, "*");
    }
})();
