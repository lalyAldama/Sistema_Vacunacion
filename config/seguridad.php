<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
        "httponly" => true,
        "samesite" => "Lax"
    ]);
    session_start();
}

if (!isset($_SESSION["form_guard_secret"])) {
    $_SESSION["form_guard_secret"] = bin2hex(random_bytes(32));
}

$archivoActual = basename($_SERVER["SCRIPT_NAME"] ?? "");
if (!empty($_SESSION["id_usuario"]) && $archivoActual !== "login.php" && $archivoActual !== "logout.php") {
    $ahora = time();
    $ultimaActividad = (int)($_SESSION["ultima_actividad"] ?? $ahora);

    if ($ahora - $ultimaActividad >= 300) {
        session_unset();
        session_destroy();

        $raizAplicacion = realpath(__DIR__ . "/..");
        $raizDocumentos = realpath($_SERVER["DOCUMENT_ROOT"] ?? "");
        $rutaBase = "";
        if ($raizAplicacion && $raizDocumentos) {
            $rutaBase = str_replace("\\", "/", substr($raizAplicacion, strlen($raizDocumentos)));
        }
        $rutaLogin = rtrim($rutaBase, "/") . "/login.php?sesion=expirada";
        header("Location: " . $rutaLogin);
        exit();
    }

    $_SESSION["ultima_actividad"] = $ahora;
}

function validarFormularioHumano()
{
    if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
        return;
    }

    $campoTrampa = $_POST["_human_check"] ?? "";
    $inicio = filter_var($_POST["_form_started_at"] ?? null, FILTER_VALIDATE_INT);
    $prueba = $_POST["_form_proof"] ?? "";
    $secreto = $_SESSION["form_guard_secret"] ?? "";

    if (!is_string($campoTrampa) || trim($campoTrampa) !== "") {
        http_response_code(400);
        exit("No fue posible validar el formulario. Regresa e inténtalo de nuevo.");
    }

    if (
        $inicio === false
        || !is_string($prueba)
        || !preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $prueba, $partes)
        || !is_string($secreto)
        || $secreto === ""
        || time() - $inicio < 2
        || time() - $inicio > 3600
    ) {
        http_response_code(400);
        exit("No fue posible validar el formulario. Regresa e inténtalo de nuevo.");
    }

    $firmaEsperada = hash_hmac("sha256", $inicio . "|" . $partes[1], $secreto);
    if (!hash_equals($firmaEsperada, $partes[2])) {
        http_response_code(400);
        exit("No fue posible validar el formulario. Regresa e inténtalo de nuevo.");
    }
}

validarFormularioHumano();

function agregarProteccionHumanaAFormularios($html)
{
    return preg_replace_callback('/<form\b[^>]*>/i', function ($coincidencia) {
        if (!preg_match('/\bmethod\s*=\s*(?:"post"|\'post\'|post)(?:\s|>)/i', $coincidencia[0])) {
            return $coincidencia[0];
        }

        $inicio = time();
        $nonce = bin2hex(random_bytes(16));
        $firma = hash_hmac(
            "sha256",
            $inicio . "|" . $nonce,
            $_SESSION["form_guard_secret"]
        );

        return $coincidencia[0]
            . '<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">'
            . '<label for="verificacion-' . $nonce . '">Deja este campo vacío</label>'
            . '<input id="verificacion-' . $nonce . '" type="text" name="_human_check" tabindex="-1" autocomplete="off">'
            . '</div>'
            . '<input type="hidden" name="_form_started_at" value="' . $inicio . '">'
            . '<input type="hidden" name="_form_proof" value="' . $nonce . '.' . $firma . '">';
    }, $html);
}

ob_start("agregarProteccionHumanaAFormularios");
