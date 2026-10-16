/**
 * Floating Auto-Retracting Glass Navbar
 * Extracted from Portfolio — Zero-dependency drop-in script (with optional Lenis support)
 *
 * Usage Option A (Declarative HTML):
 *   Add <li class="nav-item" data-target="hero">Home</li> inside #nav-list (after #nav-blob).
 *   Include <script src="navbar.js"></script> — it will auto-initialize on DOMContentLoaded!
 *
 * Usage Option B (JS Configuration):
 *   FloatingNavbar.init({
 *       items: [
 *           { id: 'hero', label: 'Home' },
 *           { id: 'projects', label: 'Projects' },
 *           { id: 'about', label: 'About' },
 *           { id: 'contact', label: 'Contact' }
 *       ],
 *       scrollThreshold: 100, // px scrolled before collapsing into mini pill
 *       lenis: window.lenis   // optional Lenis instance (auto-detected if window.lenis exists)
 *   });
 */

(function (global) {
    let navItemsConfig = [];
    let activeSection = 0;
    let isNavbarHovered = false;
    let isProgrammaticScroll = false;
    let programmaticScrollTimer = null;
    let leaveHoverTimer = null;
    let swipeTimer = null;
    let swipeCleanupTimer = null;
    let isInitialized = false;
    let currentOptions = {
        scrollThreshold: 100,
        sectionSelector: '.section',
        enableBlobParallax: true,
        lenis: null
    };

    /**
     * Synchronize explicit pixel width & height on #navbar so CSS transitions
     * can smoothly morph between the full expanded bar and the compact mini pill.
     */
    function syncNavbarSize() {
        const navbar = document.getElementById('navbar');
        const navList = document.getElementById('nav-list');
        const miniText = document.getElementById('nav-mini-text');
        if (!navbar || !navList || !miniText) return;

        const isCollapsedMini =
            navbar.classList.contains('mini') &&
            !navbar.classList.contains('expanded-hover');

        if (isCollapsedMini) {
            // Mini pill padding: 5px top/bottom (10px) + 15px left/right (30px) + 2px border
            const targetW = Math.ceil(miniText.offsetWidth) + 32;
            const targetH = Math.ceil(miniText.offsetHeight) + 12;
            navbar.style.width = `${targetW}px`;
            navbar.style.height = `${targetH}px`;
        } else {
            // Expanded pill padding: 10px top/bottom (20px) + 10px left/right (20px) + 2px border
            const targetW = Math.ceil(navList.offsetWidth) + 22;
            const targetH = Math.ceil(navList.offsetHeight) + 22;
            navbar.style.width = `${targetW}px`;
            navbar.style.height = `${targetH}px`;
        }
    }

    /**
     * Clear temporary inline styles applied during the swipeMiniText transition
     * so CSS class rules take back control cleanly.
     */
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

    /**
     * Position the sliding highlight blob (#nav-blob) under the active .nav-item.
     */
    function updateNavBlob(index) {
        const navItems = document.querySelectorAll('#nav-list .nav-item');
        const blob = document.getElementById('nav-blob');
        const navList = document.getElementById('nav-list');

        if (!navItems[index] || !blob || !navList) return;

        const target = navItems[index];

        navItems.forEach((item) => item.classList.remove('active'));
        target.classList.add('active');

        blob.style.width = `${target.offsetWidth}px`;
        blob.style.height = `${target.offsetHeight}px`;
        blob.style.left = `${target.offsetLeft}px`;
        blob.style.top = `${target.offsetTop}px`;

        activeSection = index;
    }

    /**
     * Trigger the liquid "squeeze" scale flash when clicking a nav item.
     */
    function animateNavbarClick() {
        const nav = document.getElementById('navbar');
        if (!nav) return;
        nav.classList.add('scaled');
        setTimeout(() => {
            nav.classList.remove('scaled');
        }, 400);
    }

    /**
     * Animate the mini-pill text out to the left with blur and slide the new section title in from the right.
     */
    function swipeMiniText(newText) {
        const el = document.getElementById('nav-mini-text');
        if (!el) return;

        clearTimeout(swipeTimer);
        clearTimeout(swipeCleanupTimer);

        // 1. Exit current text to the left
        el.style.transition = 'transform 0.2s ease, opacity 0.2s ease, filter 0.2s ease';
        el.style.transform = 'translateX(-20px)';
        el.style.opacity = '0';
        el.style.filter = 'blur(5px)';

        // 2. Wait for exit transition (200ms)
        swipeTimer = setTimeout(() => {
            // 3. Reset position to the right instantly & update pill width
            el.style.transition = 'none';
            el.style.transform = 'translateX(20px)';
            el.textContent = newText;
            syncNavbarSize();

            // Force reflow
            void el.offsetWidth;

            // 4. Enter new text with spring overshoot
            el.style.transition =
                'transform 0.3s cubic-bezier(.44,-0.08,0,1.38), opacity 0.25s ease, filter 0.25s ease';
            el.style.transform = 'translateX(0)';
            el.style.opacity = '1';
            el.style.filter = 'blur(0)';

            swipeCleanupTimer = setTimeout(() => {
                clearMiniTextInlineStyles();
            }, 320);
        }, 200);
    }

    /**
     * Scroll smoothly to target section and lock intermediate scroll-spy updates while scrolling.
     */
    function scrollToSection(id, index) {
        const el = id ? document.getElementById(id) : null;

        isProgrammaticScroll = true;
        clearTimeout(programmaticScrollTimer);
        programmaticScrollTimer = setTimeout(() => {
            isProgrammaticScroll = false;
        }, 1250);

        updateNavBlob(index);
        animateNavbarClick();

        const miniText = document.getElementById('nav-mini-text');
        if (miniText && navItemsConfig[index]) {
            clearMiniTextInlineStyles();
            miniText.textContent = navItemsConfig[index].label;
        }

        if (el) {
            const lenisInstance = currentOptions.lenis || global.lenis;
            if (lenisInstance && typeof lenisInstance.scrollTo === 'function') {
                lenisInstance.scrollTo(el, {
                    onComplete: () => {
                        isProgrammaticScroll = false;
                        clearTimeout(programmaticScrollTimer);
                    }
                });
            } else {
                el.scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Contract navbar on touch/coarse pointer devices after selecting a section
        const navbar = document.getElementById('navbar');
        if (
            navbar &&
            navbar.classList.contains('mini') &&
            !window.matchMedia('(hover: hover) and (pointer: fine)').matches
        ) {
            navbar.classList.remove('expanded-hover');
            syncNavbarSize();
        }
    }

    /**
     * Normalize items configuration or read existing DOM <li class="nav-item"> elements.
     */
    function setupNavItems(itemsOption) {
        const navList = document.getElementById('nav-list');
        const miniText = document.getElementById('nav-mini-text');
        if (!navList) return;

        // Ensure #nav-blob exists as the first child of #nav-list
        let blob = document.getElementById('nav-blob');
        if (!blob) {
            blob = document.createElement('div');
            blob.id = 'nav-blob';
            navList.prepend(blob);
        }

        if (Array.isArray(itemsOption) && itemsOption.length > 0) {
            // Clear any existing .nav-item elements and render from config
            navList.querySelectorAll('.nav-item').forEach((el) => el.remove());

            navItemsConfig = itemsOption.map((item, index) => {
                if (typeof item === 'string') {
                    return { id: '', label: item };
                }
                return {
                    id: item.id || item.target || '',
                    label: item.label || item.sectionTitle || item.title || `Section ${index + 1}`
                };
            });

            navItemsConfig.forEach((item, index) => {
                const li = document.createElement('li');
                li.className = 'nav-item';
                li.textContent = item.label;
                li.dataset.index = index;
                if (item.id) li.dataset.target = item.id;
                li.addEventListener('click', (e) => {
                    e.stopPropagation();
                    scrollToSection(item.id, index);
                });
                navList.appendChild(li);
            });
        } else {
            // Parse existing <li class="nav-item"> elements from HTML
            const existingItems = Array.from(navList.querySelectorAll('.nav-item'));
            navItemsConfig = existingItems.map((li, index) => {
                const id = li.dataset.target || li.getAttribute('href')?.replace(/^#/, '') || '';
                const label = li.textContent.trim();
                li.dataset.index = index;
                li.addEventListener('click', (e) => {
                    e.stopPropagation();
                    scrollToSection(id, index);
                });
                return { id, label };
            });
        }

        if (miniText) {
            miniText.classList.remove('hidden');
            if (navItemsConfig[0]) {
                miniText.textContent = navItemsConfig[0].label;
            }
        }

        requestAnimationFrame(() => {
            updateNavBlob(activeSection);
            syncNavbarSize();
        });
    }

    /**
     * Handle scroll position updates (collapse/expand threshold + active section detection).
     */
    function handleScroll(scrollY) {
        const navbar = document.getElementById('navbar');
        const miniText = document.getElementById('nav-mini-text');
        if (!navbar || !miniText || navItemsConfig.length === 0) return;

        // 1. Toggle .mini pill mode past scrollThreshold
        if (scrollY > currentOptions.scrollThreshold) {
            if (!navbar.classList.contains('mini')) {
                clearMiniTextInlineStyles();
                if (navItemsConfig[activeSection]) {
                    miniText.textContent = navItemsConfig[activeSection].label;
                }
                navbar.classList.add('mini');
                // If cursor is already inside navbar when crossing threshold, stay expanded until mouseleave
                if (isNavbarHovered) {
                    navbar.classList.add('expanded-hover');
                }
                syncNavbarSize();
            }
        } else {
            if (navbar.classList.contains('mini')) {
                clearMiniTextInlineStyles();
                navbar.classList.remove('mini', 'expanded-hover');
                updateNavBlob(activeSection);
                syncNavbarSize();
            }
        }

        // 2. Active section detection (skip intermediate overrides while click-scrolling)
        if (!isProgrammaticScroll) {
            const center = window.innerHeight / 2;
            let matchedIndex = -1;

            // Prefer matching by configured section IDs if available, else fallback to sectionSelector
            const hasTargetIds = navItemsConfig.some((item) => Boolean(item.id));
            if (hasTargetIds) {
                navItemsConfig.forEach((item, i) => {
                    if (!item.id) return;
                    const sec = document.getElementById(item.id);
                    if (!sec) return;
                    const rect = sec.getBoundingClientRect();
                    if (rect.top <= center && rect.bottom >= center) {
                        matchedIndex = i;
                    }
                });
            } else {
                const sections = document.querySelectorAll(currentOptions.sectionSelector);
                sections.forEach((sec, i) => {
                    if (i >= navItemsConfig.length) return;
                    const rect = sec.getBoundingClientRect();
                    if (rect.top <= center && rect.bottom >= center) {
                        matchedIndex = i;
                    }
                });
            }

            if (matchedIndex !== -1 && activeSection !== matchedIndex) {
                activeSection = matchedIndex;
                updateNavBlob(matchedIndex);
                const newLabel = navItemsConfig[matchedIndex].label;
                if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
                    swipeMiniText(newLabel);
                } else {
                    miniText.textContent = newLabel;
                }
            }
        }
    }

    /**
     * Initialize the Floating Navbar.
     */
    function init(options = {}) {
        currentOptions = {
            ...currentOptions,
            ...options
        };

        const navbar = document.getElementById('navbar');
        const miniText = document.getElementById('nav-mini-text');
        if (!navbar || !miniText) {
            console.warn('[FloatingNavbar] Required #navbar or #nav-mini-text element not found.');
            return;
        }

        setupNavItems(options.items);

        if (isInitialized) {
            updateNavBlob(activeSection);
            syncNavbarSize();
            return;
        }
        isInitialized = true;

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
                if (navItemsConfig[activeSection]) {
                    miniText.textContent = navItemsConfig[activeSection].label;
                }
                navbar.classList.remove('expanded-hover');
                syncNavbarSize();
            }
        };

        // Bind scroll listener (supports both Lenis and native window scroll)
        const lenisInstance = currentOptions.lenis || global.lenis;
        if (lenisInstance && typeof lenisInstance.on === 'function') {
            lenisInstance.on('scroll', ({ scroll }) => {
                handleScroll(scroll);
            });
        } else {
            window.addEventListener(
                'scroll',
                () => {
                    handleScroll(window.scrollY || window.pageYOffset || 0);
                },
                { passive: true }
            );
        }

        // Desktop hover interactions (with 80ms leave debounce to prevent cubic-bezier edge dip flicker)
        navbar.addEventListener('mouseenter', () => {
            isNavbarHovered = true;
            clearTimeout(leaveHoverTimer);
            expandNavbar();
        });

        navbar.addEventListener('mouseleave', () => {
            isNavbarHovered = false;
            clearTimeout(leaveHoverTimer);
            leaveHoverTimer = setTimeout(() => {
                if (!isNavbarHovered) {
                    contractNavbar();
                }
            }, 80);
        });

        // Mobile / Touch click to expand when in mini mode
        navbar.addEventListener('click', (e) => {
            e.stopPropagation();
            if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
                expandNavbar();
            }
        });

        // Close when clicking/tapping outside navbar
        document.addEventListener('click', (e) => {
            if (!navbar.contains(e.target)) {
                isNavbarHovered = false;
                contractNavbar();
            }
        });

        // Recalculate dimensions on font load and window resize
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(() => {
                updateNavBlob(activeSection);
                syncNavbarSize();
            });
        }

        window.addEventListener('resize', () => {
            updateNavBlob(activeSection);
            syncNavbarSize();
        });

        // Optional ambient background blob mouse parallax
        if (currentOptions.enableBlobParallax) {
            window.addEventListener(
                'mousemove',
                (e) => {
                    const blobs = document.querySelectorAll('.blob');
                    if (!blobs.length) return;
                    const x = e.clientX / window.innerWidth;
                    const y = e.clientY / window.innerHeight;

                    blobs.forEach((blob, i) => {
                        const factor = (i + 1) * 20;
                        const offsetX = (x - 0.5) * factor;
                        const offsetY = (y - 0.5) * factor;
                        blob.style.translate = `${offsetX}px ${offsetY}px`;
                    });
                },
                { passive: true }
            );
        }

        // Run initial scroll check in case page loads already scrolled down
        handleScroll(window.scrollY || window.pageYOffset || 0);
    }

    // Auto-initialize on DOMContentLoaded if declarative .nav-item elements exist in #nav-list
    document.addEventListener('DOMContentLoaded', () => {
        if (global.FloatingNavbarManualInit) return;
        const existingItems = document.querySelectorAll('#nav-list .nav-item');
        if (existingItems.length > 0 && !isInitialized) {
            init();
        }
    });

    // Expose public API
    global.FloatingNavbar = {
        init,
        setActive: updateNavBlob,
        refresh: () => {
            updateNavBlob(activeSection);
            syncNavbarSize();
        },
        scrollToSection
    };
})(window);
