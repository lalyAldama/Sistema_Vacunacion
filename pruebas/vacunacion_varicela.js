const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const elements = new Map();
function element(id) {
    if (!elements.has(id)) {
        elements.set(id, {
            value: "", hidden: false, disabled: false, required: false, checked: false,
            handlers: {},
            addEventListener(event, handler) {
                (this.handlers[event] ||= []).push(handler);
            },
            dispatch(event) {
                for (const handler of this.handlers[event] || []) handler.call(this);
            },
            appendChild() {}, replaceChildren(...options) { this.options = options; }
        });
    }
    return elements.get(id);
}

const context = {
    document: { getElementById: element, createElement: () => ({}) },
    Option: function(text, value) { this.text = text; this.value = value; },
    idBiologicoSrp: null, idBiologicoVph: 3, idBiologicoHepatitisB: 4,
    idBiologicoSr: 5, idBiologicoVaricela: 1, idBiologicoHepatitisA: 2,
    idBiologicoDpt: null, fechaNacimientoPaciente: "2020-05-15",
    rangoDosisCero: { inicio: "", fin: "" }, personalSaludPaciente: false,
    biologicosConPreguntaReemplazo: [], datosDosis: [], datosLotes: [],
    presentacionesPorBiologico: {}, reemplazosPorBiologico: {}, biologicosReemplazo: []
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, "..", "vacunacion", "js", "vacunacion.js"), "utf8"), context);

const vacuna = element("vacuna");
const fecha = element("fecha_aplicacion");
const casilla = element("rezago-varicela");
const seccion = element("seccion-rezago-varicela");
const hepatitis = element("clasificacion-varicela-hepatitis-a");

vacuna.value = "1";
fecha.value = "2022-05-14";
vacuna.dispatch("change");
assert.equal(seccion.hidden, true);
assert.equal(hepatitis.disabled, true);

fecha.value = "2022-05-15";
fecha.dispatch("change");
assert.equal(seccion.hidden, false);
assert.equal(casilla.disabled, false);
assert.equal(casilla.required, true);
casilla.checked = true;
fecha.value = "2025-05-14";
fecha.dispatch("change");
assert.equal(casilla.checked, true);
fecha.value = "2025-05-15";
fecha.dispatch("change");
assert.equal(seccion.hidden, true);
assert.equal(casilla.checked, false);
assert.equal(casilla.required, false);

vacuna.value = "2";
vacuna.dispatch("change");
assert.equal(hepatitis.required, false);
assert.deepEqual(hepatitis.options.map(option => option.value), ["", "rezago", "riesgo"]);
assert.equal(seccion.hidden, true);
console.log("Pruebas de formulario de varicela correctas.");
