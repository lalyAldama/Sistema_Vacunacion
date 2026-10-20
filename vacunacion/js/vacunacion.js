const vacuna = document.getElementById("vacuna");
const dosis = document.getElementById("dosis");
const lote = document.getElementById("lote");


// =========================================================
// CUANDO SE SELECCIONA UNA VACUNA
// =========================================================

vacuna.addEventListener("change", function() {

    const idBiologico = this.value;


    // Reiniciar dosis

    dosis.innerHTML = "";

    lote.innerHTML = "";


    // Si no seleccionó vacuna

    if(idBiologico === "") {

        dosis.disabled = true;
        lote.disabled = true;

        dosis.innerHTML = `
            <option value="">
                PRIMERO SELECCIONE UNA VACUNA
            </option>
        `;

        lote.innerHTML = `
            <option value="">
                PRIMERO SELECCIONE UNA VACUNA
            </option>
        `;

        return;
    }


    // =====================================================
    // CARGAR DOSIS DE ESA VACUNA
    // =====================================================

    dosis.disabled = false;

    const opcionInicialDosis =
        document.createElement("option");

    opcionInicialDosis.value = "";

    opcionInicialDosis.textContent =
        "SELECCIONE UNA DOSIS";

    dosis.appendChild(opcionInicialDosis);


    datosDosis.forEach(function(item) {

        if(
            String(item.id_biologico) ===
            String(idBiologico)
        ) {

            const opcion =
                document.createElement("option");

            opcion.value =
                item.id_esquema_biologico_dosis;

            opcion.textContent =
                item.nombre_dosis;

            dosis.appendChild(opcion);
        }

    });


    // =====================================================
    // CARGAR LOTES DE ESA VACUNA
    // =====================================================

    lote.disabled = false;

    const opcionInicialLote =
        document.createElement("option");

    opcionInicialLote.value = "";

    opcionInicialLote.textContent =
        "SELECCIONE UN LOTE";

    lote.appendChild(opcionInicialLote);


    datosLotes.forEach(function(item) {

        if(
            String(item.id_biologico) ===
            String(idBiologico)
        ) {

            const opcion =
                document.createElement("option");

            opcion.value =
                item.id_lote;

            opcion.textContent =
                item.nombre_biologico +
                " - " +
                item.nombre_presentacion +
                " - LOTE: " +
                item.numero_lote +
                " - CAD: " +
                item.fecha_caducidad;

            lote.appendChild(opcion);
        }

    });

});