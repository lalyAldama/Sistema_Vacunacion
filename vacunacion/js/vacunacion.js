const vacuna = document.getElementById("vacuna");
const dosis = document.getElementById("dosis");
const lote = document.getElementById("lote");
const etiquetaLote = document.getElementById("etiqueta-lote");
const reemplazo = document.getElementById("reemplazo");
const datosReemplazo = document.getElementById("datos-reemplazo");
const biologicoReemplazo = document.getElementById("biologico-reemplazo");
const motivoReemplazo = document.getElementById("motivo-reemplazo");

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

function idBiologicoDelLote() {
    return reemplazo.value === "1"
        ? biologicoReemplazo.value
        : vacuna.value;
}

function actualizarLotes() {
    cargarLotes(idBiologicoDelLote());
}

function cargarBiologicosReemplazo() {
    biologicoReemplazo.innerHTML = "";

    const opcionInicial = document.createElement("option");
    opcionInicial.value = "";
    biologicoReemplazo.appendChild(opcionInicial);

    if (vacuna.value === "") {
        opcionInicial.textContent = "PRIMERO SELECCIONE LA VACUNA DEL ESQUEMA";
        biologicoReemplazo.disabled = true;
        return;
    }

    const idsReemplazo = reemplazosPorBiologico[String(vacuna.value)] || [];
    if (idsReemplazo.length === 0) {
        opcionInicial.textContent =
            "NO HAY UN BIOLÓGICO DE REEMPLAZO CONFIGURADO PARA ESTA VACUNA";
        biologicoReemplazo.disabled = true;
        return;
    }

    opcionInicial.textContent = "SELECCIONE EL BIOLÓGICO DE REEMPLAZO";
    biologicoReemplazo.disabled = false;

    idsReemplazo.forEach(function(idBiologico) {
        const item = biologicosReemplazo.find(function(biologico) {
            return String(biologico.id_biologico) === String(idBiologico);
        });
        if (!item) {
            return;
        }

        const opcion = document.createElement("option");
        opcion.value = item.id_biologico;
        opcion.textContent = item.nombre_biologico;
        biologicoReemplazo.appendChild(opcion);
    });
}

function actualizarModoReemplazo() {
    const estaReemplazando = reemplazo.value === "1";
    datosReemplazo.hidden = !estaReemplazando;
    etiquetaLote.textContent = estaReemplazando
        ? "LOTE DEL BIOLÓGICO DE REEMPLAZO:"
        : "LOTE DE LA VACUNA DEL ESQUEMA:";
    biologicoReemplazo.required = estaReemplazando;
    motivoReemplazo.required = estaReemplazando;
    biologicoReemplazo.disabled = !estaReemplazando;
    motivoReemplazo.disabled = !estaReemplazando;

    if (estaReemplazando) {
        cargarBiologicosReemplazo();
    } else {
        biologicoReemplazo.value = "";
    }
    actualizarLotes();
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
        cargarBiologicosReemplazo();
        actualizarLotes();
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

    if (reemplazo.value === "1") {
        cargarBiologicosReemplazo();
    }
    actualizarLotes();
});

reemplazo.addEventListener("change", actualizarModoReemplazo);
biologicoReemplazo.addEventListener("change", actualizarLotes);
