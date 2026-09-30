const fechaNacimiento = document.getElementById("fecha_nacimiento");
const edad = document.getElementById("edad");
const tipoCenso = document.getElementById("tipo_censo");


// =========================
// EDAD Y TIPO DE CENSO
// =========================

fechaNacimiento.addEventListener("change", function() {

    const fecha = new Date(this.value);
    const hoy = new Date();

    let años = hoy.getFullYear() - fecha.getFullYear();
    const mes = hoy.getMonth() - fecha.getMonth();

    if (
        mes < 0 ||
        (mes === 0 && hoy.getDate() < fecha.getDate())
    ) {
        años--;
    }

    edad.textContent = años + " años";

    if(años >= 0 && años <= 9){

        tipoCenso.textContent = "0-9 años";

    } else if(años >= 10 && años <= 19){

        tipoCenso.textContent = "10-19 años";

    } else if(años >= 20){

        tipoCenso.textContent = "Adultos y Embarazadas";

    } else {

        tipoCenso.textContent = "--";
    }
});


// =========================
// EMBARAZO
// =========================

const sexo = document.querySelector('select[name="sexo"]');
const campoEmbarazo = document.getElementById("campo_embarazo");
const embarazo = document.getElementById("embarazo");
const campoFum = document.getElementById("campo_fum");
const fechaFum = document.querySelector(
    'input[name="fecha_ultima_menstruacion"]'
);

function actualizarEmbarazo() {

    if (!fechaNacimiento.value || !sexo.value) {

        campoEmbarazo.style.display = "none";
        campoFum.style.display = "none";

        embarazo.required = false;
        fechaFum.required = false;

        embarazo.value = "";
        fechaFum.value = "";

        return;
    }

    const fecha = new Date(fechaNacimiento.value + "T00:00:00");
    const hoy = new Date();

    let años = hoy.getFullYear() - fecha.getFullYear();
    const mes = hoy.getMonth() - fecha.getMonth();

    if (
        mes < 0 ||
        (mes === 0 && hoy.getDate() < fecha.getDate())
    ) {
        años--;
    }

    if (sexo.value === "F" && años >= 10) {

        campoEmbarazo.style.display = "block";
        embarazo.required = true;

    } else {

        campoEmbarazo.style.display = "none";
        campoFum.style.display = "none";

        embarazo.required = false;
        fechaFum.required = false;

        embarazo.value = "";
        fechaFum.value = "";
    }
}

fechaNacimiento.addEventListener("change", actualizarEmbarazo);
sexo.addEventListener("change", actualizarEmbarazo);


// =========================
// FECHA ÚLTIMA MENSTRUACIÓN
// =========================

embarazo.addEventListener("change", function() {

    if(embarazo.value === "1") {

        campoFum.style.display = "block";
        fechaFum.required = true;

    } else {

        campoFum.style.display = "none";
        fechaFum.required = false;
        fechaFum.value = "";
    }
});


// =========================
// MIGRANTE
// =========================

const EsMigrante = document.getElementById("es_migrante");
const campoEstatusMigratorio =
    document.getElementById("campo_estatus_migratorio");
const estatusMigratorio =
    document.getElementById("estatus_migratorio");

EsMigrante.addEventListener("change", function() {

    if(EsMigrante.value === "1") {

        campoEstatusMigratorio.style.display = "block";
        estatusMigratorio.required = true;

    } else {

        campoEstatusMigratorio.style.display = "none";
        estatusMigratorio.required = false;
        estatusMigratorio.value = "";
    }
});


// =========================
// TUTOR
// =========================

const datosTutor = document.getElementById("datos_tutor");
const camposTutor = datosTutor.querySelectorAll("input, select");
const telefonoPaciente = document.getElementById("telefono_paciente");
const telefonoTutor = document.getElementById("tutor_telefono");

function actualizarTutor() {

    if(!fechaNacimiento.value) {

        datosTutor.style.display = "none";
        telefonoPaciente.required= false;
        telefonoTutor.required= false;

        camposTutor.forEach(function(campo) {
            campo.required = false;
            campo.disabled = true;
        });

        return;
    }

    const fecha = new Date(fechaNacimiento.value + "T00:00:00");
    const hoy = new Date();

    let años = hoy.getFullYear() - fecha.getFullYear();
    const mes = hoy.getMonth() - fecha.getMonth();

    if (
        mes < 0 ||
        (mes === 0 && hoy.getDate() < fecha.getDate())
    ) {
        años--;
    }


    // PACIENTE DE 0 A 9 AÑOS

    if(años >= 0 && años <= 9) {

        datosTutor.style.display = "block";
        telefonoPaciente.required= false;
        telefonoTutor.required= true;

        camposTutor.forEach(function(campo) {

            campo.disabled = false;

            if(campo.name === "tutor_curp") {

                campo.required = false;

            } else {

                campo.required = true;
            }
        });

    }

    // PACIENTE DE 10 AÑOS O MÁS

    else {

        datosTutor.style.display = "none";
        telefonoPaciente.required= true;
        telefonoTutor.required = false;

        camposTutor.forEach(function(campo) {

            campo.required = false;
            campo.disabled = true;
            campo.value = "";

        });
    }
}

fechaNacimiento.addEventListener("change", actualizarTutor);


// =========================
// FORMATO DE TELÉFONO
// =========================

function formatoTelefono(input) {

    input.addEventListener("input", function() {

        let numero = this.value.replace(/\D/g, "");

        numero = numero.substring(0, 10);

        if(numero.length > 3) {

            numero =
                numero.substring(0, 3) +
                "-" +
                numero.substring(3);
        }

        if(numero.length > 7) {

            numero =
                numero.substring(0, 7) +
                "-" +
                numero.substring(7);
        }

        this.value = numero;
    });
}

formatoTelefono(
    document.getElementById("telefono_paciente")
);

formatoTelefono(
    document.getElementById("tutor_telefono")
);


// =========================
// CONVERTIR A MAYÚSCULAS
// =========================

document.querySelectorAll(
    'input[type="text"], textarea'
) .forEach(function(input) {
    input.addEventListener("input", function(){
        this.value = this.value.toUpperCase();
    });
});