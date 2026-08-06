<?php
/**
 * includes/documentos_legales.php
 *
 * Muestra automáticamente los documentos cuando el usuario todavía no
 * acepta la versión vigente y permite consultarlos desde el navbar.
 *
 * Este archivo no utiliza SweetAlert, jQuery ni librerías externas.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/db.php';
}

require_once __DIR__ . '/legal_config.php';

$conn->set_charset('utf8mb4');

$legalUsuarioId = legal_obtener_usuario_id($conn);
$legalDocumentos = legal_construir_documentos($conn);
$legalRequiereAceptacion = false;
$legalError = '';

try {
    legal_asegurar_tabla($conn);

    if ($legalUsuarioId > 0) {
        /*
         * La aceptación se valida únicamente por usuario, versiones vigentes
         * y estado de aceptación.
         *
         * Los hashes se guardan como evidencia del contenido mostrado al
         * aceptar, pero no se comparan aquí porque los documentos incluyen
         * datos dinámicos de la tienda (nombre, teléfono, correo, dirección y
         * horario). Cambiar esos datos no representa una nueva versión legal.
         */
        $stmt = $conn->prepare(
            "SELECT id
             FROM aceptaciones_legales
             WHERE usuario_id = ?
               AND version_terminos = ?
               AND version_privacidad = ?
               AND acepto_terminos = 1
               AND acepto_privacidad = 1
               AND fecha_revocacion IS NULL
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo verificar la aceptación: ' . $conn->error
            );
        }

        $stmt->bind_param(
            'iss',
            $legalUsuarioId,
            $legalDocumentos['version_terminos'],
            $legalDocumentos['version_privacidad']
        );

        $stmt->execute();
        $resultado = $stmt->get_result();

        $legalRequiereAceptacion =
            !$resultado || $resultado->num_rows === 0;

        $stmt->close();
    }
} catch (Throwable $e) {
    /*
     * Si el usuario tiene sesión y ocurre un error de verificación,
     * se muestra el aviso para no ocultarlo silenciosamente.
     */
    $legalRequiereAceptacion = $legalUsuarioId > 0;
    $legalError = $e->getMessage();

    error_log(
        'Error al verificar documentos legales: ' . $e->getMessage()
    );
}

if (empty($_SESSION['legal_csrf_token'])) {
    $_SESSION['legal_csrf_token'] =
        bin2hex(random_bytes(32));
}

/*
 * Obtener rutas URL desde la ubicación física de la carpeta includes.
 * Esto evita formar includes/includes cuando navbar.php está en includes.
 */
$documentRoot = realpath(
    (string)($_SERVER['DOCUMENT_ROOT'] ?? '')
);

$includesReal = realpath(__DIR__);

$includesUrl = '/includes';

if ($documentRoot && $includesReal) {
    $rootNormalizado = rtrim(
        str_replace('\\', '/', $documentRoot),
        '/'
    );

    $includesNormalizado = str_replace(
        '\\',
        '/',
        $includesReal
    );

    if (strpos($includesNormalizado, $rootNormalizado) === 0) {
        $rutaRelativa = substr(
            $includesNormalizado,
            strlen($rootNormalizado)
        );

        if ($rutaRelativa !== false && $rutaRelativa !== '') {
            $includesUrl = '/' . ltrim($rutaRelativa, '/');
        }
    }
}

$baseUrl = rtrim(
    str_replace('\\', '/', dirname($includesUrl)),
    '/'
);

if ($baseUrl === '.' || $baseUrl === '/') {
    $baseUrl = '';
}

$legalEndpoint =
    $includesUrl . '/guardar_aceptacion_legal.php';

$legalLogout =
    $baseUrl . '/logout.php';
?>

<style>
#legalOverlay {
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 14px;
    background: rgba(15, 23, 42, .78);
    backdrop-filter: blur(5px);
}

#legalOverlay.legal-visible {
    display: flex;
}

body.legal-modal-abierto {
    overflow: hidden !important;
}

.legal-modal {
    width: min(1040px, 100%);
    max-height: calc(100vh - 28px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 28px 80px rgba(15, 23, 42, .38);
}

.legal-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 20px 14px;
    border-bottom: 1px solid #e2e8f0;
    background:
        radial-gradient(circle at top right, rgba(251, 146, 60, .17), transparent 38%),
        #fff;
}

.legal-modal-header h2 {
    margin: 0 0 5px;
    color: #1e293b;
    font-size: 24px;
    line-height: 1.2;
}

.legal-modal-header p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
    line-height: 1.5;
}

.legal-cerrar-superior {
    display: none;
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    border: 0;
    border-radius: 50%;
    color: #64748b;
    background: #f1f5f9;
    font-size: 22px;
    cursor: pointer;
}

.legal-tabs {
    display: flex;
    gap: 8px;
    padding: 12px 18px 0;
    background: #fff;
}

.legal-tab {
    flex: 1;
    min-height: 44px;
    padding: 9px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 11px 11px 0 0;
    color: #64748b;
    background: #f8fafc;
    font-weight: 800;
    cursor: pointer;
}

.legal-tab.activa {
    border-color: #fdba74;
    color: #9a3412;
    background: #fff7ed;
}

.legal-modal-body {
    min-height: 0;
    padding: 0 18px 14px;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.legal-panel {
    display: none;
    padding-top: 14px;
}

.legal-panel.activo {
    display: block;
}

.legal-doc-cabecera {
    margin-bottom: 20px;
    padding: 16px;
    border: 1px solid #fed7aa;
    border-radius: 14px;
    background: #fff7ed;
}

.legal-doc-cabecera h3 {
    margin: 5px 0 7px;
    color: #7c2d12;
    font-size: 21px;
    line-height: 1.3;
}

.legal-doc-cabecera p {
    margin: 0;
    color: #9a3412;
}

.legal-doc-insignia {
    display: inline-flex;
    padding: 4px 9px;
    border-radius: 999px;
    color: #9a3412;
    background: #ffedd5;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.legal-separador {
    margin: 0 6px;
}

.legal-contenido-documento {
    color: #334155;
    font-size: 14px;
    line-height: 1.68;
    text-align: left;
}

.legal-contenido-documento section {
    padding: 2px 2px 13px;
    border-bottom: 1px solid #eef2f7;
}

.legal-contenido-documento section:last-child {
    border-bottom: 0;
}

.legal-contenido-documento h4 {
    margin: 17px 0 7px;
    color: #9a3412;
    font-size: 16px;
    line-height: 1.35;
}

.legal-contenido-documento p {
    margin: 0 0 10px;
}

.legal-contenido-documento ul,
.legal-contenido-documento ol {
    margin: 7px 0 11px;
    padding-left: 23px;
}

.legal-contenido-documento li {
    margin-bottom: 5px;
}

.legal-datos-contacto {
    display: grid;
    gap: 7px;
    margin: 10px 0 0;
}

.legal-datos-contacto div {
    display: grid;
    grid-template-columns: 115px 1fr;
    gap: 10px;
}

.legal-datos-contacto dt {
    color: #475569;
    font-weight: 800;
}

.legal-datos-contacto dd {
    margin: 0;
}

.legal-aceptaciones {
    display: grid;
    gap: 9px;
    padding: 12px 18px 4px;
    border-top: 1px solid #e2e8f0;
    background: #fff;
}

.legal-check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 13px;
    border: 1px solid #e2e8f0;
    border-radius: 11px;
    color: #334155;
    background: #f8fafc;
    cursor: pointer;
}

.legal-check:hover {
    border-color: #fdba74;
    background: #fff7ed;
}

.legal-check input {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    margin-top: 1px;
    accent-color: #ea580c;
}

#legalMensaje {
    display: none;
    margin: 8px 18px 0;
    padding: 10px 12px;
    border-radius: 10px;
    color: #b91c1c;
    background: #fef2f2;
    font-size: 13px;
}

#legalMensaje.visible {
    display: block;
}

#legalMensaje.exito {
    color: #166534;
    background: #f0fdf4;
}

.legal-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    padding: 13px 18px 17px;
    border-top: 1px solid #e2e8f0;
    background: #fff;
}

.legal-btn {
    min-height: 42px;
    padding: 10px 18px;
    border: 0;
    border-radius: 10px;
    font-weight: 800;
    cursor: pointer;
}

.legal-btn-secundario {
    color: #fff;
    background: #64748b;
}

.legal-btn-principal {
    color: #fff;
    background: #ea580c;
}

.legal-btn:disabled {
    opacity: .65;
    cursor: wait;
}

@media (max-width: 700px) {
    #legalOverlay {
        padding: 7px;
    }

    .legal-modal {
        max-height: calc(100vh - 14px);
        border-radius: 15px;
    }

    .legal-modal-header {
        padding: 15px 14px 11px;
    }

    .legal-modal-header h2 {
        font-size: 20px;
    }

    .legal-tabs {
        padding: 10px 10px 0;
    }

    .legal-tab {
        min-height: 48px;
        padding: 8px;
        font-size: 12px;
    }

    .legal-modal-body {
        padding: 0 11px 11px;
    }

    .legal-contenido-documento {
        font-size: 13px;
    }

    .legal-doc-cabecera {
        padding: 13px;
    }

    .legal-doc-cabecera h3 {
        font-size: 18px;
    }

    .legal-datos-contacto div {
        grid-template-columns: 1fr;
        gap: 1px;
    }

    .legal-aceptaciones {
        padding: 10px 11px 3px;
    }

    #legalMensaje {
        margin: 8px 11px 0;
    }

    .legal-modal-footer {
        flex-direction: column-reverse;
        padding: 11px;
    }

    .legal-btn {
        width: 100%;
    }
}
</style>

<div
    id="legalOverlay"
    class="<?php echo $legalRequiereAceptacion ? 'legal-visible' : ''; ?>"
    aria-hidden="<?php echo $legalRequiereAceptacion ? 'false' : 'true'; ?>"
>
    <section
        class="legal-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="legalTitulo"
    >
        <header class="legal-modal-header">
            <div>
                <h2 id="legalTitulo">Privacidad y términos de uso</h2>
                <p id="legalDescripcion">
                    Para continuar, revisa y acepta los documentos vigentes.
                </p>
            </div>

            <button
                type="button"
                id="legalCerrarSuperior"
                class="legal-cerrar-superior"
                aria-label="Cerrar"
            >
                &times;
            </button>
        </header>

        <div class="legal-tabs" role="tablist">
            <button
                type="button"
                class="legal-tab activa"
                id="legalTabTerminos"
                data-panel="legalPanelTerminos"
            >
                Términos y condiciones
            </button>

            <button
                type="button"
                class="legal-tab"
                id="legalTabPrivacidad"
                data-panel="legalPanelPrivacidad"
            >
                Aviso de privacidad
            </button>
        </div>

        <div class="legal-modal-body">
            <div
                id="legalPanelTerminos"
                class="legal-panel activo"
                role="tabpanel"
            >
                <?php echo $legalDocumentos['terminos_html']; ?>
            </div>

            <div
                id="legalPanelPrivacidad"
                class="legal-panel"
                role="tabpanel"
            >
                <?php echo $legalDocumentos['privacidad_html']; ?>
            </div>
        </div>

        <div id="legalAceptaciones" class="legal-aceptaciones">
            <label class="legal-check">
                <input
                    type="checkbox"
                    id="legalAceptoTerminos"
                >
                <span>
                    He leído y acepto los
                    <strong>Términos y Condiciones de Uso</strong>.
                </span>
            </label>

            <label class="legal-check">
                <input
                    type="checkbox"
                    id="legalAceptoPrivacidad"
                >
                <span>
                    He leído el
                    <strong>Aviso de Privacidad Integral</strong>.
                </span>
            </label>
        </div>

        <div id="legalMensaje" role="alert">
            <?php
            if ($legalError !== '') {
                echo legal_html($legalError);
            }
            ?>
        </div>

        <footer class="legal-modal-footer">
            <button
                type="button"
                id="legalBtnSalir"
                class="legal-btn legal-btn-secundario"
            >
                Cerrar sesión
            </button>

            <button
                type="button"
                id="legalBtnAceptar"
                class="legal-btn legal-btn-principal"
            >
                Aceptar y continuar
            </button>

            <button
                type="button"
                id="legalBtnCerrarConsulta"
                class="legal-btn legal-btn-principal"
                style="display:none;"
            >
                Cerrar
            </button>
        </footer>
    </section>
</div>

<script>
(function () {
    'use strict';

    const requiereAceptacion =
        <?php echo $legalRequiereAceptacion ? 'true' : 'false'; ?>;

    const endpointLegal =
        <?php echo json_encode(
            $legalEndpoint,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ); ?>;

    const logoutLegal =
        <?php echo json_encode(
            $legalLogout,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ); ?>;

    const csrfLegal =
        <?php echo json_encode(
            $_SESSION['legal_csrf_token'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ); ?>;

    const overlay =
        document.getElementById('legalOverlay');

    const descripcion =
        document.getElementById('legalDescripcion');

    const aceptaciones =
        document.getElementById('legalAceptaciones');

    const mensaje =
        document.getElementById('legalMensaje');

    const btnAceptar =
        document.getElementById('legalBtnAceptar');

    const btnSalir =
        document.getElementById('legalBtnSalir');

    const btnCerrarConsulta =
        document.getElementById('legalBtnCerrarConsulta');

    const btnCerrarSuperior =
        document.getElementById('legalCerrarSuperior');

    const chkTerminos =
        document.getElementById('legalAceptoTerminos');

    const chkPrivacidad =
        document.getElementById('legalAceptoPrivacidad');

    const tabs =
        document.querySelectorAll('.legal-tab');

    const panels =
        document.querySelectorAll('.legal-panel');

    let modoConsulta = false;

    function seleccionarPanel(panelId) {
        tabs.forEach(function (tab) {
            tab.classList.toggle(
                'activa',
                tab.dataset.panel === panelId
            );
        });

        panels.forEach(function (panel) {
            panel.classList.toggle(
                'activo',
                panel.id === panelId
            );
        });

        const cuerpo =
            document.querySelector('.legal-modal-body');

        if (cuerpo) {
            cuerpo.scrollTop = 0;
        }
    }

    function mostrarMensaje(texto, esExito = false) {
        mensaje.textContent = texto;
        mensaje.classList.add('visible');
        mensaje.classList.toggle('exito', esExito);
    }

    function limpiarMensaje() {
        mensaje.textContent = '';
        mensaje.classList.remove('visible', 'exito');
    }

    function abrirModal(esConsulta = false) {
        modoConsulta = esConsulta === true;

        limpiarMensaje();
        seleccionarPanel('legalPanelTerminos');

        overlay.classList.add('legal-visible');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('legal-modal-abierto');

        if (modoConsulta) {
            descripcion.textContent =
                'Consulta los documentos legales vigentes de la aplicación.';

            aceptaciones.style.display = 'none';
            btnAceptar.style.display = 'none';
            btnSalir.style.display = 'none';
            btnCerrarConsulta.style.display = '';
            btnCerrarSuperior.style.display = 'block';
        } else {
            descripcion.textContent =
                'Para continuar, revisa y acepta los documentos vigentes.';

            aceptaciones.style.display = 'grid';
            btnAceptar.style.display = '';
            btnSalir.style.display = '';
            btnCerrarConsulta.style.display = 'none';
            btnCerrarSuperior.style.display = 'none';
        }
    }

    function cerrarModal() {
        if (!modoConsulta) {
            return;
        }

        overlay.classList.remove('legal-visible');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('legal-modal-abierto');
    }

    async function guardarAceptacion() {
        limpiarMensaje();

        if (!chkTerminos.checked || !chkPrivacidad.checked) {
            mostrarMensaje(
                'Debes marcar las dos casillas para continuar.'
            );
            return;
        }

        btnAceptar.disabled = true;
        btnAceptar.textContent = 'Guardando...';

        try {
            const respuesta = await fetch(endpointLegal, {
                method: 'POST',
                credentials: 'same-origin',

                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfLegal
                },

                body: JSON.stringify({
                    acepto_terminos: true,
                    acepto_privacidad: true
                })
            });

            const texto = await respuesta.text();
            let datos = null;

            try {
                datos = JSON.parse(texto);
            } catch (error) {
                throw new Error(
                    'El servidor devolvió una respuesta no válida. '
                    + 'Revisa guardar_aceptacion_legal.php.'
                );
            }

            if (!respuesta.ok || !datos.success) {
                throw new Error(
                    datos.message
                    || 'No se pudo guardar la aceptación.'
                );
            }

            mostrarMensaje(
                datos.message
                || 'Aceptación registrada correctamente.',
                true
            );

            setTimeout(function () {
                overlay.classList.remove('legal-visible');
                overlay.setAttribute('aria-hidden', 'true');
                document.body.classList.remove(
                    'legal-modal-abierto'
                );
            }, 700);
        } catch (error) {
            mostrarMensaje(
                error.message
                || 'No fue posible guardar la aceptación.'
            );
        } finally {
            btnAceptar.disabled = false;
            btnAceptar.textContent = 'Aceptar y continuar';
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            seleccionarPanel(this.dataset.panel);
        });
    });

    btnAceptar.addEventListener(
        'click',
        guardarAceptacion
    );

    btnSalir.addEventListener('click', function () {
        window.location.href = logoutLegal;
    });

    btnCerrarConsulta.addEventListener(
        'click',
        cerrarModal
    );

    btnCerrarSuperior.addEventListener(
        'click',
        cerrarModal
    );

    document.addEventListener('keydown', function (event) {
        if (
            event.key === 'Escape'
            && modoConsulta
            && overlay.classList.contains('legal-visible')
        ) {
            cerrarModal();
        }
    });

    window.mostrarDocumentosLegales = function (
        esConsulta = true
    ) {
        abrirModal(esConsulta === true);
    };

    function iniciarDocumentosLegales() {
        if (requiereAceptacion) {
            abrirModal(false);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            iniciarDocumentosLegales,
            { once: true }
        );
    } else {
        iniciarDocumentosLegales();
    }
})();
</script>