// At top of file, before jQuery ready
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

jQuery(document).ready(function($) {
    // Image preloading
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

    // Share functionality
    window.shareCar = function() {
        if (navigator.share) {
            navigator.share({
                title: document.querySelector('.occasion-title').textContent,
                url: window.location.href
            }).catch(console.error);
        } else {
            // Fallback for browsers that don't support Web Share API
            const dummy = document.createElement('input');
            document.body.appendChild(dummy);
            dummy.value = window.location.href;
            dummy.select();
            document.execCommand('copy');
            document.body.removeChild(dummy);
            alert('Link gekopieerd naar klembord!');
        }
    };
});