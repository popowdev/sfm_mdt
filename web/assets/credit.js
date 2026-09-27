(function () {
  if (window.__CREDIT_MOUNTED) return;
  window.__CREDIT_MOUNTED = true;

  function mount() {
    if (document.getElementById('ss-credit')) return;
    var f = document.createElement('footer');
    f.id = 'ss-credit';
    f.style.cssText = 'padding:14px 18px;text-align:center;font-size:13px;line-height:1.5;color:#7b8794';
    var a = document.createElement('a');
    a.href = 'https://surfsmart.fr';
    a.target = '_blank';
    a.rel = 'noopener';
    a.textContent = 'Surf Smart';
    a.style.cssText = 'color:#4d90f0;text-decoration:underline';
    f.appendChild(document.createTextNode('Développé par '));
    f.appendChild(a);
    document.body.appendChild(f);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
