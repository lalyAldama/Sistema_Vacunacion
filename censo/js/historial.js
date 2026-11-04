(function () {
    var body = document.body;
    var tipo = body.getAttribute("data-historial-tipo");
    if (!tipo) return;

    var idCenso = body.getAttribute("data-historial-censo");
    var token = body.getAttribute("data-historial-token");
    var inicioFormulario = body.getAttribute("data-historial-form-inicio");
    var nonceFormulario = body.getAttribute("data-historial-form-nonce");
    var pruebaFormulario = body.getAttribute("data-historial-form-prueba");

    function convertirImagenes(clon) {
        var imagenes = Array.from(clon.querySelectorAll("img[src]"));
        return Promise.all(imagenes.map(function (imagen, indice) {
            var original = document.querySelectorAll("img[src]")[indice];
            var url = original ? original.src : "";
            if (!url || url.startsWith("data:")) return Promise.resolve();

            return fetch(url, { credentials: "same-origin" })
                .then(function (respuesta) {
                    if (!respuesta.ok) throw new Error("No se pudo conservar una imagen del censo.");
                    return respuesta.blob();
                })
                .then(function (blob) {
                    return new Promise(function (resolve, reject) {
                        var lector = new FileReader();
                        lector.onload = function () {
                            imagen.setAttribute("src", lector.result);
                            resolve();
                        };
                        lector.onerror = function () {
                            reject(new Error("No se pudo convertir una imagen del censo."));
                        };
                        lector.readAsDataURL(blob);
                    });
                });
        }));
    }

    function convertirEstilos(clon) {
        var enlaces = Array.from(clon.querySelectorAll('link[rel~="stylesheet"]'));
        return Promise.all(enlaces.map(function (enlace, indice) {
            var original = document.querySelectorAll('link[rel~="stylesheet"]')[indice];
            if (!original || new URL(original.href).origin !== window.location.origin) {
                return Promise.resolve();
            }

            return fetch(original.href, { credentials: "same-origin" })
                .then(function (respuesta) {
                    if (!respuesta.ok) throw new Error("No se pudo conservar el formato CSS del censo.");
                    return respuesta.text();
                })
                .then(function (css) {
                    var estilo = document.createElement("style");
                    estilo.textContent = css;
                    enlace.replaceWith(estilo);
                });
        }));
    }

    function guardarCopia() {
        var clon = document.documentElement.cloneNode(true);
        clon.querySelectorAll(".consulta-historica").forEach(function (seccion) {
            seccion.remove();
        });
        clon.querySelectorAll("a[href]").forEach(function (enlace) {
            enlace.removeAttribute("href");
            enlace.setAttribute("aria-disabled", "true");
            enlace.setAttribute("tabindex", "-1");
        });
        clon.querySelectorAll("[contenteditable]").forEach(function (elemento) {
            elemento.removeAttribute("contenteditable");
        });
        var cuerpoClonado = clon.querySelector("body");
        if (cuerpoClonado) {
            [
                "data-historial-tipo",
                "data-historial-censo",
                "data-historial-token",
                "data-historial-form-inicio",
                "data-historial-form-nonce",
                "data-historial-form-prueba",
                "data-capturar-influenza",
                "data-volver-vacunacion"
            ].forEach(function (atributo) {
                cuerpoClonado.removeAttribute(atributo);
            });
        }

        return Promise.all([
            convertirImagenes(clon),
            convertirEstilos(clon)
        ]).then(function () {
            clon.querySelectorAll("script").forEach(function (script) {
                script.remove();
            });

            var datos = new FormData();
            datos.append("tipo", tipo);
            datos.append("id_censo", idCenso);
            datos.append("token", token);
            datos.append("_human_check", "");
            datos.append("_form_started_at", inicioFormulario);
            datos.append("_form_proof", nonceFormulario + "." + pruebaFormulario);
            datos.append("contenido", "<!DOCTYPE html>\n" + clon.outerHTML);

            return fetch("guardar_historial.php", {
                method: "POST",
                credentials: "same-origin",
                body: datos
            });
        }).then(function (respuesta) {
            if (!respuesta.ok) throw new Error("No se pudo guardar la copia histórica del censo.");
            return respuesta.json();
        }).then(function (resultado) {
            if (!resultado.success) throw new Error(resultado.error || "No se pudo guardar la copia histórica del censo.");
            if (window.parent !== window) {
                window.parent.postMessage({ type: "censo-historial-guardado" }, window.location.origin);
            }
        });
    }

    function capturarInfluenza() {
        return capturarUrl("influenza.php?capturar_historial=1");
    }

    function capturarCensoAnterior(idAnterior) {
        if (!idAnterior || Number(idAnterior) === Number(idCenso)) {
            return Promise.resolve();
        }

        return capturarUrl(
            "index.php?censo=" + encodeURIComponent(idAnterior)
        );
    }

    function capturarUrl(url) {
        return new Promise(function (resolve, reject) {
            var marco = document.createElement("iframe");
            marco.hidden = true;
            var temporizador = window.setTimeout(function () {
                window.removeEventListener("message", recibirCopia);
                marco.remove();
                reject(new Error("No se pudo completar la copia del otro censo."));
            }, 30000);

            function recibirCopia(evento) {
                if (
                    evento.origin !== window.location.origin
                    || evento.source !== marco.contentWindow
                    || !evento.data
                    || evento.data.type !== "censo-historial-guardado"
                ) return;

                window.clearTimeout(temporizador);
                window.removeEventListener("message", recibirCopia);
                marco.remove();
                resolve();
            }

            window.addEventListener("message", recibirCopia);
            marco.src = url;
            document.body.appendChild(marco);
        });
    }

    guardarCopia().then(function () {
        if (body.getAttribute("data-capturar-censo-anterior")) {
            return capturarCensoAnterior(body.getAttribute("data-capturar-censo-anterior"));
        }
        if (body.getAttribute("data-capturar-influenza") === "1") {
            return capturarInfluenza();
        }
    }).then(function () {
        if (body.getAttribute("data-volver-vacunacion") === "1") {
            window.location.href = "../vacunacion/index.php";
        }
    }).catch(function (error) {
        console.error(error);
        var aviso = document.createElement("div");
        aviso.className = "panel-resultados sin-resultados";
        aviso.setAttribute("role", "alert");
        aviso.textContent = "No se pudo guardar la copia histórica: " + error.message;
        var pagina = document.querySelector(".pagina");
        if (pagina) pagina.prepend(aviso);
    });
})();
