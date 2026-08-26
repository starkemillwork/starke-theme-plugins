(function() {
    // --- CSS INJECTIONS ---
    const style = document.createElement('style');
    style.textContent = `
        /* FIX: Whitelist transform, filter, and box-shadow to restore the native hover animations! */
        .blocks-gallery-item { opacity: 0; margin-bottom: 0 !important; }
        .blocks-gallery-item figure { margin: 0 !important; padding: 0 !important; display: block; overflow: hidden; }
        .blocks-gallery-item img { 
            transition: transform 0.4s ease, filter 0.4s ease !important; 
            display: block !important; vertical-align: bottom !important; margin: 0 !important; padding: 0 !important; width: 100%; height: auto; 
        }
        .blocks-gallery-item.starke-fade-in { 
            opacity: 1 !important; 
            transition: opacity 0.5s ease-in-out, transform 0.4s ease, box-shadow 0.4s ease !important; 
        }
        .wp-block-gallery { transition: height 0.3s ease; position: relative; }
        @keyframes starke-spin { 100% { transform: rotate(360deg); } }
    `;
    document.head.appendChild(style);

    // --- MASONRY LAYOUT ---
    function masonryLayout(ulElement) {
        if (!ulElement) return;
        
        const items = ulElement.querySelectorAll('.blocks-gallery-item');
        if (items.length === 0) return;

        const gap = 28; 
        const numCols = parseInt(getComputedStyle(ulElement).getPropertyValue('--masonry-cols')) || 4;
        const staggerOffset = 35; 

        if (ulElement.offsetWidth === 0) return;

        const colWidth = (ulElement.offsetWidth - (gap * (numCols - 1))) / numCols;
        let colHeights = Array(numCols).fill(0);

        items.forEach(item => {
            item.style.width = `${colWidth}px`;
            
            let itemHeight = 0;
            const img = item.querySelector('img');
            
            if (img) {
                const wAttr = img.getAttribute('width') || img.getAttribute('data-eio-rwidth');
                const hAttr = img.getAttribute('height') || img.getAttribute('data-eio-rheight');
                if (wAttr && hAttr) {
                    itemHeight = colWidth * (parseFloat(hAttr) / parseFloat(wAttr));
                    const caption = item.querySelector('figcaption');
                    if (caption) itemHeight += caption.offsetHeight; 
                } else {
                    itemHeight = item.offsetHeight || (colWidth * 1.3);
                }
            } else {
                itemHeight = item.offsetHeight;
            }

            const shortestColIndex = colHeights.indexOf(Math.min(...colHeights));
            let top = colHeights[shortestColIndex];

            if (top === 0 && shortestColIndex % 2 === 1) {
                top += staggerOffset;
                colHeights[shortestColIndex] += staggerOffset; 
            }
            
            const left = shortestColIndex * (colWidth + gap);
            
            // FIX: Turn off CSS transitions, set the --x/--y variables, force the browser
            // to apply them instantly, and turn transitions back on. This STOPS the flying 
            // animation on load, but keeps your native X/Y hover effect completely intact!
            item.style.transition = 'none';
            item.style.position = 'absolute';
            item.style.setProperty('--x', `${left}px`);
            item.style.setProperty('--y', `${top}px`);
            
            void item.offsetWidth; // Force a browser reflow
            item.style.transition = ''; // Hand control back to your theme!

            colHeights[shortestColIndex] += itemHeight + gap;
            
            setTimeout(() => item.classList.add('starke-fade-in'), 50);
        });

        ulElement.style.height = `${Math.max(...colHeights) - gap}px`;
    }

    // --- INITIALIZER ---
    function initGallery(activeContainer) {
        const placeholder = activeContainer.querySelector('.starke-ajax-placeholder');
        
        if (placeholder) {
            if (placeholder.dataset.isFetching === 'true') return;
            placeholder.dataset.isFetching = 'true';
            
            const pId = placeholder.getAttribute('data-post-id');
            const gIndex = placeholder.getAttribute('data-gallery-index');
            
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    'starke_ajax_load': '1',
                    'post_id': pId,
                    'gallery_index': gIndex
                })
            })
                .then(res => res.json())
                .then(data => {
                    if (data && data.success && data.html) {
                        placeholder.insertAdjacentHTML('beforebegin', data.html);
                        placeholder.remove(); 
                        
                        const ul = activeContainer.querySelector('ul');
                        if (ul) setupTab(ul);
                    } else {
                        placeholder.innerHTML = '<p>Gallery failed to load.</p>';
                    }
                })
                .catch(err => {
                    console.error("AJAX Error: ", err);
                    placeholder.innerHTML = '<p>Gallery failed to load.</p>';
                });
            return;
        }

        const ul = activeContainer.querySelector('ul.wp-block-gallery');
        if (ul) setupTab(ul);
    }

    function setupTab(ulElement) {
        if (ulElement.dataset.starkeInit === 'true') {
            masonryLayout(ulElement);
            return;
        }
        ulElement.dataset.starkeInit = 'true';
        
        masonryLayout(ulElement);

        const initialImgs = ulElement.querySelectorAll('img');
        initialImgs.forEach(img => {
            if (!img.complete) {
                img.addEventListener('load', () => masonryLayout(ulElement));
            }
        });
    }

    // --- TAB SWITCHING LOGIC ---
    const tabsContainer = document.querySelector('.gallery-tabs-container');
    if (tabsContainer) {
        const loadedClass = 'js-loaded';
        const tabs = tabsContainer.querySelectorAll('.photo-tab');
        const contents = tabsContainer.querySelectorAll('.photo-tab-content');
        const mainContent = document.querySelector('.entry-content');
        
        // NEW: Object to store exact scroll depths for each tab
        const tabScrollPositions = {};

        if (mainContent) {
            const contentContainers = mainContent.querySelectorAll('.wp-block-uagb-container');
            contents.forEach((tabContent, index) => {
                if (contentContainers[index]) {
                    tabContent.appendChild(contentContainers[index]);
                }
            });
        }

        function switchTab(targetTabId, isInitialLoad = false) {
            // 1. Identify current tab and save its scroll position before leaving
            let currentTabId = null;
            const activeTabEl = document.querySelector('.photo-tab.active');
            if (activeTabEl) {
                currentTabId = activeTabEl.getAttribute('data-tab');
            }
            
            if (!isInitialLoad && currentTabId && currentTabId !== targetTabId) {
                tabScrollPositions[currentTabId] = window.scrollY;
            }

            // 2. Toggle Active Classes
            tabs.forEach(t => t.classList.toggle('active', t.getAttribute('data-tab') === targetTabId));
            contents.forEach(c => c.classList.toggle('active', c.id === `content-for-${targetTabId}`));
            
            if (!isInitialLoad) {
                history.pushState(null, null, `#${targetTabId}`);
            } else {
                history.replaceState(null, null, `#${targetTabId}`);
            }

            // 3. Handle deterministic scrolling for the target tab
            if (!isInitialLoad) {
                if (tabScrollPositions[targetTabId] !== undefined) {
                    // Goal 1: Restore exact saved position for THIS tab
                    window.scrollTo({ top: tabScrollPositions[targetTabId], behavior: 'instant' });
                } else {
                    // Goal 2: First time clicking THIS tab. 
                    const containerRect = tabsContainer.getBoundingClientRect();
                    
                    // Grab the wrapper of the tabs to read the sticky offset
                    const tabsNav = tabs.length > 0 ? tabs[0].parentElement : null;
                    
                    if (tabsNav) {
                        const computedTop = window.getComputedStyle(tabsNav).top;
                        let exactStickyOffset = parseInt(computedTop, 10) || 120; 
                        const nudge = 0; // Adjust this if margins throw off the alignment
                        
                        if (containerRect.top < exactStickyOffset) {
                            const absoluteTop = window.scrollY + containerRect.top;
                            window.scrollTo({ 
                                top: Math.max(0, absoluteTop - exactStickyOffset + nudge), 
                                behavior: 'smooth' 
                            });
                        }
                    }
                }
            }
            
            // 4. Initialize Gallery Content
            const activeContainer = document.querySelector(`#content-for-${targetTabId}`);
            if (!activeContainer) return;

            setTimeout(() => {
                initGallery(activeContainer);
                
                setTimeout(() => {
                    const activeGallery = activeContainer.querySelector('.wp-block-gallery');
                    if (activeGallery) masonryLayout(activeGallery);
                }, 400);

            }, 150);
        }

        tabs.forEach(tab => {
            tab.addEventListener('click', () => switchTab(tab.getAttribute('data-tab'), false));
        });

        window.addEventListener('hashchange', function() {
            const newHash = window.location.hash.substring(1);
            const targetTab = document.querySelector(`.photo-tab[data-tab="${newHash}"]`);
            if (newHash && targetTab) switchTab(newHash, false);
        });

        const currentHash = window.location.hash.substring(1);
        const targetTab = document.querySelector(`.photo-tab[data-tab="${currentHash}"]`);

        // Wait until the page is fully painted before fetching the first tab
        window.addEventListener('load', function() {
            if (currentHash && targetTab) {
                switchTab(currentHash, true);
            } else if (tabs.length > 0) {
                switchTab(tabs[0].getAttribute('data-tab'), true);
            }
        });

        tabsContainer.classList.add(loadedClass);

        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                const activeContainer = document.querySelector('.photo-tab-content.active');
                if (activeContainer) {
                    const activeGallery = activeContainer.querySelector('.wp-block-gallery');
                    masonryLayout(activeGallery);
                }
            }, 150);
        });
    }

    // --- LIGHTBOX LOGIC ---
    const lightbox = document.getElementById('starke-lightbox');
    const lightboxImage = document.getElementById('lightbox-image');
    const closeBtn = document.querySelector('.starke-lightbox-close');
    const prevBtn = document.querySelector('.starke-lightbox-prev');
    const nextBtn = document.querySelector('.starke-lightbox-next');
    const galleryContainer = document.getElementById('galleryTabContents');

    let currentGalleryImages = [];
    let currentImageIndex = 0;

    if (!lightbox || !lightboxImage || !closeBtn || !prevBtn || !nextBtn || !galleryContainer) return;

    const updateLightboxImage = () => {
        const imgElement = currentGalleryImages[currentImageIndex];
        const srcset = imgElement.getAttribute('data-srcset-webp') || imgElement.getAttribute('data-srcset') || imgElement.srcset;
        const src = imgElement.getAttribute('data-src-webp') || imgElement.getAttribute('data-src') || imgElement.src;

        if (srcset && srcset !== '') {
            const fullSizeUrl = srcset.split(',')[0].trim().split(' ')[0];
            lightboxImage.src = fullSizeUrl;
        } else if (src) {
            lightboxImage.src = src;
        }
    };

    const openLightbox = (clickedImage) => {
        const activeTab = galleryContainer.querySelector('.photo-tab-content.active');
        if (!activeTab) return;
        
        currentGalleryImages = Array.from(activeTab.querySelectorAll('.blocks-gallery-item img'));
        currentImageIndex = currentGalleryImages.findIndex(img => img === clickedImage);
        
        if (currentImageIndex !== -1) {
            updateLightboxImage();
            lightbox.style.display = 'flex';
        }
    };

    const closeLightbox = () => {
        lightbox.style.display = 'none';
        lightboxImage.src = '';
        currentGalleryImages = [];
        currentImageIndex = 0;
    };
    
    const showNext = () => {
        currentImageIndex = (currentImageIndex + 1) % currentGalleryImages.length;
        updateLightboxImage();
    };
    
    const showPrev = () => {
        currentImageIndex = (currentImageIndex - 1 + currentGalleryImages.length) % currentGalleryImages.length;
        updateLightboxImage();
    };

    galleryContainer.addEventListener('click', function(e) {
        const clickedImage = e.target.closest('.blocks-gallery-item img');
        if (clickedImage) {
            e.preventDefault();
            openLightbox(clickedImage);
        }
    });

    closeBtn.addEventListener('click', closeLightbox);
    prevBtn.addEventListener('click', showPrev);
    nextBtn.addEventListener('click', showNext);
    
    lightbox.addEventListener('click', (e) => {
        if (e.target === lightbox) closeLightbox();
    });
    
    document.addEventListener('keydown', (e) => {
        if (lightbox.style.display === 'flex') {
            if (e.key === 'ArrowRight') showNext();
            if (e.key === 'ArrowLeft') showPrev();
            if (e.key === 'Escape') closeLightbox();
        }
    });

    lightbox.addEventListener('wheel', (e) => {
        if (lightbox.style.display === 'flex' && currentGalleryImages.length > 1) {
            e.preventDefault(); // Stop the page from scrolling
            if (e.deltaY > 0) showNext();
            else if (e.deltaY < 0) showPrev();
        }
    }, { passive: false });

    let touchStartX = 0;
    let touchStartY = 0;
    let touchEndX = 0;
    let touchEndY = 0;

    lightbox.addEventListener('touchstart', (e) => {
        if (lightbox.style.display === 'flex') {
            touchStartX = e.changedTouches[0].screenX;
            touchStartY = e.changedTouches[0].screenY; // Track starting Y position
        }
    }, { passive: false });

    lightbox.addEventListener('touchmove', (e) => {
        if (lightbox.style.display === 'flex') {
            e.preventDefault(); // Prevents background scrolling while swiping
        }
    }, { passive: false });

    lightbox.addEventListener('touchend', (e) => {
        if (lightbox.style.display === 'flex' && currentGalleryImages.length > 1) {
            touchEndX = e.changedTouches[0].screenX;
            touchEndY = e.changedTouches[0].screenY; // Track ending Y position
            
            const swipeThresholdX = 25; // Standard threshold for left/right
            const swipeThresholdY = 10; // Much shorter threshold for up/down

            let diffX = touchStartX - touchEndX;
            let diffY = touchStartY - touchEndY;

            // Determine if the user swiped mostly sideways or mostly up/down
            if (Math.abs(diffX) > Math.abs(diffY)) {
                // Horizontal Swipe
                if (diffX > swipeThresholdX) showNext(); // Swiped left
                else if (diffX < -swipeThresholdX) showPrev(); // Swiped right
            } else {
                // Vertical Swipe
                if (diffY > swipeThresholdY) showNext(); // Swiped up
                else if (diffY < -swipeThresholdY) showPrev(); // Swiped down
            }
        }
    }, { passive: false });

})();