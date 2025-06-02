jQuery(document).ready(function($) {
    // Initialize gallery functionality
    function initializeGallery() {
        const mainImage = document.getElementById('main-image');
        const thumbnails = document.querySelectorAll('.gallery-thumbnails .thumbnail img');

        if (mainImage && thumbnails.length > 0) {
            thumbnails.forEach(thumb => {
                thumb.addEventListener('click', function() {
                    mainImage.src = this.src;
                    thumbnails.forEach(t => t.parentElement.classList.remove('active'));
                    this.parentElement.classList.add('active');
                });
            });
        }
    }

    // Initialize contact form
    function initializeContactForm() {
        const contactButton = document.querySelector('.contact-button');
        if (contactButton) {
            contactButton.addEventListener('click', function() {
                const modal = document.createElement('div');
                modal.className = 'contact-modal';
                modal.innerHTML = `
                    <div class="modal-content">
                        <span class="close-button">&times;</span>
                        <h2>Contact opnemen</h2>
                        <form id="contact-form">
                            <div class="form-group">
                                <label for="name">Naam</label>
                                <input type="text" id="name" name="name" required>
                            </div>
                            <div class="form-group">
                                <label for="email">E-mail</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            <div class="form-group">
                                <label for="phone">Telefoon</label>
                                <input type="tel" id="phone" name="phone">
                            </div>
                            <div class="form-group">
                                <label for="message">Bericht</label>
                                <textarea id="message" name="message" required></textarea>
                            </div>
                            <button type="submit" class="submit-button">Versturen</button>
                        </form>
                    </div>
                `;

                document.body.appendChild(modal);
                modal.style.display = 'block';

                // Close modal functionality
                const closeButton = modal.querySelector('.close-button');
                closeButton.addEventListener('click', function() {
                    modal.remove();
                });

                // Close modal when clicking outside
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        modal.remove();
                    }
                });

                // Handle form submission
                const form = modal.querySelector('#contact-form');
                form.addEventListener('submit', handleContactFormSubmit);
            });
        }
    }

    // Handle contact form submission
    function handleContactFormSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const submitButton = form.querySelector('.submit-button');
        const originalText = submitButton.textContent;

        submitButton.textContent = vweData.i18n.loading;
        submitButton.disabled = true;

        const formData = new FormData(form);
        formData.append('action', 'vwe_handle_contact');
        formData.append('nonce', vweData.nonce);

        $.ajax({
            url: vweData.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert(vweData.i18n.success);
                    form.closest('.contact-modal').remove();
                } else {
                    alert(response.data.message || vweData.i18n.error);
                }
            },
            error: function() {
                alert(vweData.i18n.error);
            },
            complete: function() {
                submitButton.textContent = originalText;
                submitButton.disabled = false;
            }
        });
    }

    // Initialize share functionality
    function initializeShare() {
        const shareButton = document.querySelector('.share-button');
        if (shareButton) {
            shareButton.addEventListener('click', function() {
                if (navigator.share) {
                    navigator.share({
                        title: document.title,
                        text: 'Bekijk deze occasion: ' + document.title,
                        url: window.location.href
                    }).catch(error => console.log('Error sharing:', error));
                } else {
                    const dummy = document.createElement('input');
                    document.body.appendChild(dummy);
                    dummy.value = window.location.href;
                    dummy.select();
                    document.execCommand('copy');
                    document.body.removeChild(dummy);
                    alert(vweData.i18n.copied);
                }
            });
        }
    }

    // Initialize all functionality
    initializeGallery();
    initializeContactForm();
    initializeShare();
});