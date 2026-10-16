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
let isNavbarHovered = false;
let isProgrammaticScroll = false;
let programmaticScrollTimer = null;
let leaveHoverTimer = null;
let swipeTimer = null;
let swipeCleanupTimer = null;

// Fetch Content
async function loadContent() {
    try {
        const response = await fetch(`content.json?v=${Date.now()}`, { cache: 'no-store' });
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

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => {
            updateNavBlob(activeSection);
            syncNavbarSize();
            resizeWordArt();
        });
    }
    window.addEventListener('resize', () => {
        updateNavBlob(activeSection);
        syncNavbarSize();
    });
}

// --- Render Functions ---

function renderNavbar() {
    const navList = document.getElementById('nav-list');
    const miniText = document.getElementById('nav-mini-text');

    if (miniText) {
        miniText.classList.remove('hidden');
        if (contentData.navbar[0]) {
            miniText.textContent = contentData.navbar[0].sectionTitle;
        }
    }

    contentData.navbar.forEach((item, index) => {
        const li = document.createElement('li');
        li.className = 'nav-item';
        li.textContent = item.sectionTitle;
        li.dataset.index = index;
        li.dataset.target = item.id;
        li.addEventListener('click', (e) => {
            e.stopPropagation();
            scrollToSection(item.id, index);
        });
        navList.appendChild(li);
    });

    // Initial active state
    requestAnimationFrame(() => {
        updateNavBlob(0);
        syncNavbarSize();
    });
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
    const totalCount = String(data.length).padStart(2, '0');

    // Add a wrapper for animation
    const wrapper = document.createElement('div');
    wrapper.className = 'section-content projects-wrapper';
    wrapper.style.width = '100%';

    data.forEach((project, idx) => {
        const indexNum = String(idx + 1).padStart(2, '0');
        const stackHtml = project.techStack.map(key => {
            const iconUrl = assets[key];
            return iconUrl ? `<img src="${iconUrl}" alt="${key}" class="tech-icon" title="${key}">` : '';
        }).join('');

        let hostname = project.link;
        try {
            hostname = new URL(project.link).hostname.replace(/^www\./, '');
        } catch (e) {
            // fallback to raw link
        }

        const card = document.createElement('div');
        card.className = 'project-card';
        const titleClass = project.fontStyle === '8-bit' ? 'font-8bit' : 'font-stretched';

        const feedbackHtml = project.customer_feedback && project.customer_name ? `
            <div class="customer-feedback">
                <p class="feedback-quote">"${project.customer_feedback}"</p>
                <p class="feedback-name">— ${project.customer_name}</p>
            </div>
        ` : '';

        const maintainedHtml = project.maintained !== undefined 
            ? `<div class="project-maintained ${project.maintained ? 'active' : 'inactive'}">
                 <span class="status-dot"></span> ${project.maintained ? 'Actively Maintained' : 'No Longer Maintained'}
               </div>`
            : '';

        card.innerHTML = `
            <div class="project-bg" style="background-image: url('${project.image}')"></div>
            <div class="project-watermark" aria-hidden="true">${indexNum}</div>
            <div class="project-content">
                <div class="project-header-top">
                    <div class="project-meta-left">
                        <div class="project-pretext">Featured Project</div>
                    </div>
                    ${maintainedHtml}
                </div>

                <div class="project-main-grid">
                    <div class="project-info-col">
                        <h2 class="project-title ${titleClass}">${project.title}</h2>
                        <div class="project-divider"></div>
                        <p class="project-desc">${project.description}</p>
                        ${feedbackHtml}
                    </div>

                    <div class="project-visual-col">
                        <div class="project-showcase-frame">
                            <div class="showcase-browser-bar">
                                <div class="showcase-dots">
                                    <span></span><span></span><span></span>
                                </div>
                                <div class="showcase-domain">${hostname}</div>
                            </div>
                            <div class="showcase-img-wrap">
                                <img src="${project.image}" alt="${project.title}" class="showcase-img" loading="lazy">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="project-footer-bar">
                    <div class="tech-stack">${stackHtml}</div>
                    <div class="project-cta">
                        <span>Explore Project</span>
                        <span class="project-cta-arrow">↗</span>
                    </div>
                </div>
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
            <div class="contact-actions">
                <div class="gcal-btn-wrapper">
                    <span id="gcal-scheduling-target"></span>
                </div>
                <a href="https://myclientportal.top" target="_blank" rel="noopener noreferrer" class="contact-portal-btn">
                    <span>Go to MyClientPortal</span>
                    <span class="portal-btn-arrow">↗</span>
                </a>
            </div>
            <div class="arrow-container">
                <canvas id="arrow-canvas"></canvas>
            </div>
        </div>
    `;

    initGoogleCalendarButton();
    setTimeout(resizeWordArt, 100);
    window.addEventListener('resize', resizeWordArt);
}

function initGoogleCalendarButton() {
    const target = document.getElementById('gcal-scheduling-target');
    if (!target) return;

    const loadBtn = () => {
        if (window.calendar && window.calendar.schedulingButton) {
            window.calendar.schedulingButton.load({
                url: 'https://calendar.google.com/calendar/appointments/schedules/AcZssZ0sqrVQkdefjddPW4JsV94OcER0yjaUYFRaF3lZZQmqqtBwZTrFmOvWEd12oFdV-5vY4dVxiLaz?gv=true',
                color: '#039BE5',
                label: 'Book an appointment',
                target,
            });
            return true;
        }
        return false;
    };

    if (!loadBtn()) {
        window.addEventListener('load', loadBtn);
        let attempts = 0;
        const interval = setInterval(() => {
            attempts++;
            if (loadBtn() || attempts > 50) {
                clearInterval(interval);
            }
        }, 100);
    }
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

// Background Blob Mouse Interaction (uses CSS `translate` so it composes with `@keyframes drift` `transform`)
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

function syncNavbarSize() {
    const navbar = document.getElementById('navbar');
    const navList = document.getElementById('nav-list');
    const miniText = document.getElementById('nav-mini-text');
    if (!navbar || !navList || !miniText) return;

    const isCollapsedMini = navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover');

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

function scrollToSection(id, index) {
    const el = document.getElementById(id);
    if (el) {
        isProgrammaticScroll = true;
        clearTimeout(programmaticScrollTimer);
        programmaticScrollTimer = setTimeout(() => {
            isProgrammaticScroll = false;
        }, 1250);

        updateNavBlob(index);
        animateNavbarClick();

        const miniText = document.getElementById('nav-mini-text');
        if (miniText && contentData.navbar[index]) {
            clearMiniTextInlineStyles();
            miniText.textContent = contentData.navbar[index].sectionTitle;
        }

        lenis.scrollTo(el, {
            onComplete: () => {
                isProgrammaticScroll = false;
                clearTimeout(programmaticScrollTimer);
            }
        });

        // Contract navbar on touch/coarse pointer devices after selecting a section
        const navbar = document.getElementById('navbar');
        if (navbar.classList.contains('mini') && !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            navbar.classList.remove('expanded-hover');
            syncNavbarSize();
        }
    }
}

function animateNavbarClick() {
    const nav = document.getElementById('navbar');
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

    // Position using offset (blob is inside nav-list, sibling to nav-items)
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
    const navbar = document.getElementById('navbar');
    const miniText = document.getElementById('nav-mini-text');

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
            miniText.textContent = contentData.navbar[activeSection].sectionTitle;
            navbar.classList.remove('expanded-hover');
            syncNavbarSize();
        }
    };

    lenis.on('scroll', ({ scroll }) => {
        if (scroll > 100) {
            if (!navbar.classList.contains('mini')) {
                clearMiniTextInlineStyles();
                miniText.textContent = contentData.navbar[activeSection].sectionTitle;
                navbar.classList.add('mini');
                // If cursor is already inside navbar when crossing 100px, stay expanded until mouseleave
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

        // Active section detection (skip intermediate overrides while click-scrolling)
        if (!isProgrammaticScroll) {
            const sections = document.querySelectorAll('.section');
            const center = window.innerHeight / 2;
            sections.forEach((sec, i) => {
                const rect = sec.getBoundingClientRect();
                if (rect.top <= center && rect.bottom >= center) {
                    if (activeSection !== i) {
                        activeSection = i;
                        updateNavBlob(i);
                        if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
                            swipeMiniText(contentData.navbar[i].sectionTitle);
                        } else {
                            miniText.textContent = contentData.navbar[i].sectionTitle;
                        }
                    }
                }
            });
        }

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

    // Mouse interactions (Desktop) with small leave debounce to prevent cubic-bezier edge dip flicker
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

    // Click interaction (Mobile/Tablet)
    navbar.addEventListener('click', (e) => {
        e.stopPropagation();
        if (navbar.classList.contains('mini') && !navbar.classList.contains('expanded-hover')) {
            expandNavbar();
        }
    });

    // Close when clicking outside
    document.addEventListener('click', (e) => {
        if (!navbar.contains(e.target)) {
            isNavbarHovered = false;
            contractNavbar();
        }
    });
}

function initSectionObserver() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.classList.contains('active-view')) {
                entry.target.classList.add('active-view');
                setTimeout(() => {
                    entry.target.classList.add('transition-done');
                }, 850);
            }
        });
    }, { threshold: 0.02 });

    document.querySelectorAll('.section').forEach(sec => observer.observe(sec));
}

function swipeMiniText(newText) {
    const el = document.getElementById('nav-mini-text');
    if (!el) return;

    clearTimeout(swipeTimer);
    clearTimeout(swipeCleanupTimer);

    // 1. Exit Active
    el.style.transition = 'transform 0.2s ease, opacity 0.2s ease, filter 0.2s ease';
    el.style.transform = 'translateX(-20px)';
    el.style.opacity = '0';
    el.style.filter = 'blur(5px)';

    // 2. Wait for exit transition (200ms)
    swipeTimer = setTimeout(() => {
        // 3. Reset position instantly for entry & update pill width
        el.style.transition = 'none';
        el.style.transform = 'translateX(20px)';
        el.textContent = newText;
        syncNavbarSize();

        // Force Reflow
        void el.offsetWidth;

        // 4. Enter New
        el.style.transition = 'transform 0.3s cubic-bezier(.44,-0.08,0,1.38), opacity 0.25s ease, filter 0.25s ease';
        el.style.transform = 'translateX(0)';
        el.style.opacity = '1';
        el.style.filter = 'blur(0)';

        swipeCleanupTimer = setTimeout(() => {
            clearMiniTextInlineStyles();
        }, 320);
    }, 200);
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

// Arrow Animation (Curved arrow from cursor to @Freshebred when in Contact section)
function initArrowAnimation() {
    const canvas = document.getElementById('arrow-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    const resizeCanvas = () => {
        const dpr = window.devicePixelRatio || 1;
        canvas.width = window.innerWidth * dpr;
        canvas.height = window.innerHeight * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    let hasPointerMoved = false;
    let isTouchActive = false;
    let touchReleaseTimer = null;
    let mouseX = window.innerWidth * 0.75;
    let mouseY = window.innerHeight * 0.5;
    let currX = mouseX;
    let currY = mouseY;

    window.addEventListener('mousemove', (e) => {
        // Ignore synthetic mousemove events fired after touch taps on mobile
        if (window.matchMedia && !window.matchMedia('(pointer: fine)').matches) return;
        mouseX = e.clientX;
        mouseY = e.clientY;
        if (!hasPointerMoved) {
            hasPointerMoved = true;
            currX = mouseX;
            currY = mouseY;
        }
    });

    window.addEventListener('touchmove', (e) => {
        if (e.touches && e.touches.length > 0) {
            isTouchActive = true;
            mouseX = e.touches[0].clientX;
            mouseY = e.touches[0].clientY;
            clearTimeout(touchReleaseTimer);
            touchReleaseTimer = setTimeout(() => {
                isTouchActive = false;
            }, 1200);
        }
    }, { passive: true });

    function drawArrowLoop() {
        const contactSection = document.getElementById('contact');
        const target = document.getElementById('footer-handle');
        const popupOverlay = document.getElementById('popup-overlay');

        if (!contactSection || !target) {
            requestAnimationFrame(drawArrowLoop);
            return;
        }

        const contactRect = contactSection.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const isPopupOpen = popupOverlay && popupOverlay.classList.contains('active');

        // Active when user has scrolled down to Contact Me (or Footer)
        const isInContactSection = contactRect.top <= window.innerHeight * 0.65 && !isPopupOpen;

        // On mobile (or before desktop mouse moves), anchor smoothly to .arrow-container unless finger is dragging
        if (!hasPointerMoved && !isTouchActive) {
            const container = document.querySelector('.arrow-container');
            if (container) {
                const cRect = container.getBoundingClientRect();
                mouseX = cRect.left + cRect.width / 2;
                mouseY = cRect.top + cRect.height / 2;
            }
        }
        currX += (mouseX - currX) * 0.22;
        currY += (mouseY - currY) * 0.22;

        // Target center (@Freshebred link in footer)
        const rawTargetX = targetRect.left + targetRect.width / 2;
        const rawTargetY = targetRect.top + targetRect.height / 2;

        const rawDist = Math.hypot(rawTargetX - currX, rawTargetY - currY);

        if (isInContactSection && rawDist > 40) {
            canvas.classList.add('active');
            canvas.classList.remove('dissolve');
        } else {
            canvas.classList.remove('active');
            canvas.classList.add('dissolve');
        }

        ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);

        if (rawDist > 25) {
            // Determine visible endpoint (clamp to bottom of viewport if footer isn't scrolled into view yet)
            let ex = rawTargetX;
            let ey = rawTargetY;
            const bottomMargin = 22;

            if (rawTargetY > window.innerHeight - bottomMargin && currY < window.innerHeight - bottomMargin) {
                const t = (window.innerHeight - bottomMargin - currY) / (rawTargetY - currY);
                ex = currX + (rawTargetX - currX) * Math.max(0.1, Math.min(1, t));
                ey = window.innerHeight - bottomMargin;
            } else {
                // Stop just outside the @Freshebred element box so the arrowhead doesn't cover the text
                const angleToTarget = Math.atan2(rawTargetY - currY, rawTargetX - currX);
                const padX = (targetRect.width / 2) + 12;
                const padY = (targetRect.height / 2) + 10;
                const stopDist = Math.min(
                    rawDist * 0.35,
                    Math.hypot(Math.cos(angleToTarget) * padX, Math.sin(angleToTarget) * padY)
                );
                ex = rawTargetX - Math.cos(angleToTarget) * stopDist;
                ey = rawTargetY - Math.sin(angleToTarget) * stopDist;
            }

            const fullDx = ex - currX;
            const fullDy = ey - currY;
            const fullDist = Math.hypot(fullDx, fullDy);

            if (fullDist > 20) {
                // Quadratic Bezier control point with smooth perpendicular curve
                const mx = (currX + ex) / 2;
                const my = (currY + ey) / 2;
                const nx = -fullDy / fullDist;
                const ny = fullDx / fullDist;
                const curveOffset = Math.min(fullDist * 0.22, 130);

                const cx = mx + nx * curveOffset;
                const cy = my + ny * curveOffset;

                // Offset start slightly away from the cursor tip along initial tangent
                const startAngle = Math.atan2(cy - currY, cx - currX);
                const startOffset = Math.min(18, fullDist * 0.2);
                const sx = currX + Math.cos(startAngle) * startOffset;
                const sy = currY + Math.sin(startAngle) * startOffset;

                // Tangent angle at the end of quadratic Bezier (from control point to end point)
                const endAngle = Math.atan2(ey - cy, ex - cx);
                const headLength = 14;
                const headSpread = Math.PI / 6.5;

                ctx.save();
                ctx.strokeStyle = 'white';
                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';

                // Curved shaft
                ctx.beginPath();
                ctx.moveTo(sx, sy);
                ctx.quadraticCurveTo(cx, cy, ex, ey);
                ctx.stroke();

                // Arrowhead aligned with curve arrival tangent
                ctx.beginPath();
                ctx.moveTo(
                    ex - headLength * Math.cos(endAngle - headSpread),
                    ey - headLength * Math.sin(endAngle - headSpread)
                );
                ctx.lineTo(ex, ey);
                ctx.lineTo(
                    ex - headLength * Math.cos(endAngle + headSpread),
                    ey - headLength * Math.sin(endAngle + headSpread)
                );
                ctx.stroke();
                ctx.restore();
            }
        }

        requestAnimationFrame(drawArrowLoop);
    }

    requestAnimationFrame(drawArrowLoop);
}

// Start
loadContent();
