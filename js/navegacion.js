(function () {
    var script = document.currentScript;
    var menuUrl = script ? script.getAttribute("data-menu-url") : "";

    if (!menuUrl) {
        return;
    }

    var volverAlMenu = function () {
        window.location.replace(menuUrl);
    };

    window.addEventListener("popstate", volverAlMenu);
    window.addEventListener("pageshow", function (event) {
        var navigationEntries = window.performance
            && typeof window.performance.getEntriesByType === "function"
            ? window.performance.getEntriesByType("navigation")
            : [];
        var navigation = navigationEntries.length ? navigationEntries[0] : null;

        if (event.persisted || (navigation && navigation.type === "back_forward")) {
            volverAlMenu();
        }
    });
})();
