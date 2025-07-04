// Favorites functionality (from scripts.js)
+(function(global){
    if (typeof global.toggleFavorite === 'undefined') {
        global.toggleFavorite = function(car) {
            try {
                let favorites = JSON.parse(localStorage.getItem('favorites') || '[]');
                if (typeof car === 'string') {
                    car = JSON.parse(car);
                }
                if (!car || !car.kenteken) return;
                const idx = favorites.findIndex(f => f.kenteken === car.kenteken);
                if (idx > -1) {
                    favorites.splice(idx, 1);
                } else {
                    favorites.push(car);
                }
                localStorage.setItem('favorites', JSON.stringify(favorites));
            } catch(e){console.error(e);}
        };
    }
})(window);

// Image preloading functionality (from scripts.js)
function preloadImages(images) {
    images.forEach(function(src) {
        const img = new Image();
        img.src = src;
    });
}

// Initialize image preloading if data is available
if (typeof vweData !== 'undefined' && vweData.preloadImages) {
    preloadImages(vweData.preloadImages);
}

document.addEventListener("DOMContentLoaded", function() {
    console.log("VWE Listing JS loaded");

    // Check if data is available
    if (!window.vweListingData) {
        console.error("vweListingData not found - page may not be loading properly");
        return;
    }

    console.log("vweListingData found:", window.vweListingData);

    let currentPage = window.vweListingData?.currentPage || 1;
    let carsPerPage = window.vweListingData?.carsPerPage || 9;
    let allCars = window.vweListingData?.allCars || [];
    let filteredCars = allCars.slice();
    const totalItems = allCars.length;

    console.log("Initialized with:", { currentPage, carsPerPage, totalItems });

    // Global variables for modal functionality
    window.currentSlide = 0;
    window.totalSlides = 0;

    function checkFilters(carData) {
        // Add null checks for all DOM elements
        const brandFilter = document.getElementById("brandFilter");
        const modelFilter = document.getElementById("modelFilter");
        const fuelFilter = document.getElementById("fuelFilter");
        const transmissionFilter = document.getElementById("transmissionFilter");
        const bodyFilter = document.getElementById("bodyFilter");
        const doorsFilter = document.getElementById("doorsFilter");
        const seatsFilter = document.getElementById("seatsFilter");
        const priceMin = document.getElementById("priceMin");
        const priceMax = document.getElementById("priceMax");
        const kmMin = document.getElementById("kmMin");
        const kmMax = document.getElementById("kmMax");
        const powerMin = document.getElementById("powerMin");
        const powerMax = document.getElementById("powerMax");

        // Get values safely
        const brandValue = brandFilter ? brandFilter.value : "";
        const modelValue = modelFilter ? modelFilter.value : "";
        const fuelValue = fuelFilter ? fuelFilter.value : "";
        const transmissionValue = transmissionFilter ? transmissionFilter.value : "";
        const bodyValue = bodyFilter ? bodyFilter.value : "";
        const doorsValue = doorsFilter ? doorsFilter.value : "";
        const seatsValue = seatsFilter ? seatsFilter.value : "";
        const priceMinValue = priceMin ? priceMin.value : "";
        const priceMaxValue = priceMax ? priceMax.value : "";
        const kmMinValue = kmMin ? kmMin.value : "";
        const kmMaxValue = kmMax ? kmMax.value : "";
        const powerMinValue = powerMin ? powerMin.value : "";
        const powerMaxValue = powerMax ? powerMax.value : "";

        const yearCheckboxes = document.querySelectorAll("input[name='year']:checked");
        const selectedYears = Array.from(yearCheckboxes).map(cb => cb.value);
        const yearMatch = selectedYears.includes("all") || selectedYears.some(range => {
            const [start, end] = range.split("-").map(Number);
            return carData.bouwjaar >= start && carData.bouwjaar <= end;
        });

        const statusCheckboxes = document.querySelectorAll("input[name='status']:checked");
        const selectedStatuses = Array.from(statusCheckboxes).map(cb => cb.value);
        const statusMatch = selectedStatuses.includes("all") || selectedStatuses.includes(carData.status);

        const carPrice = parseFloat((carData.prijs||"").toString().replace(/[^0-9.]/g, ""));
        const carKm = parseFloat((carData.kilometerstand||"").toString().replace(/[^0-9]/g, ""));
        const carPower = parseFloat((carData.vermogen_pk||"").toString().replace(/[^0-9]/g, ""));

        return (!brandValue || carData.merk === brandValue) &&
            (!modelValue || carData.model === modelValue) &&
            (!fuelValue || carData.brandstof === fuelValue) &&
            (!transmissionValue || carData.transmissie === transmissionValue) &&
            (!bodyValue || carData.carrosserie === bodyValue) &&
            (!doorsValue || carData.deuren === doorsValue) &&
            (!seatsValue || carData.aantal_zitplaatsen === seatsValue) &&
            (!priceMinValue || carPrice >= parseFloat(priceMinValue)) &&
            (!priceMaxValue || carPrice <= parseFloat(priceMaxValue)) &&
            (!kmMinValue || carKm >= parseFloat(kmMinValue)) &&
            (!kmMaxValue || carKm <= parseFloat(kmMaxValue)) &&
            (!powerMinValue || carPower >= parseFloat(powerMinValue)) &&
            (!powerMaxValue || carPower <= parseFloat(powerMaxValue)) &&
            yearMatch &&
            statusMatch;
    }

    function renderCars() {
        console.log("renderCars called");
        const carsGrid = document.getElementById("carsGrid");
        if (!carsGrid) {
            console.error("carsGrid element not found");
            return;
        }

        console.log("Rendering cars:", filteredCars.length);
        carsGrid.innerHTML = "";
        const start = (currentPage - 1) * carsPerPage;
        const end = start + carsPerPage;
        const carsToShow = filteredCars.slice(start, end);

        console.log("Showing cars from", start, "to", end, ":", carsToShow.length);

        carsToShow.forEach(car => {
            const card = document.createElement("div");
            card.className = "car-card";
            card.dataset.car = JSON.stringify(car);

            // Bepaal SEO-vriendelijke slug op basis van titel of merk+model
            const carTitle = car.titel ||
                `${car.merk} ${car.model}${car.cilinder_inhoud ? " " + car.cilinder_inhoud : ""}${car.transmissie ? " " + car.transmissie : ""}${car.brandstof ? " " + car.brandstof : ""}${car.deuren ? " " + car.deuren + " Deurs" : ""}`;
            const slug = car.slug ? car.slug : carTitle
                .toLowerCase()
                .replace(/[^a-z0-9\s-]/g, "")
                .trim()
                .replace(/\s+/g, "-")
                .replace(/-+/g, "-");

            const carTitleDisplay = car.titel ||
                `${car.merk} ${car.model}${car.cilinder_inhoud ? " / " + car.cilinder_inhoud : ""}${car.transmissie ? " / " + car.transmissie : ""}${car.brandstof ? " / " + car.brandstof : ""}${car.deuren ? " / " + car.deuren + " Deurs" : ""} / NL Auto`;

            card.innerHTML = `
                <div class="car-image">
                    <img src="${car.eersteAfbeelding}" alt="${car.merk} ${car.model}" class="car-image" loading="lazy" decoding="async" style="cursor: pointer;" onclick="openModal(${JSON.stringify(car).replace(/"/g, '&quot;')})">
                    <div class="car-badges">
                        <span class="status-badge ${car.status}">${car.status === "beschikbaar" ? "AVAILABLE" : (car.status === "verkocht" ? "VERKOCHT" : "GERESERVEERD")}</span>
                        <span class="year-badge">${car.bouwjaar}</span>
                    </div>
                </div>
                <div class="car-info">
                    <div class="car-brand">${car.merk.toUpperCase()}</div>
                    <h3 class="car-title">${carTitleDisplay}</h3>
                    <div class="car-price">€ ${Number((car.prijs||"").toString().replace(/[^0-9.]/g, "")).toLocaleString("nl-NL")}</div>
                    <div class="car-specs">
                        <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/Tank.svg" alt="Kilometerstand" width="18" style="vertical-align:middle;margin-right:4px;">${car.kilometerstand || "0 km"}</span>
                        <span><img src="https://raw.githubusercontent.com/anouarlafkri/SVG/main/pK.svg" alt="Vermogen" width="18" style="vertical-align:middle;margin-right:4px;">${car.vermogen || "0 pk"}</span>
                    </div>
                    <button class="view-button" onclick="openModal(${JSON.stringify(car).replace(/"/g, '&quot;')})">BEKIJKEN <span class="arrow">→</span></button>
                </div>
            `;
            carsGrid.appendChild(card);
        });
    }

    function updatePagination() {
        const totalPages = Math.ceil(filteredCars.length / carsPerPage);
        const prevBtn = document.querySelector(".pagination-prev");
        const nextBtn = document.querySelector(".pagination-next");

        if (prevBtn) prevBtn.disabled = currentPage === 1;
        if (nextBtn) nextBtn.disabled = currentPage === totalPages || totalPages === 0;

        const pageButtons = document.querySelectorAll(".pagination-number");
        pageButtons.forEach(button => {
            const pageNum = parseInt(button.dataset.page);
            button.classList.toggle("active", pageNum === currentPage);
            button.disabled = pageNum === currentPage;
        });
    }

    function goToPage(page) {
        if (page < 1 || page > Math.ceil(filteredCars.length / carsPerPage)) return;
        currentPage = page;
        renderCars();
        updatePagination();
        const carsGrid = document.getElementById("carsGrid");
        if (carsGrid) carsGrid.scrollIntoView({ behavior: "smooth" });
    }

    function applyFilters() {
        filteredCars = allCars.filter(checkFilters);
        sortCars();
        currentPage = 1;
        renderCars();
        updatePagination();
        updateResultsCount();
    }

    function sortCars() {
        const sortSelect = document.getElementById("sortSelect");
        if (!sortSelect) return;

        const sortValue = sortSelect.value;
        if (!sortValue) return;

        const [sortBy, sortOrder] = sortValue.split("-");
        filteredCars.sort((a, b) => {
            let valA, valB;
            if (sortBy === "prijs") {
                valA = parseFloat((a.prijs || "").toString().replace(/[^0-9.]/g, ""));
                valB = parseFloat((b.prijs || "").toString().replace(/[^0-9.]/g, ""));
            } else if (sortBy === "km") {
                valA = parseFloat((a.kilometerstand || "").toString().replace(/[^0-9]/g, ""));
                valB = parseFloat((b.kilometerstand || "").toString().replace(/[^0-9]/g, ""));
            } else if (sortBy === "jaar") {
                valA = parseInt(a.bouwjaar);
                valB = parseInt(b.bouwjaar);
            } else {
                return 0;
            }
            if (isNaN(valA)) valA = sortOrder === "asc" ? Infinity : -Infinity;
            if (isNaN(valB)) valB = sortOrder === "asc" ? Infinity : -Infinity;
            if (valA < valB) return sortOrder === "asc" ? -1 : 1;
            if (valA > valB) return sortOrder === "asc" ? 1 : -1;
            return 0;
        });
    }

    function updateResultsCount() {
        const resultCountElement = document.getElementById("resultCount");
        if (resultCountElement) {
            const count = filteredCars.length;
            resultCountElement.textContent = count + (count === 1 ? " result found" : " results found");
        }
    }

    function changePage(direction) {
        const totalPages = Math.ceil(filteredCars.length / carsPerPage);
        const newPage = currentPage + direction;
        if (newPage < 1 || newPage > totalPages) return;
        currentPage = newPage;
        renderCars();
        updatePagination();
        const carsGrid = document.getElementById("carsGrid");
        if (carsGrid) carsGrid.scrollIntoView({ behavior: "smooth" });
    }

    function resetAllFilters() {
        document.querySelectorAll("select").forEach(select => { select.value = ""; });
        document.querySelectorAll("input[type='number']").forEach(input => { input.value = ""; });
        document.querySelectorAll("input[type='checkbox']").forEach(checkbox => { checkbox.checked = checkbox.value === "all"; });
        applyFilters();
    }

    // Modal functions
    function openModal(carData) {
        const modal = document.getElementById("carModal");
        if (!modal) return;

        const carouselSlides = modal.querySelector(".carousel-slides");
        const carouselDots = modal.querySelector(".carousel-dots");
        const carouselCounter = modal.querySelector(".carousel-counter");
        const details = modal.querySelector(".modal-details");

        // Generate URL-friendly title
        const carTitle = carData.titel || `${carData.merk} ${carData.model}${carData.cilinder_inhoud ? ' ' + carData.cilinder_inhoud : ''}${carData.transmissie ? ' ' + carData.transmissie : ''}${carData.brandstof ? ' ' + carData.brandstof : ''}${carData.deuren ? ' ' + carData.deuren + ' Deurs' : ''}`;
        const carId = carTitle.toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '') // Remove special characters
            .replace(/\s+/g, '-') // Replace spaces with hyphens
            .replace(/-+/g, '-') // Replace multiple hyphens with single hyphen
            .replace(/^-|-$/g, ''); // Remove leading/trailing hyphens

        const carUrl = window.location.origin + window.location.pathname + '?car=' + carId;

        // Update browser URL without reloading the page
        window.history.pushState({car: carData}, '', carUrl);

        // Reset carousel
        carouselSlides.innerHTML = "";
        carouselDots.innerHTML = "";

        if (carData.afbeeldingen && carData.afbeeldingen.length > 0) {
            carData.afbeeldingen.forEach((image, index) => {
                const slide = document.createElement("div");
                slide.className = "carousel-slide";
                slide.innerHTML = `
                    <img src="${image}"
                         alt="${carData.merk} ${carData.model} - Image ${index + 1}"
                         loading="lazy"
                         decoding="async">
                `;
                carouselSlides.appendChild(slide);

                const dot = document.createElement("div");
                dot.className = "carousel-dot" + (index === 0 ? " active" : "");
                dot.onclick = () => goToSlide(index);
                carouselDots.appendChild(dot);
            });

            window.totalSlides = carData.afbeeldingen.length;
            window.currentSlide = 0;
            updateCarousel();
            updateCarouselCounter();
        }

        // Add keyboard navigation
        document.addEventListener("keydown", function(e) {
            if (modal.style.display === "block") {
                if (e.key === "ArrowLeft") prevSlide();
                if (e.key === "ArrowRight") nextSlide();
            }
        });

        details.innerHTML = `
            <div class="modal-sections">
                <div class="modal-section title-section" data-car='${JSON.stringify(carData)}'>
                    <div class="title-content">
                        <h3>${carData.titel || (carData.merk + ' ' + carData.model)}</h3>
                        <div class="car-status ${carData.status}">${carData.status.toUpperCase()}</div>
                    </div>
                    <div class="price-tag">€ ${Number(carData.prijs).toLocaleString("nl-NL")}</div>
                </div>

                <div class="modal-section">
                    <h4><i class="fas fa-info-circle"></i> Belangrijke Specificaties</h4>
                    <div class="specs-grid">
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-calendar"></i> Bouwjaar</span>
                            <span class="spec-value">${carData.bouwjaar || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-tachometer-alt"></i> Kilometerstand</span>
                            <span class="spec-value">${carData.kilometerstand || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-gas-pump"></i> Brandstof</span>
                            <span class="spec-value">${carData.brandstof || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-cog"></i> Transmissie</span>
                            <span class="spec-value">${carData.transmissie || "N/A"}</span>
                        </div>
                    </div>
                </div>

                <div class="modal-section">
                    <h4><i class="fas fa-tools"></i> Technische Details</h4>
                    <div class="specs-grid">
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-bolt"></i> Vermogen</span>
                            <span class="spec-value">${carData.vermogen || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-compress-arrows-alt"></i> Cilinder Inhoud</span>
                            <span class="spec-value">${carData.cilinder_inhoud || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-cogs"></i> Aantal Cilinders</span>
                            <span class="spec-value">${carData.cilinders || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-weight"></i> Gewicht</span>
                            <span class="spec-value">${carData.gewicht || "N/A"}</span>
                        </div>
                    </div>
                </div>

                <div class="modal-section">
                    <h4><i class="fas fa-car"></i> Voertuig Kenmerken</h4>
                    <div class="specs-grid">
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-car-side"></i> Carrosserie</span>
                            <span class="spec-value">${carData.carrosserie || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-door-open"></i> Aantal Deuren</span>
                            <span class="spec-value">${carData.deuren || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-chair"></i> Aantal Zitplaatsen</span>
                            <span class="spec-value">${carData.aantal_zitplaatsen || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-palette"></i> Kleur</span>
                            <span class="spec-value">${carData.kleur || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-paint-brush"></i> Interieur Kleur</span>
                            <span class="spec-value">${carData.interieurkleur || "N/A"}</span>
                        </div>
                        <div class="spec-item">
                            <span class="spec-label"><i class="fas fa-couch"></i> Bekleding</span>
                            <span class="spec-value">${carData.bekleding || "N/A"}</span>
                        </div>
                    </div>
                </div>

                ${carData.opmerkingen ? `
                    <div class="modal-section">
                        <h4><i class="fas fa-align-left"></i> Beschrijving</h4>
                        <div class="description-content">
                            ${carData.opmerkingen}
                        </div>
                    </div>
                ` : ""}
            </div>
        `;

        modal.style.display = "block";
        document.body.style.overflow = "hidden"; // Prevent background scrolling
    }

    function closeModal() {
        const modal = document.getElementById("carModal");
        if (!modal) return;

        modal.style.display = "none";
        document.body.style.overflow = ""; // Restore scrolling
        // Reset URL when closing modal
        window.history.pushState({}, '', window.location.pathname);
    }

    function updateCarousel() {
        const slides = document.querySelector(".carousel-slides");
        if (!slides || !window.totalSlides) return;
        slides.style.transform = "translateX(-" + (window.currentSlide * 100) + "%)";
        const dots = document.querySelectorAll(".carousel-dot");
        dots.forEach((dot, index) => {
            dot.classList.toggle("active", index === window.currentSlide);
        });
    }

    function nextSlide() {
        if (!window.totalSlides) return;
        window.currentSlide = (window.currentSlide + 1) % window.totalSlides;
        updateCarousel();
        updateCarouselCounter();
    }

    function prevSlide() {
        if (!window.totalSlides) return;
        window.currentSlide = (window.currentSlide - 1 + window.totalSlides) % window.totalSlides;
        updateCarousel();
        updateCarouselCounter();
    }

    function goToSlide(index) {
        if (!window.totalSlides) return;
        window.currentSlide = index;
        updateCarousel();
        updateCarouselCounter();
    }

    function updateCarouselCounter() {
        const counter = document.querySelector(".carousel-counter");
        if (counter && window.totalSlides) {
            counter.textContent = `${window.currentSlide + 1} / ${window.totalSlides}`;
        }
    }

    function openLightbox(imageSrc) {
        const lightbox = document.createElement("div");
        lightbox.className = "lightbox";
        lightbox.innerHTML = `
            <img src="${imageSrc}" alt="Full size image">
            <button class="lightbox-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        document.body.appendChild(lightbox);
    }

    function shareCar() {
        const titleSection = document.querySelector(".modal-section.title-section");
        if (!titleSection) return;

        const carData = JSON.parse(titleSection.dataset.car);

        // Generate URL-friendly title
        const carTitle = carData.titel || `${carData.merk} ${carData.model}${carData.cilinder_inhoud ? ' ' + carData.cilinder_inhoud : ''}${carData.transmissie ? ' ' + carData.transmissie : ''}${carData.brandstof ? ' ' + carData.brandstof : ''}${carData.deuren ? ' ' + carData.deuren + ' Deurs' : ''}`;
        const carId = carTitle.toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '') // Remove special characters
            .replace(/\s+/g, '-') // Replace spaces with hyphens
            .replace(/-+/g, '-') // Replace multiple hyphens with single hyphen
            .replace(/^-|-$/g, ''); // Remove leading/trailing hyphens

        const shareUrl = window.location.origin + window.location.pathname + '?car=' + carId;

        const shareData = {
            title: carTitle,
            text: `Bekijk deze ${carTitle} op onze website!`,
            url: shareUrl
        };

        if (navigator.share) {
            navigator.share(shareData)
                .then(() => {
                    showNotification('Succesvol gedeeld!');
                })
                .catch((error) => {
                    console.error('Error sharing:', error);
                    fallbackShare(shareUrl);
                });
        } else {
            fallbackShare(shareUrl);
        }
    }

    function fallbackShare(url) {
        const dummy = document.createElement("input");
        document.body.appendChild(dummy);
        dummy.value = url;
        dummy.select();
        document.execCommand("copy");
        document.body.removeChild(dummy);
        showNotification('Link gekopieerd naar klembord!');
    }

    function showNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'notification';
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.classList.add('show');
        }, 100);

        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 300);
        }, 3000);
    }

    function printCarDetails() {
        window.print();
    }

    // Make functions globally available
    window.openModal = openModal;
    window.closeModal = closeModal;
    window.updateCarousel = updateCarousel;
    window.nextSlide = nextSlide;
    window.prevSlide = prevSlide;
    window.goToSlide = goToSlide;
    window.updateCarouselCounter = updateCarouselCounter;
    window.openLightbox = openLightbox;
    window.shareCar = shareCar;
    window.fallbackShare = fallbackShare;
    window.showNotification = showNotification;
    window.printCarDetails = printCarDetails;

    // Event listeners
    document.addEventListener("change", function(e) {
        if (e.target.matches("select, input[type='number'], input[type='checkbox']")) {
            applyFilters();
        }
    });

    document.addEventListener("click", function(e) {
        if (e.target.matches(".pagination-prev")) {
            e.preventDefault();
            changePage(-1);
        } else if (e.target.matches(".pagination-next")) {
            e.preventDefault();
            changePage(1);
        } else if (e.target.matches(".pagination-number")) {
            e.preventDefault();
            const page = parseInt(e.target.dataset.page);
            goToPage(page);
        } else if (e.target.matches("#resetFilters")) {
            e.preventDefault();
            resetAllFilters();
        } else if (e.target.matches(".modal-close") || e.target.matches("#carModal")) {
            if (e.target === e.currentTarget || e.target.matches(".modal-close")) {
                closeModal();
            }
        }
    });

    // Initialize
    console.log("Starting initialization...");
    renderCars();
    updatePagination();
    updateResultsCount();
    console.log("Initialization complete");
});