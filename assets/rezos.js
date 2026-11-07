/* Rezos — pagination du flux : boutons « Publications suivantes / précédentes ». */
(function () {
    'use strict';

    function init(root) {
        var pages = root.querySelectorAll('.rezos__list[data-rezos-page]');
        var pager = root.querySelector('.rezos__pager');
        if (!pager || pages.length < 2 || pager.dataset.rezosReady) {
            return;
        }
        pager.dataset.rezosReady = '1';

        var prev = pager.querySelector('[data-rezos-step="-1"]');
        var next = pager.querySelector('[data-rezos-step="1"]');
        var status = pager.querySelector('.rezos__pager-status');
        var current = 0;

        function show(index, scroll) {
            var hadFocus = pager.contains(document.activeElement);
            current = Math.max(0, Math.min(pages.length - 1, index));
            for (var i = 0; i < pages.length; i++) {
                pages[i].hidden = i !== current;
            }
            prev.hidden = current === 0;
            next.hidden = current === pages.length - 1;
            status.textContent = (current + 1) + ' / ' + pages.length;

            // Le bouton cliqué vient peut-être de disparaître : on garde le focus dans la navigation.
            if (hadFocus && !pager.contains(document.activeElement)) {
                (next.hidden ? prev : next).focus();
            }
            // Haut du flux sorti de l'écran (flux long) : on y revient.
            if (scroll && root.getBoundingClientRect().top < 0) {
                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        pager.addEventListener('click', function (event) {
            var button = event.target.closest('[data-rezos-step]');
            if (button) {
                show(current + Number(button.dataset.rezosStep), true);
            }
        });

        pager.hidden = false;
        show(0, false);
    }

    function initAll() {
        var feeds = document.querySelectorAll('.rezos');
        for (var i = 0; i < feeds.length; i++) {
            init(feeds[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
