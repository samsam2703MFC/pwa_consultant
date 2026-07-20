/* /consultant/sw.js */
const VERSION = "v1.0.0";
const STATIC_CACHE = `static-${VERSION}`;
const RUNTIME_CACHE = `runtime-${VERSION}`;

const PRECACHE_URLS = [
    "/consultant/",
    "/consultant/assets/mazer/vendors/bootstrap-icons/bootstrap-icons.css",
];

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS))
    );
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter((k) => ![STATIC_CACHE, RUNTIME_CACHE].includes(k))
                .map((k) => caches.delete(k))
        );
        await self.clients.claim();
    })());
});

function isGET(req) {
    return req.method === "GET";
}

function isStaticAsset(url) {
    return (
        url.pathname.startsWith("/consultant/assets/") ||
        url.pathname.endsWith(".css") ||
        url.pathname.endsWith(".js") ||
        url.pathname.endsWith(".png") ||
        url.pathname.endsWith(".jpg") ||
        url.pathname.endsWith(".jpeg") ||
        url.pathname.endsWith(".svg") ||
        url.pathname.endsWith(".webp") ||
        url.pathname.endsWith(".woff2") ||
        url.pathname.endsWith(".woff") ||
        url.pathname.endsWith(".ttf")
    );
}

function isApiGET(url) {
    return (
        url.pathname.startsWith("/consultant/api-proxy") ||
        url.pathname.startsWith("/api/")
    );
}

self.addEventListener("fetch", (event) => {
    const req = event.request;
    if (!isGET(req)) return;

    const url = new URL(req.url);

    const isSameOrigin = url.origin === self.location.origin;
    const isGoogleFonts = url.origin.includes("fonts.googleapis.com") || url.origin.includes("fonts.gstatic.com");

    // 1) Cache-first dla statycznych assetów
    if (isSameOrigin && isStaticAsset(url)) {
        event.respondWith((async () => {
            const cached = await caches.match(req);
            if (cached) return cached;
            const res = await fetch(req);
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(req, res.clone());
            return res;
        })());
        return;
    }

    // 2) Network-first dla GET z API (świeże dane)
    if (isSameOrigin && isApiGET(url)) {
        event.respondWith((async () => {
            const cache = await caches.open(RUNTIME_CACHE);
            try {
                const res = await fetch(req);
                if (res && res.status === 200) cache.put(req, res.clone());
                return res;
            } catch (e) {
                const cached = await cache.match(req);
                if (cached) return cached;
                return new Response(JSON.stringify({ error: "offline" }), {
                    status: 503,
                    headers: { "Content-Type": "application/json" }
                });
            }
        })());
        return;
    }

    // 3) Fonty — runtime cache
    if (isGoogleFonts) {
        event.respondWith((async () => {
            const cached = await caches.match(req);
            if (cached) return cached;
            const res = await fetch(req);
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(req, res.clone());
            return res;
        })());
        return;
    }

    // 4) Domyślnie: network, fallback na cache, a przy nawigacji — offline page
    event.respondWith((async () => {
        try {
            return await fetch(req);
        } catch (e) {
            const cached = await caches.match(req);
            if (cached) return cached;

            if (req.mode === "navigate") {
                return Response.redirect("/consultant/offline.html", 302);
            }
            throw e;
        }
    })());
});


