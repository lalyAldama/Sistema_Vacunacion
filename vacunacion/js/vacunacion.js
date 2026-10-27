const vacuna = document.getElementById("vacuna");
const dosis = document.getElementById("dosis");
const lote = document.getElementById("lote");

function cargarLotes(idBiologico) {
    lote.innerHTML = "";

    const opcionInicial = document.createElement("option");
    opcionInicial.value = "";
    lote.appendChild(opcionInicial);

    if (idBiologico === "") {
        opcionInicial.textContent = "PRIMERO SELECCIONE UNA VACUNA";
        lote.disabled = true;
        return;
    }

    const presentaciones = presentacionesPorBiologico[String(idBiologico)] || {};
    const tieneAmbasPresentaciones =
        Object.prototype.hasOwnProperty.call(presentaciones, "pediatrica") &&
        Object.prototype.hasOwnProperty.call(presentaciones, "adulta");
    let categoriaEsperada = null;
    if (tieneAmbasPresentaciones) {
        if (edadPacienteEnDias === null) {
            opcionInicial.textContent =
                "NO SE PUEDE DETERMINAR LA EDAD PARA FILTRAR LAS PRESENTACIONES";
            lote.disabled = true;
            return;
        }
        categoriaEsperada = edadPacienteEnDias <= 7 ? "pediatrica" : "adulta";
    }

    opcionInicial.textContent = "SELECCIONE UN LOTE";
    lote.disabled = false;

    let hayLotesDisponibles = false;
    datosLotes.forEach(function(item) {
        if (
            String(item.id_biologico) !== String(idBiologico) ||
            (categoriaEsperada !== null &&
                item.categoria_presentacion !== categoriaEsperada)
        ) {
            return;
        }

        const opcion = document.createElement("option");
        opcion.value = item.id_lote;
        opcion.textContent =
            item.nombre_biologico +
            " - " +
            item.nombre_presentacion +
            " - LOTE: " +
            item.numero_lote +
            " - CAD: " +
            item.fecha_caducidad;
        lote.appendChild(opcion);
        hayLotesDisponibles = true;
    });

    if (!hayLotesDisponibles) {
        opcionInicial.textContent = tieneAmbasPresentaciones
            ? "NO HAY LOTES DISPONIBLES PARA LA PRESENTACIÓN REQUERIDA"
            : "NO HAY LOTES DISPONIBLES PARA ESTA VACUNA";
    }
}

vacuna.addEventListener("change", function() {
    const idBiologico = this.value;
    dosis.innerHTML = "";

    if (idBiologico === "") {
        dosis.disabled = true;
        dosis.innerHTML = `
            <option value="">
                PRIMERO SELECCIONE UNA VACUNA
            </option>
        `;
        cargarLotes("");
        return;
    }

    dosis.disabled = false;
    const opcionInicialDosis = document.createElement("option");
    opcionInicialDosis.value = "";
    opcionInicialDosis.textContent = "SELECCIONE UNA DOSIS";
    dosis.appendChild(opcionInicialDosis);

    datosDosis.forEach(function(item) {
        if (String(item.id_biologico) === String(idBiologico)) {
            const opcion = document.createElement("option");
            opcion.value = item.id_esquema_biologico_dosis;
            opcion.textContent = item.nombre_dosis;
            dosis.appendChild(opcion);
        }
    });

    cargarLotes(idBiologico);
});
