// Mobile filter toggle logic
function toggleMobileFilters() {
    console.log('toggleMobileFilters called');
    const filtersPanel = document.getElementById('filtersPanel');
    const filtersOverlay = document.getElementById('filtersOverlay');
    const toggleButton = document.getElementById('mobileFilterToggle');

    console.log('Elements found:', {
        filtersPanel: !!filtersPanel,
        filtersOverlay: !!filtersOverlay,
        toggleButton: !!toggleButton
    });

    if (!filtersPanel || !filtersOverlay || !toggleButton) {
        console.error('Required elements not found');
        return;
    }

    const isActive = filtersPanel.classList.contains('active');
    console.log('Current state - isActive:', isActive);

    if (isActive) {
        console.log('Closing filters');
        closeMobileFilters();
    } else {
        console.log('Opening filters');
        openMobileFilters();
    }
}

function openMobileFilters() {
    console.log('openMobileFilters called');
    const filtersPanel = document.getElementById('filtersPanel');
    const filtersOverlay = document.getElementById('filtersOverlay');
    const toggleButton = document.getElementById('mobileFilterToggle');

    if (!filtersPanel || !filtersOverlay || !toggleButton) {
        console.error('Required elements not found in openMobileFilters');
        return;
    }

    console.log('Before adding active class:', {
        filtersPanelClasses: filtersPanel.className,
        filtersOverlayClasses: filtersOverlay.className,
        toggleButtonClasses: toggleButton.className
    });

    try {
        filtersPanel.classList.add('active');
        filtersOverlay.classList.add('active');
        toggleButton.classList.add('active');
        document.body.style.overflow = 'hidden';

        console.log('After adding active class:', {
            filtersPanelClasses: filtersPanel.className,
            filtersOverlayClasses: filtersOverlay.className,
            toggleButtonClasses: toggleButton.className
        });
    } catch (error) {
        console.error('Error in openMobileFilters:', error);
    }
}

function closeMobileFilters() {
    console.log('closeMobileFilters called');
    const filtersPanel = document.getElementById('filtersPanel');
    const filtersOverlay = document.getElementById('filtersOverlay');
    const toggleButton = document.getElementById('mobileFilterToggle');

    if (!filtersPanel || !filtersOverlay || !toggleButton) {
        console.error('Required elements not found in closeMobileFilters');
        return;
    }

    try {
        filtersPanel.classList.remove('active');
        filtersOverlay.classList.remove('active');
        toggleButton.classList.remove('active');
        document.body.style.overflow = '';

        console.log('After removing active class:', {
            filtersPanelClasses: filtersPanel.className,
            filtersOverlayClasses: filtersOverlay.className,
            toggleButtonClasses: toggleButton.className
        });
    } catch (error) {
        console.error('Error in closeMobileFilters:', error);
    }
}

// Remove any existing event listeners to prevent duplicates
function removeExistingListeners() {
    const toggleButton = document.getElementById('mobileFilterToggle');
    const filtersOverlay = document.getElementById('filtersOverlay');
    const closeBtn = document.querySelector('.close-filters');

    if (toggleButton) {
        const newToggleButton = toggleButton.cloneNode(true);
        toggleButton.parentNode.replaceChild(newToggleButton, toggleButton);
    }

    if (filtersOverlay) {
        const newOverlay = filtersOverlay.cloneNode(true);
        filtersOverlay.parentNode.replaceChild(newOverlay, filtersOverlay);
    }

    if (closeBtn) {
        const newCloseBtn = closeBtn.cloneNode(true);
        closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
    }
}

document.addEventListener("DOMContentLoaded", function() {
    console.log('DOMContentLoaded - Mobile filters script loaded');

    // Remove any existing listeners to prevent duplicates
    removeExistingListeners();

    // Button event
    const toggleButton = document.getElementById('mobileFilterToggle');
    console.log('Toggle button found:', !!toggleButton);
    if (toggleButton) {
        toggleButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Toggle button clicked');
            toggleMobileFilters();
        });
        console.log('Click event listener added to toggle button');
    }

    // Overlay event
    const filtersOverlay = document.getElementById('filtersOverlay');
    console.log('Overlay found:', !!filtersOverlay);
    if (filtersOverlay) {
        filtersOverlay.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Overlay clicked');
            closeMobileFilters();
        });
        console.log('Click event listener added to overlay');
    }

    // Sluitknop in het filterpaneel
    const closeBtn = document.querySelector('.close-filters');
    console.log('Close button found:', !!closeBtn);
    if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Close button clicked');
            closeMobileFilters();
        });
        console.log('Click event listener added to close button');
    }

    // Close filters when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const filtersPanel = document.getElementById('filtersPanel');
        if (
            window.innerWidth <= 768 &&
            filtersPanel &&
            filtersPanel.classList.contains('active') &&
            !filtersPanel.contains(event.target) &&
            event.target !== toggleButton &&
            event.target !== filtersOverlay
        ) {
            console.log('Clicking outside - closing filters');
            closeMobileFilters();
        }
    });

    // Close filters on escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            console.log('Escape key pressed - closing filters');
            closeMobileFilters();
        }
    });

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            console.log('Window resized to desktop - closing filters');
            closeMobileFilters();
        }
    });
});