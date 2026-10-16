# Floating Auto-Retracting Glass Navbar Kit

A self-contained, zero-dependency reproduction kit for the **Floating Auto-Retracting Glassmorphic Navbar** (with liquid sliding highlight blob, scroll-spy mini-pill retraction, and directional blur/swipe text transitions).

---

## Included Files

| File | Purpose |
| :--- | :--- |
| `navbar.css` | Drop-in stylesheet containing all navbar states, spring easing, glassmorphism, and optional ambient background blobs. |
| `navbar.js` | Drop-in script handling explicit pixel size morphing, active blob positioning, scroll-spy, swipe text transitions, and hover/touch expansion. |
| `index.html` | Complete runnable demo page — open directly in your browser to preview all states. |
| `README.md` | Integration guide, configuration options, and architecture breakdown. |

---

## Quick Start (Drop Into Any Project)

### 1. Include CSS & JS
Copy `navbar.css` and `navbar.js` into your project and link them in your HTML:

```html
<link rel="stylesheet" href="navbar.css">
<script src="navbar.js" defer></script>
```

### 2. Add the Navbar HTML

#### Option A: Declarative HTML (Auto-Initializes)
Simply place your `<li class="nav-item" data-target="SECTION_ID">` items inside `#nav-list`. When the page loads, `navbar.js` automatically detects them and initializes everything:

```html
<nav id="navbar">
    <ul id="nav-list">
        <!-- IMPORTANT: #nav-blob must remain inside #nav-list -->
        <div id="nav-blob"></div>
        <li class="nav-item" data-target="hero">Home</li>
        <li class="nav-item" data-target="projects">Projects</li>
        <li class="nav-item" data-target="about">About</li>
        <li class="nav-item" data-target="contact">Contact</li>
    </ul>
    <div id="nav-mini-text" class="hidden"></div>
</nav>
```

Make sure your page sections have matching `id` attributes:
```html
<section id="hero" class="section">...</section>
<section id="projects" class="section">...</section>
<section id="about" class="section">...</section>
<section id="contact" class="section">...</section>
```

#### Option B: Dynamic JS Initialization (e.g., from JSON / CMS)
If you prefer rendering the items via JavaScript (just like `content.json` in the portfolio), leave only `<div id="nav-blob"></div>` inside `#nav-list` and call `FloatingNavbar.init()`:

```html
<nav id="navbar">
    <ul id="nav-list">
        <div id="nav-blob"></div>
    </ul>
    <div id="nav-mini-text" class="hidden"></div>
</nav>

<script src="navbar.js"></script>
<script>
    FloatingNavbar.init({
        items: [
            { id: 'hero', label: 'Home' },
            { id: 'projects', label: 'Projects' },
            { id: 'about', label: 'About' },
            { id: 'contact', label: 'Contact' }
        ],
        scrollThreshold: 100,       // Scroll Y (px) before collapsing into .mini pill
        sectionSelector: '.section', // Fallback selector if items don't specify an id
        enableBlobParallax: true,   // Mouse parallax for optional background .blob elements
        lenis: window.lenis         // Optional: pass Lenis smooth-scroll instance if used
    });
</script>
```

---

## Using with Lenis Smooth Scroll (Optional)

`navbar.js` works out-of-the-box with native browser scrolling (`window.scrollY` and `scrollIntoView({ behavior: 'smooth' })`).

If your project uses [Lenis](https://github.com/darkroomengineering/lenis), `navbar.js` will automatically use `window.lenis` if defined globally, or you can pass your `lenis` instance explicitly to `FloatingNavbar.init({ lenis })`.

---

## How the Navbar State Machine Works

| State / Class on `#navbar` | Trigger | Visual Behavior |
| :--- | :--- | :--- |
| *(Default)* | `scrollY <= 100px` | Full expanded glass pill (`padding: 10px`, `border-radius: 50px`) with all `.nav-item` buttons and `#nav-blob` visible. |
| `.mini` | `scrollY > 100px` | Retracts into a compact pill (`padding: 5px 15px`, `border-radius: 30px`). `#nav-list` & `#nav-blob` fade out (`opacity: 0`), while `#nav-mini-text` fades in showing only the active section's title. |
| `.mini.expanded-hover` | Hovering (desktop) or tapping (mobile/tablet) while in `.mini` | Smoothly morphs width, height, padding, and border-radius back to the full expanded navbar so the user can pick a section. |
| `.scaled` | Clicking any `.nav-item` | Applies a 400ms liquid squeeze pulse (`scale(1.05)` on `#navbar`, `scale(1.1)` and `brightness(1.2)` on `#nav-blob`, plus glowing border). |

### Key Implementation Details that Make It Smooth

1. **Explicit Pixel Morphing (`syncNavbarSize`)**:
   CSS cannot transition `width: auto`. Both `#nav-list` and `#nav-mini-text` stay mounted in the layout at all times (hidden via `opacity: 0` + `pointer-events: none` rather than `display: none`). Whenever the state or label changes, `syncNavbarSize()` measures `navList.offsetWidth` or `miniText.offsetWidth` and sets explicit pixel `width` and `height` on `#navbar`.
2. **Bouncy Overshoot Easing (`--ease-custom`)**:
   `cubic-bezier(.44, -0.08, 0, 1.38)` creates the signature liquid recoil and spring overshoot on width, height, transform, and the sliding blob.
3. **80ms Hover-Leave Debounce**:
   Because `cubic-bezier(.44, -0.08, 0, 1.38)` has a slight negative dip (`-0.08`) at the start of the curve, a tiny `80ms` debounce on `mouseleave` prevents edge-flicker when the cursor sits right on the pill boundary.
4. **Programmatic Scroll Lock (`isProgrammaticScroll`)**:
   When clicking a nav item to jump across multiple sections, intermediate sections are ignored by the scroll-spy until the scroll completes, preventing the highlight blob or mini-pill text from flickering through intermediate labels.
5. **Directional Blur Swipe (`swipeMiniText`)**:
   When scrolling into a new section while collapsed in `.mini` mode, `#nav-mini-text` slides left (`-20px`) with `blur(5px)` and `opacity: 0`, resets to `+20px`, updates the text and pill width, forces a DOM reflow (`void el.offsetWidth`), and springs back to `0px`.

---

## Customizing Styles (CSS Variables)

Override any of these variables in your own stylesheet's `:root`:

```css
:root {
    --nav-bg: rgba(10, 10, 10, 0.4);
    --nav-text-color: #ededed;
    --nav-active-text-color: #000000;
    --nav-blob-color: #ededed;
    --nav-glass-border: rgba(255, 255, 255, 0.1);
    --nav-hover-border: rgba(255, 255, 255, 0.3);
    --nav-scaled-border: rgba(255, 255, 255, 0.4);
    --nav-scaled-glow: rgba(255, 255, 255, 0.2);
    --nav-blur: 12px;
    --nav-font: 'Inter', sans-serif;
    --ease-custom: cubic-bezier(.44, -0.08, 0, 1.38);
}
```

---

## Public JS API

- `FloatingNavbar.init(options)` — Initialize or re-configure the navbar.
- `FloatingNavbar.setActive(index)` — Programmatically move the highlight blob to item `index`.
- `FloatingNavbar.scrollToSection(sectionId, index)` — Smoothly scroll to a section and trigger the click squeeze animation.
- `FloatingNavbar.refresh()` — Force recalculation of blob position and navbar dimensions (called automatically on `resize` and `document.fonts.ready`).
