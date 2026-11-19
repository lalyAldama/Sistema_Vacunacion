const vacuna = document.getElementById("vacuna");
const dosis = document.getElementById("dosis");
const lote = document.getElementById("lote");
const etiquetaLote = document.getElementById("etiqueta-lote");
const seccionReemplazo = document.getElementById("seccion-reemplazo");
const reemplazo = document.getElementById("reemplazo");
const datosReemplazo = document.getElementById("datos-reemplazo");
const biologicoReemplazo = document.getElementById("biologico-reemplazo");
const motivoReemplazo = document.getElementById("motivo-reemplazo");
const seccionDosisCero = document.getElementById("seccion-dosis-cero");
const dosisCero = document.getElementById("dosis_cero");
const seccionClasificacionVph = document.getElementById("seccion-clasificacion-vph");
const clasificacionVph = document.getElementById("clasificacion-vph");
const seccionClasificacionHepatitisB = document.getElementById("seccion-clasificacion-hepatitis-b");
const clasificacionHepatitisB = document.getElementById("clasificacion-hepatitis-b");
const seccionClasificacionSr = document.getElementById("seccion-clasificacion-sr");
const clasificacionSr = document.getElementById("clasificacion-sr");
const seccionClasificacionVaricelaHepatitisA = document.getElementById("seccion-clasificacion-varicela-hepatitis-a");
const clasificacionVaricelaHepatitisA = document.getElementById("clasificacion-varicela-hepatitis-a");
const seccionRezagoVaricela = document.getElementById("seccion-rezago-varicela");
const rezagoVaricela = document.getElementById("rezago-varicela");
const seccionClasificacionDpt = document.getElementById("seccion-clasificacion-dpt");
const sinAntecedenteDpt = document.getElementById("sin-antecedente-dpt");
const fechaAplicacion = document.getElementById("fecha_aplicacion");

function actualizarDosisCero() {
    const corresponde = idBiologicoSrp !== null
        && String(vacuna.value) === String(idBiologicoSrp)
        && fechaAplicacion.value !== ""
        && fechaAplicacion.value >= rangoDosisCero.inicio
        && fechaAplicacion.value <= rangoDosisCero.fin;
    seccionDosisCero.hidden = !corresponde;
    dosisCero.disabled = !corresponde;
    dosisCero.required = corresponde;
    if (!corresponde) {
        dosisCero.value = "";
    }
}

function actualizarClasificacionVph() {
    const esVph = String(vacuna.value) === String(idBiologicoVph);
    seccionClasificacionVph.hidden = !esVph;
    clasificacionVph.disabled = !esVph;
    clasificacionVph.required = esVph;
    if (!esVph) {
        clasificacionVph.value = "";
    }
}

function actualizarClasificacionHepatitisB() {
    const esHepatitisB = String(vacuna.value) === String(idBiologicoHepatitisB);
    const dosisSeleccionada = datosDosis.find(function(item) {
        return String(item.id_esquema_biologico_dosis) === String(dosis.value);
    });
    const nombreDosis = dosisSeleccionada
        ? dosisSeleccionada.nombre_dosis.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "")
        : "";
    const esPrimeraSegunda = /(^|\D)(1|2)(\D|$)|primera|segunda/.test(nombreDosis);
    const nacimientoMs = Date.parse(fechaNacimientoPaciente + "T00:00:00Z");
    const aplicacionMs = Date.parse(fechaAplicacion.value + "T00:00:00Z");
    const edadEnDiasAlAplicar = Number.isFinite(nacimientoMs) && Number.isFinite(aplicacionMs)
        ? Math.floor((aplicacionMs - nacimientoMs) / 86400000)
        : null;
    const esUnicaNeonatal = edadEnDiasAlAplicar !== null
        && edadEnDiasAlAplicar >= 0
        && edadEnDiasAlAplicar <= 7
        && nombreDosis.includes("unica");
    const pedirClasificacion = esHepatitisB
        && esPrimeraSegunda
        && !personalSaludPaciente
        && !esUnicaNeonatal;

    seccionClasificacionHepatitisB.hidden = !pedirClasificacion;
    clasificacionHepatitisB.disabled = !pedirClasificacion;
    clasificacionHepatitisB.required = pedirClasificacion;
    if (!pedirClasificacion) {
        clasificacionHepatitisB.value = "";
    }
}

function actualizarClasificacionSr() {
    const esSr = idBiologicoSr !== null
        && String(vacuna.value) === String(idBiologicoSr);
    seccionClasificacionSr.hidden = !esSr;
    clasificacionSr.disabled = !esSr;
    clasificacionSr.required = esSr;
    if (!esSr) {
        clasificacionSr.value = "";
    }
}

function actualizarClasificacionVaricelaHepatitisA() {
    const idSeleccionado = String(vacuna.value);
    const esHepatitisA = idBiologicoHepatitisA !== null
        && idSeleccionado === String(idBiologicoHepatitisA);
    seccionClasificacionVaricelaHepatitisA.hidden = !esHepatitisA;
    clasificacionVaricelaHepatitisA.disabled = !esHepatitisA;
    clasificacionVaricelaHepatitisA.required = false;
    clasificacionVaricelaHepatitisA.replaceChildren(
        new Option("NINGUNA (AUTOMÁTICA A LOS 18 MESES)", ""),
        ...(esHepatitisA
            ? [
                new Option("Rezagos 2 a 4 años", "rezago"),
                new Option("Población en riesgo", "riesgo")
            ]
            : [])
    );
    clasificacionVaricelaHepatitisA.value = "";
}

function edadEnAniosAlAplicar() {
    const nacimientoMs = Date.parse(fechaNacimientoPaciente + "T00:00:00Z");
    const aplicacionMs = Date.parse(fechaAplicacion.value + "T00:00:00Z");
    if (!Number.isFinite(nacimientoMs) || !Number.isFinite(aplicacionMs) || aplicacionMs < nacimientoMs) {
        return null;
    }

    const nacimiento = new Date(nacimientoMs);
    const aplicacion = new Date(aplicacionMs);
    let edad = aplicacion.getUTCFullYear() - nacimiento.getUTCFullYear();
    if (
        aplicacion.getUTCMonth() < nacimiento.getUTCMonth()
        || (
            aplicacion.getUTCMonth() === nacimiento.getUTCMonth()
            && aplicacion.getUTCDate() < nacimiento.getUTCDate()
        )
    ) {
        edad--;
    }
    return edad;
}

function actualizarRezagoVaricela() {
    const edad = edadEnAniosAlAplicar();
    const corresponde = idBiologicoVaricela !== null
        && String(vacuna.value) === String(idBiologicoVaricela)
        && edad !== null
        && edad >= 2
        && edad <= 4;
    seccionRezagoVaricela.hidden = !corresponde;
    rezagoVaricela.disabled = !corresponde;
    rezagoVaricela.required = corresponde;
    if (!corresponde) {
        rezagoVaricela.checked = false;
    }
}

function actualizarClasificacionDpt() {
    const dosisSeleccionada = datosDosis.find(function(item) {
        return String(item.id_esquema_biologico_dosis) === String(dosis.value);
    });
    const nombreDosis = dosisSeleccionada
        ? dosisSeleccionada.nombre_dosis.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "")
        : "";
    const edad = edadEnAniosAlAplicar();
    const corresponde = idBiologicoDpt !== null
        && String(vacuna.value) === String(idBiologicoDpt)
        && nombreDosis.includes("unica")
        && edad !== null
        && edad >= 5
        && edad <= 6;

    seccionClasificacionDpt.hidden = !corresponde;
    sinAntecedenteDpt.disabled = !corresponde;
    if (!corresponde) {
        sinAntecedenteDpt.checked = false;
    }
}

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
        motivoReemplazo.value = "";
    }
    actualizarLotes();
}

function actualizarPreguntaReemplazo() {
    const preguntar = biologicosConPreguntaReemplazo.some(function(id) {
        return String(id) === String(vacuna.value);
    });
    seccionReemplazo.hidden = !preguntar;
    reemplazo.disabled = !preguntar;
    reemplazo.required = preguntar;
    reemplazo.value = "";
    actualizarModoReemplazo();
}

vacuna.addEventListener("change", function() {
    const idBiologico = this.value;
    dosis.innerHTML = "";
    actualizarPreguntaReemplazo();
    actualizarDosisCero();

    if (idBiologico === "") {
        dosis.disabled = true;
        dosis.innerHTML = `
            <option value="">
                PRIMERO SELECCIONE UNA VACUNA
            </option>
        `;
        actualizarClasificacionVph();
        actualizarClasificacionHepatitisB();
        actualizarClasificacionSr();
        actualizarClasificacionVaricelaHepatitisA();
        actualizarRezagoVaricela();
        actualizarClasificacionDpt();
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

    actualizarClasificacionVph();
    actualizarClasificacionHepatitisB();
    actualizarClasificacionSr();
    actualizarClasificacionVaricelaHepatitisA();
    actualizarRezagoVaricela();
    actualizarClasificacionDpt();
});

reemplazo.addEventListener("change", actualizarModoReemplazo);
biologicoReemplazo.addEventListener("change", actualizarLotes);
dosis.addEventListener("change", actualizarClasificacionHepatitisB);
dosis.addEventListener("change", actualizarClasificacionDpt);
fechaAplicacion.addEventListener("change", actualizarClasificacionHepatitisB);
fechaAplicacion.addEventListener("change", actualizarDosisCero);
fechaAplicacion.addEventListener("change", actualizarRezagoVaricela);
fechaAplicacion.addEventListener("change", actualizarClasificacionDpt);
actualizarPreguntaReemplazo();
actualizarDosisCero();
actualizarClasificacionVph();
actualizarClasificacionHepatitisB();
actualizarClasificacionSr();
actualizarClasificacionVaricelaHepatitisA();
actualizarRezagoVaricela();
actualizarClasificacionDpt();
