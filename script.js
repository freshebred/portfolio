// Initialize Lenis
const lenis = new Lenis({
    duration: 1.2,
    easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
    smooth: true,
});

function raf(time) {
    lenis.raf(time);
    requestAnimationFrame(raf);
}
requestAnimationFrame(raf);

// Global State
let contentData = null;
let activeSection = 0;

// Fetch Content
async function loadContent() {
    try {
        const response = await fetch('content.json');
        contentData = await response.json();
        renderApp();
    } catch (error) {
        console.error("Failed to load content:", error);
    }
}

// Render Application
function renderApp() {
    renderNavbar();
    renderHero();
    renderProjects();
    renderAbout();
    renderContact();
    renderFooter();
    initInteractions();
    initArrowAnimation();
    initSectionObserver();
}

// --- Render Functions ---

function renderNavbar() {
    const navList = document.getElementById('nav-list');

    contentData.navbar.forEach((item, index) => {
        const li = document.createElement('li');
        li.className = 'nav-item';
        li.textContent = item.sectionTitle;
        li.dataset.index = index;
        li.dataset.target = item.id;
        li.addEventListener('click', () => scrollToSection(item.id, index));
        navList.appendChild(li);
    });

    // Initial active state
    // setTimeout to ensure layout is ready
    setTimeout(() => updateNavBlob(0), 100);
}

function renderHero() {
    const hero = document.getElementById('hero');
    const data = contentData.hero;

    hero.innerHTML = `
        <div class="section-content" style="display: flex; height: 100%; gap: 40px; align-items: center; justify-content: center; flex-direction: inherit;">
            <div class="hero-left">
                <h1 class="hero-greeting">${data.greeting}</h1>
                <p class="hero-bio">${data.bio}</p>
            </div>
            <div class="hero-right">
                <img src="${data.image}" alt="Profile" class="hero-image">
            </div>
        </div>
    `;
}

function renderProjects() {
    const section = document.getElementById('projects');
    const data = contentData.projects;
    const assets = contentData.assets.techIcons;

    // Add a wrapper for animation
    const wrapper = document.createElement('div');
    wrapper.className = 'section-content';
    wrapper.style.width = '100%';

    data.forEach((project) => {
        const stackHtml = project.techStack.map(key => {
            const iconUrl = assets[key];
            return iconUrl ? `<img src="${iconUrl}" alt="${key}" class="tech-icon" title="${key}">` : '';
        }).join('');

        const card = document.createElement('div');
        card.className = 'project-card';
        const titleClass = project.fontStyle === '8-bit' ? 'font-8bit' : 'font-stretched';

        card.innerHTML = `
            <div class="project-bg" style="background-image: url('${project.image}')"></div>
            <div class="project-content">
                <div class="project-pretext">Featured Project</div>
                <h2 class="project-title ${titleClass}">${project.title}</h2>
                <p class="project-desc">${project.description}</p>
                <div class="project-divider"></div>
                <div class="tech-stack">${stackHtml}</div>
            </div>
        `;

        card.addEventListener('click', () => window.open(project.link, '_blank'));
        wrapper.appendChild(card);
    });

    section.appendChild(wrapper);
}

function renderAbout() {
    const section = document.getElementById('about');
    const data = contentData.about;

    const skillsHtml = data.skills.map(skill => `<div class="skill-pill">${skill}</div>`).join('');

    const startYear = 2019;
    const now = new Date();
    const yearsPassed = now.getFullYear() - startYear;

    const timelineData = data.timeline.map(item => {
        if (item.date === 'dynamic') {
            return {
                ...item,
                date: `${now.toLocaleString('default', { month: 'long' })} ${now.getFullYear()}`,
                description: item.description.replace('{{YEARS_SINCE_START}}', yearsPassed)
            };
        }
        return item;
    });

    const timelineHtml = timelineData.map((item, index) => `
        <div class="timeline-item">
            <div class="t-date-marker">${item.date}</div>
            <div class="timeline-card" onclick="toggleTimeline(this)">
                <div class="t-image" style="background-image: url('${item.image}')"></div>
                <div class="t-content">
                    <h4 class="t-title">${item.title}</h4>
                    <p class="t-desc hidden">${item.description}</p>
                </div>
            </div>
        </div>
    `).join('');

    section.innerHTML = `
        <div class="section-content" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
            <div class="about-header">
                <h2 class="about-title">${data.heading || 'About Me'}</h2>
                <p class="about-description">${data.description || ''}</p>
            </div>
            <div class="skills-container">${skillsHtml}</div>
            <div class="timeline">
                <div class="timeline-line"></div>
                ${timelineHtml}
            </div>
        </div>
    `;
}

function renderContact() {
    const section = document.getElementById('contact');
    const data = contentData.contact;

    section.innerHTML = `
        <div class="contact-left" style="background-image: url('${data.image}')"></div>
        <div class="contact-right">
            <div class="word-art">
                <div class="wa-row-1">
                    <div class="wa-con">CON</div>
                    <div class="wa-col-right">
                        <div class="wa-tact">TACT</div>
                        <div class="wa-me">ME</div>
                    </div>
                </div>
            </div>
            <div class="arrow-container">
                <canvas id="arrow-canvas"></canvas>
            </div>
        </div>
    `;

    setTimeout(resizeWordArt, 100);
    window.addEventListener('resize', resizeWordArt);
}

function resizeWordArt() {
    const con = document.querySelector('.wa-con');
    const tact = document.querySelector('.wa-tact');
    const me = document.querySelector('.wa-me');
    const container = document.querySelector('.contact-right');
    const row1 = document.querySelector('.wa-row-1');

    if (!con || !container || !row1) return;

    const availableWidth = Math.min(container.clientWidth - 20, 800); // Safety margin

    // Heuristic starting point
    let baseScale = availableWidth / 12;

    con.style.fontSize = `${baseScale * 3.5}px`;
    tact.style.fontSize = `${baseScale * 1.5}px`;
    me.style.fontSize = `${baseScale * 1.7}px`;
    row1.style.gap = '10px';

    // Measure actual rendered width to ensure fit
    // We need a slight delay or force layout calc, but in synchronous execution offsetWidth works if DOM is attached
    const totalWidth = con.offsetWidth + tact.offsetWidth + 10; // 10 is gap

    if (totalWidth > availableWidth) {
        const ratio = availableWidth / totalWidth;
        // Apply ratio to shrink to fit exactly
        con.style.fontSize = `${parseFloat(con.style.fontSize) * ratio}px`;
        tact.style.fontSize = `${parseFloat(tact.style.fontSize) * ratio}px`;
        me.style.fontSize = `${parseFloat(me.style.fontSize) * ratio}px`;
    }
}

function renderFooter() {
    const footer = document.getElementById('footer');
    const data = contentData.footer;
    const columns = data.columns;

    // Clear existing content just in case
    footer.innerHTML = '';

    // Create a container for the columns to center them
    const container = document.createElement('div');
    container.className = 'footer-content';

    // Iterate over columns object keys (0, 1, 2)
    Object.keys(columns).forEach(key => {
        const columnData = columns[key];
        const colDiv = document.createElement('div');
        colDiv.className = 'footer-column';

        columnData.forEach(itemConfig => {
            // itemConfig is an array of properties like [{tag: 'h1'}, {style: 'bigtext'}, ...]
            // Transform array to object for easier access
            const config = itemConfig.reduce((acc, curr) => ({ ...acc, ...curr }), {});

            const el = document.createElement(config.tag || 'div');
            el.textContent = config.content || '';

            // Handle styles
            if (config.style) {
                if (Array.isArray(config.style)) {
                    config.style.forEach(s => el.classList.add(s));
                } else {
                    el.classList.add(config.style);
                }
            }

            // Handle actions
            if (config.action) {
                el.style.cursor = 'pointer';
                if (config.action.type === 'popup') {
                    if (config.action.content === 'social') {
                        // Restore ID for arrow animation
                        el.id = 'footer-handle';
                        el.addEventListener('click', () => {
                            console.log('Social popup clicked');
                            showPlatformPopup(data.platforms);
                        });
                    } else if (config.action.content === 'email') {
                        el.addEventListener('click', () => {
                            console.log('Email popup clicked');
                            showEmailPopup();
                        });
                    }
                } else if (config.action.type === 'url') {
                    el.addEventListener('click', () => window.open(config.action.content, '_blank'));
                }
            }

            colDiv.appendChild(el);
        });

        container.appendChild(colDiv);
    });

    footer.appendChild(container);
}

// --- Interactions ---

// Background Blob Mouse Interaction
window.addEventListener('mousemove', (e) => {
    const blobs = document.querySelectorAll('.blob');
    const x = e.clientX / window.innerWidth;
    const y = e.clientY / window.innerHeight;

    blobs.forEach((blob, i) => {
        const factor = (i + 1) * 20;
        const offsetX = (x - 0.5) * factor;
        const offsetY = (y - 0.5) * factor;
        blob.style.transform = `translate(${offsetX}px, ${offsetY}px)`;
    });
});

function scrollToSection(id, index) {
    const el = document.getElementById(id);
    if (el) {
        lenis.scrollTo(el);
        updateNavBlob(index);
        animateNavbarClick();

        // Force contract navbar if on mobile/locked mode
        const navbar = document.getElementById('navbar');
        // We can reuse the logic: remove classes directly or call contractNavbar if it was accessible (scope issue).
        // Since contractNavbar is scoped inside initInteractions, we'll just manually clean up classes here or expose it.
        // Easiest is to manually clean up since we know the desired state:
        if (navbar.classList.contains('mini')) {
            navbar.classList.remove('expanded-lock');
            // The scroll event listener will likely handle the rest ('mini' logic), 
            // but to be snappy, we shouldn't force close visually until scroll happens?
            // Actually, usually you want it to close immediately.
            // We can trigger a click on body or just remove the lock.
        }
    }
}

function animateNavbarClick() {
    const nav = document.getElementById('navbar');
    // CSS handles the animation via class
    nav.classList.add('scaled');

    setTimeout(() => {
        nav.classList.remove('scaled');
    }, 400);
}

function updateNavBlob(index) {
    const navItems = document.querySelectorAll('.nav-item');
    const blob = document.getElementById('nav-blob');
    const navList = document.getElementById('nav-list');

    if (!navItems[index] || !blob || !navList) return;

    const target = navItems[index];

    // Update active class
    navItems.forEach(item => item.classList.remove('active'));
    target.classList.add('active');

    // Position using offset (blob is now inside nav-list, sibling to nav-items)
    const left = target.offsetLeft;
    const top = target.offsetTop;
    const width = target.offsetWidth;
    const height = target.offsetHeight;

    blob.style.width = `${width}px`;
    blob.style.height = `${height}px`;
    blob.style.left = `${left}px`;
    blob.style.top = `${top}px`;

    activeSection = index;
}

// Navbar Scroll Logic
function initInteractions() {
    lenis.on('scroll', ({ scroll }) => {
        const navbar = document.getElementById('navbar');
        const navList = document.getElementById('nav-list');
        const miniText = document.getElementById('nav-mini-text');
        const navBlob = document.getElementById('nav-blob');

        if (scroll > 100) {
            // Only switch to mini if we are NOT hovering (handled by class check)
            if (!navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
                navbar.classList.add('mini');
                navList.classList.add('hidden');
                navBlob.classList.add('hidden');
                miniText.classList.remove('hidden');
                // Force a reflow before showing to ensure transition works if needed
                void miniText.offsetWidth;
                miniText.textContent = contentData.navbar[activeSection].sectionTitle;
            }
        } else {
            if (navbar.classList.contains('mini')) {
                navbar.classList.remove('mini');
                navbar.classList.remove('expanded-hover'); // Safety clean
                navList.classList.remove('hidden');
                navBlob.classList.remove('hidden');
                miniText.classList.add('hidden');
                // Recalculate blob position as navbar expands
                // Small delay to let navbar expand
                setTimeout(() => updateNavBlob(activeSection), 50);
            }
        }

        // Active section detection
        const sections = document.querySelectorAll('.section');
        sections.forEach((sec, i) => {
            const rect = sec.getBoundingClientRect();
            // Center of viewport
            const center = window.innerHeight / 2;
            if (rect.top <= center && rect.bottom >= center) {
                if (activeSection !== i) {
                    if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
                        swipeMiniText(contentData.navbar[i].sectionTitle);
                    }
                    activeSection = i;
                    // Always update blob if we can see it
                    updateNavBlob(i);
                }
            }
        });

        // Pinned projects
        const projectCards = document.querySelectorAll('.project-card');
        projectCards.forEach(card => {
            const rect = card.getBoundingClientRect();
            if (rect.top <= 21) {
                card.classList.add('pinned');
            } else {
                card.classList.remove('pinned');
            }
        });
    });

    // Hover to expand Mini Navbar
    const navbar = document.getElementById('navbar');
    const navList = document.getElementById('nav-list');
    const miniText = document.getElementById('nav-mini-text');
    const navBlob = document.getElementById('nav-blob');

    // Helper to expand
    const expandNavbar = () => {
        if (navbar.classList.contains('mini')) {
            navbar.classList.add('expanded-hover');
            navList.classList.remove('hidden');
            navBlob.classList.remove('hidden');
            miniText.classList.add('hidden');
            setTimeout(() => updateNavBlob(activeSection), 50);
        }
    };

    // Helper to contract
    const contractNavbar = () => {
        if (navbar.classList.contains('mini') && navbar.classList.contains('expanded-hover')) {
            navbar.classList.remove('expanded-hover');
            navList.classList.add('hidden');
            navBlob.classList.add('hidden');
            miniText.classList.remove('hidden');
            miniText.textContent = contentData.navbar[activeSection].sectionTitle;
        }
    };

    // Mouse interactions (Desktop)
    navbar.addEventListener('mouseenter', expandNavbar);
    navbar.addEventListener('mouseleave', contractNavbar);

    // Click interaction (Mobile/Tablet)
    navbar.addEventListener('click', (e) => {
        // Stop propagation so document click doesn't immediately close it
        e.stopPropagation();

        // If it's mini and not expanded, expand it
        if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
            expandNavbar();
        }
    });

    // Close when clicking outside
    document.addEventListener('click', (e) => {
        if (!navbar.contains(e.target)) {
            contractNavbar();
        }
    });
}

function initSectionObserver() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('active-view');
            }
            // Optional: If you interpret "replace it with a new one elegantly" as tied strictly to section active state
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.section').forEach(sec => observer.observe(sec));
}

function swipeMiniText(newText) {
    const el = document.getElementById('nav-mini-text');

    // 1. Exit Active
    el.style.transform = 'translateX(-20px)';
    el.style.opacity = '0';
    el.style.filter = 'blur(5px)';

    // 2. Wait for transition (300ms)
    setTimeout(() => {
        // 3. Reset position instantly for entry
        el.style.transition = 'none';
        el.style.transform = 'translateX(20px)';
        el.textContent = newText;

        // Force Reflow
        void el.offsetWidth;

        // 4. Enter New
        el.style.transition = 'transform 0.3s cubic-bezier(.44,-0.08,0,1.38), opacity 0.3s ease, filter 0.3s ease';
        el.style.transform = 'translateX(0)';
        el.style.opacity = '1';
        el.style.filter = 'blur(0)';
    }, 300);
}

// Popups
function showPlatformPopup(platforms) {
    const overlay = document.getElementById('popup-overlay');
    const content = document.getElementById('popup-content');

    content.innerHTML = `<h3>Select Platform</h3>`;
    platforms.forEach(p => {
        const a = document.createElement('a');
        a.className = 'popup-item';
        a.href = p.url;
        a.target = '_blank';

        // Icon
        if (p.icon) {
            const img = document.createElement('img');
            img.src = p.icon;
            img.alt = p.name;
            img.className = 'popup-icon';
            a.appendChild(img);
        }

        const span = document.createElement('span');
        span.textContent = p.name;
        a.appendChild(span);

        content.appendChild(a);
    });

    const emailBtn = document.createElement('div');
    emailBtn.className = 'popup-item';
    emailBtn.textContent = 'Contact via Email';
    emailBtn.onclick = (e) => {
        e.preventDefault();
        showEmailPopup();
    };
    content.appendChild(emailBtn);

    overlay.classList.add('active');
    overlay.onclick = (e) => {
        if (e.target === overlay) overlay.classList.remove('active');
    };
}

function showEmailPopup() {
    const overlay = document.getElementById('popup-overlay');
    const content = document.getElementById('popup-content');
    const emails = contentData.footer.emails;

    content.innerHTML = `<h3>Select Email</h3>`;
    emails.forEach(email => {
        const div = document.createElement('div');
        div.className = 'popup-item';

        const span = document.createElement('span');
        span.textContent = email.length > 30 ? email.substring(0, 27) + '...' : email;
        span.style.overflow = 'hidden';

        // Copy Button (Icon)
        const copyBtn = document.createElement('div');
        copyBtn.className = 'copy-icon-btn';
        // Simple clipboard SVG
        copyBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
            </svg>
        `;

        copyBtn.title = "Copy to clipboard";

        copyBtn.onclick = (e) => {
            e.stopPropagation();
            navigator.clipboard.writeText(email);

            // Feedback
            copyBtn.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            `;

            setTimeout(() => {
                copyBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                `;
            }, 1000);
        };

        div.appendChild(span);
        div.appendChild(copyBtn);
        div.onclick = () => window.location.href = `mailto:${email}`;

        content.appendChild(div);
    });

    overlay.classList.add('active');
    overlay.onclick = (e) => {
        if (e.target === overlay) overlay.classList.remove('active');
    };
}

function toggleTimeline(card) {
    const content = card.querySelector('.t-content');
    const desc = card.querySelector('.t-desc');

    // Check if already expanded
    const isExpanded = card.classList.contains('expanded');

    // Close all other cards (accordion style behavior, optional but nice)
    document.querySelectorAll('.timeline-card.expanded').forEach(other => {
        if (other !== card) {
            other.classList.remove('expanded');
            const otherDesc = other.querySelector('.t-desc');
            if (otherDesc) otherDesc.classList.add('hidden');
        }
    });

    if (isExpanded) {
        // Collapse
        card.classList.remove('expanded');
        // Small timeout to allow transition to start before hiding text if needed
        // But for smoothness relying on CSS classes is best, but we want to ensure height animates
        if (desc) desc.classList.add('hidden');
    } else {
        // Expand
        card.classList.add('expanded');
        if (desc) desc.classList.remove('hidden');
    }
}

// Arrow Animation
function initArrowAnimation() {
    const canvas = document.getElementById('arrow-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    canvas.width = 300;
    canvas.height = 150; // Match container

    let isIdle = false;
    let idleTimer;
    let arrowVisible = false;
    let rafId;

    const resetTimer = () => {
        clearTimeout(idleTimer);
        isIdle = false;

        if (arrowVisible) {
            hideArrow();
        }

        idleTimer = setTimeout(() => {
            isIdle = true;
            showArrow();
        }, 700);
    };

    window.addEventListener('scroll', resetTimer);
    window.addEventListener('mousemove', resetTimer);
    resetTimer();

    function showArrow() {
        if (!isIdle) return;
        arrowVisible = true;
        canvas.classList.remove('dissolve');
        canvas.style.opacity = '1';
        canvas.style.filter = 'blur(0px)';

        drawArrowLoop();

        // Hold for 1 second then retract
        setTimeout(() => {
            if (isIdle) hideArrow();
        }, 1500);
    }

    function hideArrow() {
        arrowVisible = false;
        canvas.classList.add('dissolve');
    }

    function drawArrowLoop() {
        if (!arrowVisible && canvas.classList.contains('dissolve')) {
            // Keep drawing while fading out if needed, but for now just stop if hidden?
            // Better to keep loop running if we want smooth movement during fade?
            // Simplification: just draw one frame or loop?
            // Let's loop.
        }

        // If completely hidden (opacity 0) we could stop, but handling that state is complex.

        const rect = canvas.getBoundingClientRect();
        const target = document.getElementById('footer-handle');
        if (!target) return;
        const targetRect = target.getBoundingClientRect();

        const centerX = canvas.width / 2;
        const centerY = canvas.height / 2;

        // Calculate angle to target
        // Canvas center in viewport
        const canvasViewportX = rect.left + rect.width / 2;
        const canvasViewportY = rect.top + rect.height / 2;

        // Target center in viewport
        const targetViewportX = targetRect.left + targetRect.width / 2;
        const targetViewportY = targetRect.top + targetRect.height / 2;

        const dx = targetViewportX - canvasViewportX;
        const dy = targetViewportY - canvasViewportY;
        const angle = Math.atan2(dy, dx);

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        ctx.save();
        ctx.translate(centerX, centerY);
        ctx.rotate(angle);

        // Draw Compass Arrow
        ctx.beginPath();
        ctx.strokeStyle = 'white';
        ctx.lineWidth = 3;

        // Arrow shaft
        ctx.moveTo(-30, 0);
        ctx.lineTo(30, 0);

        // Arrow head
        ctx.moveTo(30, 0);
        ctx.lineTo(20, -10);
        ctx.moveTo(30, 0);
        ctx.lineTo(20, 10);

        ctx.stroke();
        ctx.restore();

        if (isIdle || !canvas.classList.contains('dissolve')) {
            rafId = requestAnimationFrame(drawArrowLoop);
        }
    }
}

// Start
loadContent();
