let currentSlide = 0;
let totalSlides = 0;

function showCarDetails(button) {
    const carCard = button.closest(".car-card");
    if (!carCard) return;

    try {
        const carData = JSON.parse(carCard.dataset.car);
        openModal(carData);
    } catch (e) {
        console.error("Error parsing car data:", e);
    }
}

function openModal(carData) {
    const modal = document.getElementById("carModal");
    const carouselSlides = modal.querySelector(".carousel-slides");
    const carouselDots = modal.querySelector(".carousel-dots");
    const details = modal.querySelector(".modal-details");

    // Reset carousel
    carouselSlides.innerHTML = "";
    carouselDots.innerHTML = "";

    // Setup carousel with images
    if (carData.afbeeldingen && carData.afbeeldingen.length > 0) {
        carData.afbeeldingen.forEach((image, index) => {
            const slide = document.createElement("div");
            slide.className = "carousel-slide";
            slide.innerHTML = `<img src="${image}" alt="${carData.merk} ${carData.model} - Image ${index + 1}">`;
            carouselSlides.appendChild(slide);

            const dot = document.createElement("div");
            dot.className = "carousel-dot" + (index === 0 ? " active" : "");
            dot.onclick = () => goToSlide(index);
            carouselDots.appendChild(dot);
        });
        totalSlides = carData.afbeeldingen.length;
        currentSlide = 0;
        updateCarousel();
    }

    // Create detailed content
    details.innerHTML = `
        <div class="modal-sections">
            <div class="modal-section">
                <h3>${carData.merk} ${carData.model}</h3>
                <div class="price-tag">€ ${Number(carData.prijs).toLocaleString("nl-NL")}</div>
            </div>
            <div class="modal-section">
                <h4>Belangrijke Specificaties</h4>
                <div class="specs-grid">
                    <div class="spec-item"><span class="spec-label">Bouwjaar</span><span class="spec-value">${carData.bouwjaar || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Kilometerstand</span><span class="spec-value">${carData.kilometerstand || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Brandstof</span><span class="spec-value">${carData.brandstof || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Transmissie</span><span class="spec-value">${carData.transmissie || "N/A"}</span></div>
                </div>
            </div>
            <div class="modal-section">
                <h4>Technische Details</h4>
                <div class="specs-grid">
                    <div class="spec-item"><span class="spec-label">Vermogen</span><span class="spec-value">${carData.vermogen || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Cilinder Inhoud</span><span class="spec-value">${carData.cilinder_inhoud || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Aantal Cilinders</span><span class="spec-value">${carData.cilinders || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Gewicht</span><span class="spec-value">${carData.gewicht || "N/A"}</span></div>
                </div>
            </div>
            <div class="modal-section">
                <h4>Voertuig Kenmerken</h4>
                <div class="specs-grid">
                    <div class="spec-item"><span class="spec-label">Carrosserie</span><span class="spec-value">${carData.carrosserie || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Aantal Deuren</span><span class="spec-value">${carData.deuren || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Aantal Zitplaatsen</span><span class="spec-value">${carData.aantal_zitplaatsen || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Kleur</span><span class="spec-value">${carData.kleur || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Interieur Kleur</span><span class="spec-value">${carData.interieurkleur || "N/A"}</span></div>
                    <div class="spec-item"><span class="spec-label">Bekleding</span><span class="spec-value">${carData.bekleding || "N/A"}</span></div>
                </div>
            </div>
            ${carData.opmerkingen ? `
                <div class="modal-section">
                    <h4>Beschrijving</h4>
                    <div class="description-content">
                        ${carData.opmerkingen}
                    </div>
                </div>
            ` : ""}
            <!-- Delivery Packages -->
            <div class="delivery-packages">
                <h4>Afleverpakketten</h4>
                <div class="packages-grid">
                    <div class="package-card basic">
                        <div class="package-header">
                            <h5 class="package-title">Basis Pakket</h5>
                            <div class="package-price">€ 0,-</div>
                        </div>
                        <div class="package-features">
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">APK (minimaal 6 maanden)</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">Technische controlebeurt</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">Reinigen interieur & exterieur</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">Tenaamstellen nieuwe auto</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">Vrijwaren inruilauto</span>
                            </div>
                        </div>
                        <button class="package-select-btn" onclick="selectPackage(1, '${carData.merk} ${carData.model}')">
                            Selecteer Pakket
                        </button>
                    </div>

                    <div class="package-card premium">
                        <div class="package-header">
                            <h5 class="package-title">Premium Pakket</h5>
                            <div class="package-price">€ 795,-</div>
                        </div>
                        <div class="package-features">
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">APK (minimaal 6 maanden)</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">Onderhoudsbeurt conform fabrieksopgave</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">12 Maanden BOVAG garantie</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">Reinigen interieur & exterieur</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">Tenaamstellen nieuwe auto</span>
                            </div>
                            <div class="package-feature">
                                <span class="feature-icon">✓</span>
                                <span class="feature-text">Vrijwaren inruilauto</span>
                            </div>
                        </div>
                        <button class="package-select-btn" onclick="selectPackage(2, '${carData.merk} ${carData.model}')">
                            Selecteer Pakket
                        </button>
                    </div>
                </div>
            </div>
        </div>`;

    modal.style.display = "block";
}


function updateCarousel() {
    const slides = document.querySelector(".carousel-slides");
    if (!slides || totalSlides === 0) return;

    slides.style.transform = "translateX(-" + (currentSlide * 100) + "%)";
    const dots = document.querySelectorAll(".carousel-dot");
    dots.forEach((dot, index) => {
        dot.classList.toggle("active", index === currentSlide);
    });
}

function nextSlide() {
    if (totalSlides > 0) {
        currentSlide = (currentSlide + 1) % totalSlides;
        updateCarousel();
    }
}

function prevSlide() {
    if (totalSlides > 0) {
        currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
        updateCarousel();
    }
}

function goToSlide(index) {
    if (index >= 0 && index < totalSlides) {
        currentSlide = index;
        updateCarousel();
    }
}

function closeModal() {
    const modal = document.getElementById("carModal");
    modal.style.display = "none";
}

function selectPackage(packageNumber, carName) {
    const packageNames = {
        1: "Basis Pakket",
        2: "Comfort Pakket",
        3: "Premium Pakket"
    };
    const packagePrices = {
        1: "€ 0,-",
        2: "€ 495,-",
        3: "€ 795,-"
    };

    if (confirm("Wilt u het " + packageNames[packageNumber] + " selecteren voor " + carName + " voor " + packagePrices[packageNumber] + "?")) {
        alert("Bedankt voor het selecteren van het " + packageNames[packageNumber] + " voor " + carName + ". Ons team neemt contact met u op voor de volgende stappen.");
    }
}

// Event Listeners
document.addEventListener("DOMContentLoaded", function() {
    const carCards = document.querySelectorAll(".car-card");
    const carsGrid = document.getElementById("carsGrid");
    const resetFilters = document.getElementById("resetFilters");
    const sortSelect = document.getElementById("sortSelect");
    const showSelect = document.getElementById("showSelect");

    // Handle filter events
    const filterElements = [
        "brandFilter", "modelFilter", "fuelFilter",
        "transmissionFilter", "bodyFilter", "doorsFilter",
        "seatsFilter", "priceMin", "priceMax",
        "kmMin", "kmMax", "powerMin", "powerMax"
    ];

    filterElements.forEach(id => {
        document.getElementById(id).addEventListener("input", filterCars);
    });

    document.querySelectorAll("input[name='year']").forEach(cb => {
        cb.addEventListener("change", filterCars);
    });

    document.querySelectorAll("input[name='status']").forEach(cb => {
        cb.addEventListener("change", filterCars);
    });

    resetFilters.addEventListener("click", resetAllFilters);
    sortSelect.addEventListener("change", sortCars);
    showSelect.addEventListener("change", showSelectedCars);
});

// Close modal on click outside
window.addEventListener("click", function(event) {
    const modal = document.getElementById("carModal");
    if (event.target === modal) {
        closeModal();
    }
});

// Close modal on Escape key
window.addEventListener("keydown", function(event) {
    if (event.key === "Escape") {
        closeModal();
    }
});

function filterCars() {
    const carCards = document.querySelectorAll(".car-card");
    carCards.forEach(card => {
        try {
            const carData = JSON.parse(card.dataset.car);
            const shouldShow = checkFilters(carData);
            card.style.display = shouldShow ? "block" : "none";
        } catch (e) {
            console.error("Error filtering car:", e);
            card.style.display = "none";
        }
    });
    updateResultsCount();
}

function checkFilters(carData) {
    const brandFilter = document.getElementById("brandFilter").value;
    const modelFilter = document.getElementById("modelFilter").value;
    const fuelFilter = document.getElementById("fuelFilter").value;
    const transmissionFilter = document.getElementById("transmissionFilter").value;
    const bodyFilter = document.getElementById("bodyFilter").value;
    const doorsFilter = document.getElementById("doorsFilter").value;
    const seatsFilter = document.getElementById("seatsFilter").value;

    return (!brandFilter || carData.merk === brandFilter) &&
           (!modelFilter || carData.model === modelFilter) &&
           (!fuelFilter || carData.brandstof === fuelFilter) &&
           (!transmissionFilter || carData.transmissie === transmissionFilter) &&
           (!bodyFilter || carData.carrosserie === bodyFilter) &&
           (!doorsFilter || carData.deuren === doorsFilter) &&
           (!seatsFilter || carData.aantal_zitplaatsen === seatsFilter);
}

function updateModelOptions() {
    const brandFilter = document.getElementById("brandFilter");
    const modelFilter = document.getElementById("modelFilter");
    const selectedBrand = brandFilter.value;

    // Clear current options
    modelFilter.innerHTML = '<option value="">Alle Modellen</option>';

    if (selectedBrand) {
        const carCards = document.querySelectorAll(".car-card");
        const models = new Set();

        carCards.forEach(card => {
            const carData = JSON.parse(card.dataset.car);
            if (carData.merk === selectedBrand) {
                models.add(carData.model);
            }
        });

        [...models].sort().forEach(model => {
            const option = document.createElement("option");
            option.value = model;
            option.textContent = model;
            modelFilter.appendChild(option);
        });
    }
}
