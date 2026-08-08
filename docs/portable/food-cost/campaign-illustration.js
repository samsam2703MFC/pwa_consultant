/**
 * Champ « illustration » du formulaire Nouvelle campagne (Identité & période).
 * Navigateur, sans dépendance.
 *
 * Ce qu'il fait, et pourquoi :
 *   · aperçu immédiat — personne ne devrait envoyer un visuel sans l'avoir vu ;
 *   · glisser-déposer + clic + collage (Ctrl+V) ;
 *   · REDIMENSIONNEMENT côté client avant l'envoi — une photo de téléphone
 *     fait 8 Mo et 4000 px de large ; réduite à 2000 px elle tombe sous le
 *     mégaoctet, et l'upload cesse d'échouer sur les connexions de boutique ;
 *   · orientation EXIF respectée, sinon les photos prises à la verticale
 *     partent couchées ;
 *   · POINT D'INTÉRÊT cliquable — le même visuel sert en bandeau large et en
 *     vignette carrée ; sans ce point, le recadrage coupe les têtes ;
 *   · texte alternatif, à côté du champ et non caché dans un onglet.
 *
 * Le contrôle client ne remplace PAS la validation serveur (CampaignImage.php) :
 * il rend l'erreur immédiate et l'envoi plus léger, c'est tout. Tout ce qui
 * arrive au serveur reste suspect.
 *
 *   CampaignIllustration.mount(document.getElementById('illu'), {
 *     name: 'illustration',
 *     value: { url: '/uploads/campaigns/2026/01/ab12.jpg', alt: 'Galette', focus_x: .5, focus_y: .35 },
 *     onChange: function (s) { console.log(s); }
 *   });
 */
(function (root, factory) {
    var CampaignIllustration = factory();
    if (typeof module === 'object' && module.exports) module.exports = CampaignIllustration;
    else root.CampaignIllustration = CampaignIllustration;
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var DEFAULTS = {
        name: 'illustration',          // nom du champ fichier posté
        accept: ['image/jpeg', 'image/png', 'image/webp'],
        maxBytes: 5 * 1024 * 1024,     // même limite que le serveur
        maxWidth: 2000,                // redimensionnement avant envoi
        minWidth: 600,                 // en dessous, l'image sera floue
        quality: 0.85,                 // qualité JPEG/WebP après redimensionnement
        value: null,                   // { url, alt, focus_x, focus_y }
        labels: {
            drop:    'Glissez une photo ou une illustration, ou cliquez pour choisir',
            hint:    'JPEG, PNG ou WebP — 5 Mo maximum',
            alt:     'Description de l’image (lue par les lecteurs d’écran)',
            focus:   'Cliquez sur l’image pour choisir le point à garder au centre',
            replace: 'Remplacer',
            remove:  'Retirer'
        },
        onChange: null
    };

    var ERRORS = {
        type:  'Format non accepté — JPEG, PNG ou WebP.',
        size:  'Image trop lourde (5 Mo maximum).',
        small: 'Image trop petite : elle serait floue à l’affichage.',
        read:  'Fichier illisible.'
    };

    /** Feuille de style minimale — à injecter une fois, ou à remplacer. */
    var CSS = [
        '.ci{display:block;font:inherit}',
        '.ci-drop{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;',
        'gap:.4rem;min-height:170px;padding:1rem;border:2px dashed currentColor;border-radius:12px;',
        'opacity:.75;cursor:pointer;text-align:center}',
        '.ci-drop:focus-visible{outline:2px solid currentColor;outline-offset:2px}',
        '.ci-drop.is-over{opacity:1;border-style:solid}',
        '.ci-hint{font-size:.8em;opacity:.8}',
        '.ci-preview{position:relative;border-radius:12px;overflow:hidden;aspect-ratio:3/1;background:#0001}',
        '.ci-preview img{width:100%;height:100%;object-fit:cover;display:block;cursor:crosshair}',
        '.ci-focus{position:absolute;width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;',
        'border:2px solid #fff;box-shadow:0 0 0 2px #0006;pointer-events:none}',
        '.ci-bar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.5rem;font-size:.85em}',
        '.ci-bar button{font:inherit;cursor:pointer}',
        '.ci-alt{display:block;width:100%;margin-top:.5rem;font:inherit;padding:.4rem .5rem}',
        '.ci-err{margin-top:.4rem;font-size:.85em;color:#b3261e}',
        '.ci-meta{font-size:.78em;opacity:.7}'
    ].join('');

    function injectCss() {
        if (typeof document === 'undefined' || document.getElementById('ci-style')) return;
        var s = document.createElement('style');
        s.id = 'ci-style';
        s.textContent = CSS;
        document.head.appendChild(s);
    }

    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (txt != null) e.textContent = txt;
        return e;
    }

    function fmtBytes(n) {
        if (n == null) return '';
        return n >= 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' Mo'
             : Math.round(n / 1024) + ' ko';
    }

    /**
     * Charge un fichier en bitmap, orientation EXIF appliquée. `createImageBitmap`
     * avec `imageOrientation` la gère nativement ; sinon repli <img>, qui
     * l'applique aussi dans les navigateurs récents.
     */
    function loadBitmap(file) {
        if (typeof createImageBitmap === 'function') {
            try {
                return createImageBitmap(file, { imageOrientation: 'from-image' })
                    .catch(function () { return loadViaImg(file); });
            } catch (e) { /* option non supportée → repli */ }
        }
        return loadViaImg(file);
    }

    function loadViaImg(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('read')); };
            img.src = url;
        });
    }

    /**
     * Redimensionne si l'image dépasse `maxWidth`. Rend un Blob, ou le fichier
     * d'origine quand il est déjà assez petit — ré-encoder pour rien dégrade
     * l'image sans rien gagner.
     */
    function resize(file, bmp, opts) {
        var w = bmp.width, h = bmp.height;
        if (w <= opts.maxWidth) return Promise.resolve(null);

        var nw = opts.maxWidth;
        var nh = Math.round(h * (nw / w));
        var canvas = document.createElement('canvas');
        canvas.width = nw; canvas.height = nh;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(bmp, 0, 0, nw, nh);

        // PNG conservé en PNG (transparence) ; tout le reste part en JPEG.
        var type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
        return new Promise(function (resolve) {
            if (canvas.toBlob) canvas.toBlob(function (b) { resolve(b); }, type, opts.quality);
            else resolve(null);
        });
    }

    /** Remplace le contenu d'un <input type=file> par un Blob (pour poster le redimensionné). */
    function setInputFile(input, blob, name) {
        if (typeof DataTransfer === 'undefined' || !blob) return false;
        try {
            var dt = new DataTransfer();
            dt.items.add(new File([blob], name, { type: blob.type, lastModified: Date.now() }));
            input.files = dt.files;
            return true;
        } catch (e) { return false; }
    }

    function mount(host, options) {
        injectCss();
        var o = {};
        Object.keys(DEFAULTS).forEach(function (k) { o[k] = DEFAULTS[k]; });
        Object.keys(options || {}).forEach(function (k) { o[k] = options[k]; });
        o.labels = Object.assign({}, DEFAULTS.labels, (options && options.labels) || {});

        var state = {
            file: null, url: null, width: null, height: null, bytes: null,
            alt: (o.value && o.value.alt) || '',
            focus_x: (o.value && o.value.focus_x != null) ? Number(o.value.focus_x) : 0.5,
            focus_y: (o.value && o.value.focus_y != null) ? Number(o.value.focus_y) : 0.5,
            removed: false, error: null
        };

        host.classList.add('ci');
        host.innerHTML = '';

        var input = el('input');
        input.type = 'file';
        input.name = o.name;
        input.accept = o.accept.join(',');
        input.hidden = true;

        // Le point d'intérêt et la suppression voyagent avec le formulaire.
        var fx = el('input'); fx.type = 'hidden'; fx.name = o.name + '_focus_x'; fx.value = state.focus_x;
        var fy = el('input'); fy.type = 'hidden'; fy.name = o.name + '_focus_y'; fy.value = state.focus_y;
        var rm = el('input'); rm.type = 'hidden'; rm.name = o.name + '_remove';  rm.value = '0';

        var drop = el('div', 'ci-drop');
        drop.tabIndex = 0;
        drop.setAttribute('role', 'button');
        drop.appendChild(el('span', null, o.labels.drop));
        drop.appendChild(el('span', 'ci-hint', o.labels.hint));

        var preview = el('div', 'ci-preview');
        preview.hidden = true;
        var img = el('img');
        img.alt = '';
        var dot = el('span', 'ci-focus');
        preview.appendChild(img);
        preview.appendChild(dot);

        var bar  = el('div', 'ci-bar');
        var meta = el('span', 'ci-meta');
        var bReplace = el('button', null, o.labels.replace); bReplace.type = 'button';
        var bRemove  = el('button', null, o.labels.remove);  bRemove.type = 'button';
        var focusHint = el('span', 'ci-hint', o.labels.focus);
        bar.appendChild(bReplace); bar.appendChild(bRemove); bar.appendChild(meta);
        bar.hidden = true;

        var alt = el('input', 'ci-alt');
        alt.type = 'text';
        alt.name = o.name + '_alt';
        alt.maxLength = 190;
        alt.placeholder = o.labels.alt;
        alt.value = state.alt;
        alt.hidden = true;

        var err = el('div', 'ci-err');
        err.setAttribute('role', 'alert');
        err.hidden = true;

        [input, fx, fy, rm, drop, preview, bar, focusHint, alt, err].forEach(function (n) { host.appendChild(n); });
        focusHint.hidden = true;

        function emit() {
            fx.value = state.focus_x;
            fy.value = state.focus_y;
            rm.value = state.removed ? '1' : '0';
            state.alt = alt.value;
            if (typeof o.onChange === 'function') o.onChange(state);
        }

        function fail(code) {
            state.error = code;
            err.textContent = ERRORS[code] || code;
            err.hidden = false;
            emit();
        }

        function clearError() { state.error = null; err.hidden = true; }

        function paint() {
            var has = !!state.url;
            preview.hidden = !has;
            bar.hidden = !has;
            focusHint.hidden = !has;
            alt.hidden = !has;
            drop.hidden = has;
            if (has) {
                img.src = state.url;
                img.style.objectPosition = (state.focus_x * 100).toFixed(1) + '% ' + (state.focus_y * 100).toFixed(1) + '%';
                dot.style.left = (state.focus_x * 100).toFixed(1) + '%';
                dot.style.top  = (state.focus_y * 100).toFixed(1) + '%';
                meta.textContent = [
                    state.width ? state.width + ' × ' + state.height + ' px' : '',
                    state.bytes ? fmtBytes(state.bytes) : ''
                ].filter(Boolean).join(' · ');
            }
        }

        function accept(file) {
            clearError();
            if (!file) return;
            if (o.accept.indexOf(file.type) === -1) { fail('type'); return; }
            if (file.size > o.maxBytes)             { fail('size'); return; }

            loadBitmap(file).then(function (bmp) {
                if (bmp.width < o.minWidth) { fail('small'); return; }

                return resize(file, bmp, o).then(function (blob) {
                    var used = blob || file;
                    if (blob) setInputFile(input, blob, (file.name || 'illustration').replace(/\.[^.]+$/, '') +
                        (blob.type === 'image/png' ? '.png' : '.jpg'));

                    if (state.url && state.url.indexOf('blob:') === 0) URL.revokeObjectURL(state.url);
                    state.file    = used;
                    state.url     = URL.createObjectURL(used);
                    state.width   = blob ? o.maxWidth : bmp.width;
                    state.height  = blob ? Math.round(bmp.height * (o.maxWidth / bmp.width)) : bmp.height;
                    state.bytes   = used.size;
                    state.removed = false;
                    paint();
                    emit();
                });
            }).catch(function () { fail('read'); });
        }

        drop.addEventListener('click', function () { input.click(); });
        drop.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        bReplace.addEventListener('click', function () { input.click(); });
        bRemove.addEventListener('click', function () {
            if (state.url && state.url.indexOf('blob:') === 0) URL.revokeObjectURL(state.url);
            state.file = null; state.url = null; state.width = state.height = state.bytes = null;
            state.removed = true;
            input.value = '';
            alt.value = '';
            paint(); emit();
        });

        input.addEventListener('change', function () { accept(input.files && input.files[0]); });

        ['dragenter', 'dragover'].forEach(function (t) {
            drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (t) {
            drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) { setInputFile(input, f, f.name); accept(f); }
        });

        // Collage : un visuel arrive souvent du presse-papier, pas d'un dossier.
        host.addEventListener('paste', function (e) {
            var items = (e.clipboardData && e.clipboardData.items) || [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image/') === 0) {
                    var f = items[i].getAsFile();
                    if (f) { setInputFile(input, f, 'collage.png'); accept(f); e.preventDefault(); return; }
                }
            }
        });

        // Point d'intérêt : clic sur l'aperçu.
        img.addEventListener('click', function (e) {
            var r = img.getBoundingClientRect();
            state.focus_x = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
            state.focus_y = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height));
            paint(); emit();
        });

        alt.addEventListener('input', emit);

        // Valeur existante (édition d'une campagne).
        if (o.value && o.value.url) {
            state.url = o.value.url;
            state.width = o.value.width || null;
            state.height = o.value.height || null;
            state.bytes = o.value.bytes || null;
        }
        paint();

        return {
            state: state,
            clear: function () { bRemove.click(); },
            destroy: function () {
                if (state.url && state.url.indexOf('blob:') === 0) URL.revokeObjectURL(state.url);
                host.innerHTML = '';
            }
        };
    }

    return { mount: mount, CSS: CSS, ERRORS: ERRORS, DEFAULTS: DEFAULTS };
}));
