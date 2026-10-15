# Plug-and-Play: Glass Navbar + Animated Background Blobs

Extracted from this portfolio. 3 files below = everything you need. No dependencies
(no Lenis required — a plain `scroll` listener is used instead).

---

## 1. State Machine (how it works)

The whole system is CSS transitions triggered by JS toggling classes:

| Class on `#navbar` | Meaning | Visual |
|---|---|---|
| *(none)* | Full expanded state (top of page) | Pill nav with items + sliding blob |
| `.mini` | Collapsed after scrolling > 100px | Small pill showing only current section text |
| `.mini.expanded-hover` | Mini pill hovered/clicked → expands again | Items fade back in, padding/radius animate back |
| `.scaled` | Flash effect on click ("liquid squeeze") | Navbar scales to 1.05, border glows, inner blob stretches |

Hidden elements use a `.hidden { display:none !important; }` utility, so when you
re-show them you call `updateNavBlob()` after a ~50ms delay so widths are measurable.

---

## 2. HTML

```html
<!-- Background blobs (fixed, behind everything) -->
<div id="background-container">
    <div id="blobs-container">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>
</div>

<!-- Navbar -->
<nav id="navbar">
    <ul id="nav-list">
        <div id="nav-blob"></div><!-- sliding highlight, must be FIRST child -->
        <!-- <li class="nav-item">Home</li> ... rendered by JS or hardcoded -->
    </ul>
    <div id="nav-mini-text" class="hidden"></div>
</nav>
```

Build nav items either by hand (`<li class="nav-item" data-index="0">Home</li>`…)
or with the `renderNavbar` example in the JS section.

---

## 3. CSS

```css
/* ---------- Design tokens ---------- */
:root {
    --bg-color: #0a0a0a;
    --text-color: #ededed;
    --glass-border: rgba(255, 255, 255, 0.1);

    /* The signature "overshoot/squish" easing used everywhere */
    --ease-custom: cubic-bezier(.44, -0.08, 0, 1.38);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    background-color: var(--bg-color);
    color: var(--text-color);
    font-family: 'Inter', sans-serif;
    overflow-x: hidden;
    min-height: 100vh;
}

.hidden { display: none !important; }

/* ================= ANIMATED BACKGROUND BLOBS ================= */

#background-container {
    position: fixed;
    top: 0; left: 0;
    width: 100vw;
    height: 100vh;
    z-index: -1;
    overflow: hidden;
}

#blobs-container {
    position: absolute;
    inset: 0;
    filter: blur(80px);          /* single blur on parent = soft merge of all blobs */
}

.blob {
    position: absolute;
    border-radius: 50%;
    opacity: 0.4;
    animation: drift 20s infinite alternate ease-in-out;
    transition: translate 0.25s ease-out;
    will-change: transform, translate; /* translate (parallax) + transform (drift) compose cleanly */
}

.blob-1 { width: 400px; height: 400px; background: #4a4a4a; top: 10%;   left: 20%;  animation-delay: 0s; }
.blob-2 { width: 300px; height: 300px; background: #2a2a2a; bottom: 20%; right: 10%; animation-delay: -5s; }
.blob-3 { width: 250px; height: 250px; background: #5a5a5a; top: 40%;   left: 60%;  animation-delay: -10s; }

@keyframes drift {
    0%   { transform: translate(0, 0) scale(1); }
    100% { transform: translate(30px, -50px) scale(1.1); }
}

/* ================= NAVBAR ================= */

#navbar {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    width: auto;
    max-width: 95vw;
    z-index: 100;
    white-space: nowrap;

    /* glassmorphism */
    background: rgba(10, 10, 10, 0.4);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid var(--glass-border);

    /* pill shape — shrinks in .mini */
    border-radius: 50px;
    padding: 10px;

    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;

    /* animate size/shape between expanded <-> mini */
    transition:
        transform     0.4s var(--ease-custom),
        width         0.4s var(--ease-custom),
        height        0.4s var(--ease-custom),
        padding       0.4s var(--ease-custom),
        border-radius 0.4s var(--ease-custom),
        border-color  0.3s ease,
        box-shadow    0.3s ease;
}

/* Click "squeeze" flash */
#navbar.scaled {
    transform: translateX(-50%) scale(1.05);
    border-color: rgba(255, 255, 255, 0.4);
    box-shadow: 0 0 20px rgba(255, 255, 255, 0.2);
}

#navbar:hover {
    border-color: rgba(255, 255, 255, 0.3);
}

/* Liquid stretch of the highlight while squeezed */
#navbar.scaled #nav-blob {
    transform: scale(1.1);
    opacity: 1;
    filter: brightness(1.2);
}

#nav-list {
    display: flex;
    list-style: none;
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    opacity: 1;
    transform: scale(1);
    transition: opacity 0.25s ease, transform 0.35s var(--ease-custom);
}

#navbar.mini:not(.expanded-hover) #nav-list {
    opacity: 0;
    pointer-events: none;
    transform: scale(0.92);
}

.nav-item {
    cursor: pointer;
    padding: 10px 20px;
    color: var(--text-color);
    font-size: 14px;
    font-weight: 500;
    position: relative;
    transition: color 0.3s;
    white-space: nowrap;
    z-index: 2;                 /* text always above the blob */
}

.nav-item.active { color: #000; }   /* dark text once blob slides under it */

/* Sliding highlight blob */
#nav-blob {
    position: absolute;         /* positioned via JS: left/top/width/height */
    background: #ededed;
    border-radius: 40px;
    z-index: 1;
    opacity: 0.9;
    transition:
        transform 0.5s var(--ease-custom),
        width     0.5s var(--ease-custom),
        height    0.5s var(--ease-custom),
        top       0.5s var(--ease-custom),
        left      0.5s var(--ease-custom),
        opacity   0.25s ease;
}

#navbar.mini:not(.expanded-hover) #nav-blob {
    opacity: 0;
}

/* COLLAPSED (scrolled) state */
#navbar.mini {
    padding: 5px 15px;
    border-radius: 30px;
}

/* Expanded-back-on-hover state (restores original paddings) */
#navbar.expanded-hover {
    padding: 10px;
    border-radius: 50px;
}

/* Current-section label shown while mini */
#nav-mini-text {
    position: absolute;
    color: var(--text-color);
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transform: translateX(0) scale(0.9);
    filter: blur(4px);
    transition:
        transform 0.3s var(--ease-custom),
        opacity   0.25s ease,
        filter    0.25s ease;
}

#navbar.mini:not(.expanded-hover) #nav-mini-text {
    opacity: 1;
    pointer-events: auto;
    transform: translateX(0) scale(1);
    filter: blur(0);
}
```

---

## 4. JS (vanilla, no dependencies)

```js
let activeSection = 0;
let isNavbarHovered = false;
let leaveHoverTimer = null;
let swipeTimer = null;
let swipeCleanupTimer = null;

function syncNavbarSize() {
    const navbar = document.getElementById('navbar');
    const navList = document.getElementById('nav-list');
    const miniText = document.getElementById('nav-mini-text');
    if (!navbar || !navList || !miniText) return;

    const isCollapsedMini = navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover');
    if (isCollapsedMini) {
        navbar.style.width  = `${Math.ceil(miniText.offsetWidth) + 32}px`;
        navbar.style.height = `${Math.ceil(miniText.offsetHeight) + 12}px`;
    } else {
        navbar.style.width  = `${Math.ceil(navList.offsetWidth) + 22}px`;
        navbar.style.height = `${Math.ceil(navList.offsetHeight) + 22}px`;
    }
}

function clearMiniTextInlineStyles() {
    const el = document.getElementById('nav-mini-text');
    if (!el) return;
    clearTimeout(swipeTimer);
    clearTimeout(swipeCleanupTimer);
    el.style.transition = '';
    el.style.transform = '';
    el.style.opacity = '';
    el.style.filter = '';
}

/* ---- Render nav items (or hardcode your own <li>s) ---- */
function renderNavbar(labels) {
    const navList = document.getElementById('nav-list');
    const miniText = document.getElementById('nav-mini-text');
    if (miniText) {
        miniText.classList.remove('hidden');
        miniText.textContent = labels[0] || '';
    }

    labels.forEach((label, index) => {
        const li = document.createElement('li');
        li.className = 'nav-item';
        li.textContent = label;
        li.addEventListener('click', (e) => {
            e.stopPropagation();
            activeSection = index;
            updateNavBlob(index);
            animateNavbarClick();
        });
        navList.appendChild(li);
    });
    requestAnimationFrame(() => {
        updateNavBlob(0);
        syncNavbarSize();
    });
}

/* ---- Slide the highlight blob under the active item ---- */
function updateNavBlob(index) {
    const navItems = document.querySelectorAll('.nav-item');
    const blob = document.getElementById('nav-blob');
    const navList = document.getElementById('nav-list');
    if (!navItems[index] || !blob || !navList) return;

    const target = navItems[index];
    navItems.forEach(item => item.classList.remove('active'));
    target.classList.add('active');

    blob.style.width  = `${target.offsetWidth}px`;
    blob.style.height = `${target.offsetHeight}px`;
    blob.style.left   = `${target.offsetLeft}px`;
    blob.style.top    = `${target.offsetTop}px`;

    activeSection = index;
}

/* ---- Click squeeze flash ---- */
function animateNavbarClick() {
    const nav = document.getElementById('navbar');
    nav.classList.add('scaled');
    setTimeout(() => nav.classList.remove('scaled'), 400);
}

/* ---- Text swap with slide+blur exit/enter for the mini pill ---- */
function swipeMiniText(newText) {
    const el = document.getElementById('nav-mini-text');
    if (!el) return;

    clearTimeout(swipeTimer);
    clearTimeout(swipeCleanupTimer);

    el.style.transition = 'transform 0.2s ease, opacity 0.2s ease, filter 0.2s ease';
    el.style.transform = 'translateX(-20px)';
    el.style.opacity = '0';
    el.style.filter = 'blur(5px)';

    swipeTimer = setTimeout(() => {
        el.style.transition = 'none';
        el.style.transform = 'translateX(20px)';
        el.textContent = newText;
        syncNavbarSize();

        void el.offsetWidth;

        el.style.transition =
            'transform 0.3s cubic-bezier(.44,-0.08,0,1.38), opacity 0.25s ease, filter 0.25s ease';
        el.style.transform = 'translateX(0)';
        el.style.opacity = '1';
        el.style.filter = 'blur(0)';

        swipeCleanupTimer = setTimeout(() => clearMiniTextInlineStyles(), 320);
    }, 200);
}

/* ---- Mouse parallax on background blobs (uses CSS translate so keyframes drift still runs) ---- */
window.addEventListener('mousemove', (e) => {
    const blobs = document.querySelectorAll('.blob');
    const x = e.clientX / window.innerWidth;
    const y = e.clientY / window.innerHeight;
    blobs.forEach((blob, i) => {
        const factor = (i + 1) * 20;
        const offsetX = (x - 0.5) * factor;
        const offsetY = (y - 0.5) * factor;
        blob.style.translate = `${offsetX}px ${offsetY}px`;
    });
});

/* ---- Collapse / expand wiring ---- */
function initNavbar(labels) {
    renderNavbar(labels);

    const navbar  = document.getElementById('navbar');
    const miniText = document.getElementById('nav-mini-text');
    const sections = document.querySelectorAll('.section');

    const expandNavbar = () => {
        clearTimeout(leaveHoverTimer);
        if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
            clearMiniTextInlineStyles();
            navbar.classList.add('expanded-hover');
            updateNavBlob(activeSection);
            syncNavbarSize();
        }
    };

    const contractNavbar = () => {
        clearTimeout(leaveHoverTimer);
        if (navbar.classList.contains('mini') && navbar.classList.contains('expanded-hover')) {
            clearMiniTextInlineStyles();
            miniText.textContent = labels[activeSection];
            navbar.classList.remove('expanded-hover');
            syncNavbarSize();
        }
    };

    window.addEventListener('scroll', () => {
        if (window.scrollY > 100) {
            if (!navbar.classList.contains('mini')) {
                clearMiniTextInlineStyles();
                miniText.textContent = labels[activeSection];
                navbar.classList.add('mini');
                if (isNavbarHovered) navbar.classList.add('expanded-hover');
                syncNavbarSize();
            }
        } else if (navbar.classList.contains('mini')) {
            clearMiniTextInlineStyles();
            navbar.classList.remove('mini', 'expanded-hover');
            updateNavBlob(activeSection);
            syncNavbarSize();
        }

        sections.forEach((sec, i) => {
            const rect = sec.getBoundingClientRect();
            if (rect.top <= window.innerHeight / 2 && rect.bottom >= window.innerHeight / 2) {
                if (activeSection !== i) {
                    activeSection = i;
                    updateNavBlob(i);
                    if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
                        swipeMiniText(labels[i]);
                    } else {
                        miniText.textContent = labels[i];
                    }
                }
            }
        });
    });

    navbar.addEventListener('mouseenter', () => {
        isNavbarHovered = true;
        clearTimeout(leaveHoverTimer);
        expandNavbar();
    });

    navbar.addEventListener('mouseleave', () => {
        isNavbarHovered = false;
        clearTimeout(leaveHoverTimer);
        leaveHoverTimer = setTimeout(() => {
            if (!isNavbarHovered) contractNavbar();
        }, 80);
    });

    navbar.addEventListener('click', (e) => {
        e.stopPropagation();
        if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
            expandNavbar();
        }
    });

    document.addEventListener('click', (e) => {
        if (!navbar.contains(e.target)) {
            isNavbarHovered = false;
            contractNavbar();
        }
    });

    window.addEventListener('resize', () => {
        updateNavBlob(activeSection);
        syncNavbarSize();
    });
}

// Boot
initNavbar(['Home', 'Projects', 'About', 'Contact']);
```

---

## 5. Tuning cheatsheet

| What you want to change | Where |
|---|---|
| Blob colors / sizes / positions | `.blob-1/2/3` sizes, `background`, placement props |
| Drift speed/distance | `drift 20s infinite alternate`, keyframe `translate(30px,-50px)` |
| Blur softness of background | `#blobs-container { filter: blur(80px) }` |
| Parallax strength | `factor = (i + 1) * 20` — raise for more movement |
| Glass darkness / blur | `#navbar background rgba(...)`, `backdrop-filter blur(12px)` |
| Squish intensity of easing | `--ease-custom cubic-bezier(...)` — y > 1 gives overshoot |
| Collapse threshold | `scrollY > 100` |
| Blob slide speed | `#nav-blob` transition `0.5s` |
| Squeeze flash duration | keep `setTimeout(400)` equal to the `transform 0.4s` |
| Highlight blob look | `#nav-blob` background/border-radius/opacity |

**Gotchas that make it work:**
- `#nav-blob` must be *inside* `#nav-list`, before the `<li>`s, so its absolute coords match item offsets.
- Both `#nav-list` and `#nav-mini-text` remain in the layout (faded via `opacity` + `pointer-events: none` instead of `display: none`) while `syncNavbarSize()` sets explicit pixel `width`/`height` on `#navbar` so CSS `transition: width, height` morphs smoothly.
- The keyframe `drift` animates `transform`; mouse parallax uses the independent CSS `translate` property so both compose without overriding each other.
