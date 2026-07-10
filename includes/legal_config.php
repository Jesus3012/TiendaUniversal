<?php
/**
 * includes/legal_config.php
 *
 * Configuración y contenido centralizado de:
 * - Términos y Condiciones de Uso.
 * - Aviso de Privacidad Integral.
 *
 * Este archivo es utilizado por:
 * - documentos_legales.php
 * - guardar_aceptacion_legal.php
 */

declare(strict_types=1);

const LEGAL_VERSION_TERMINOS = '1.0.0';
const LEGAL_VERSION_PRIVACIDAD = '1.0.0';
const LEGAL_FECHA_DOCUMENTOS = '10 de julio de 2026';

if (!function_exists('legal_html')) {
    function legal_html($valor)
    {
        return htmlspecialchars(
            trim((string)$valor),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

if (!function_exists('legal_obtener_usuario_id')) {
    function legal_obtener_usuario_id($conn)
    {
        $usuarioId = (int)(
            $_SESSION['usuario_id']
            ?? $_SESSION['id_usuario']
            ?? $_SESSION['id']
            ?? 0
        );

        if ($usuarioId > 0) {
            return $usuarioId;
        }

        $nombre = trim((string)($_SESSION['nombre'] ?? ''));

        if ($nombre === '') {
            return 0;
        }

        $stmt = $conn->prepare(
            'SELECT id FROM usuarios WHERE nombre = ? LIMIT 1'
        );

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('s', $nombre);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $fila = $resultado ? $resultado->fetch_assoc() : null;

        $stmt->close();

        if (!$fila) {
            return 0;
        }

        $usuarioId = (int)$fila['id'];

        $_SESSION['id'] = $usuarioId;
        $_SESSION['usuario_id'] = $usuarioId;

        return $usuarioId;
    }
}

if (!function_exists('legal_asegurar_tabla')) {
    function legal_asegurar_tabla($conn)
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS aceptaciones_legales (
                id int(11) NOT NULL AUTO_INCREMENT,
                usuario_id int(11) NOT NULL,
                version_terminos varchar(30) NOT NULL,
                version_privacidad varchar(30) NOT NULL,
                hash_terminos char(64) DEFAULT NULL,
                hash_privacidad char(64) DEFAULT NULL,
                acepto_terminos tinyint(1) NOT NULL DEFAULT 0,
                acepto_privacidad tinyint(1) NOT NULL DEFAULT 0,
                fecha_aceptacion datetime DEFAULT NULL,
                fecha_revocacion datetime DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                updated_at timestamp NOT NULL
                    DEFAULT current_timestamp()
                    ON UPDATE current_timestamp(),

                PRIMARY KEY (id),

                UNIQUE KEY uk_usuario_version_legal (
                    usuario_id,
                    version_terminos,
                    version_privacidad
                ),

                KEY idx_aceptaciones_usuario (usuario_id),
                KEY idx_aceptaciones_fecha (fecha_aceptacion)
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($sql)) {
            throw new RuntimeException(
                'No se pudo preparar la tabla aceptaciones_legales: '
                . $conn->error
            );
        }
    }
}

if (!function_exists('legal_obtener_datos_tienda')) {
    function legal_obtener_datos_tienda($conn)
    {
        $datos = [
            'nombre' => 'Tienda Pescadores',
            'telefono' => 'Pendiente de configurar',
            'email' => 'Pendiente de configurar',
            'direccion' => 'Pendiente de configurar',
            'horario' => 'Pendiente de configurar'
        ];

        $resultado = $conn->query(
            "SELECT nombre, telefono, email, direccion, horario
             FROM configuracion_galeria
             WHERE id = 1
             LIMIT 1"
        );

        if (!$resultado || $resultado->num_rows === 0) {
            return $datos;
        }

        $fila = $resultado->fetch_assoc();

        foreach ($datos as $campo => $valorPredeterminado) {
            $valor = trim((string)($fila[$campo] ?? ''));

            if ($valor !== '') {
                $datos[$campo] = $valor;
            }
        }

        return $datos;
    }
}

if (!function_exists('legal_construir_documentos')) {
    function legal_construir_documentos($conn)
    {
        $tienda = legal_obtener_datos_tienda($conn);

        $reemplazos = [
            '{{NOMBRE_TIENDA}}' => legal_html($tienda['nombre']),
            '{{TELEFONO}}' => legal_html($tienda['telefono']),
            '{{EMAIL}}' => legal_html($tienda['email']),
            '{{DIRECCION}}' => nl2br(legal_html($tienda['direccion'])),
            '{{HORARIO}}' => legal_html($tienda['horario']),
            '{{VERSION_TERMINOS}}' => legal_html(LEGAL_VERSION_TERMINOS),
            '{{VERSION_PRIVACIDAD}}' => legal_html(LEGAL_VERSION_PRIVACIDAD),
            '{{FECHA_DOCUMENTOS}}' => legal_html(LEGAL_FECHA_DOCUMENTOS)
        ];

        $terminos = <<<'HTML'
<article class="legal-contenido-documento">
    <header class="legal-doc-cabecera">
        <span class="legal-doc-insignia">Documento vigente</span>
        <h3>Términos y Condiciones de Uso de {{NOMBRE_TIENDA}}</h3>
        <p>
            <strong>Versión:</strong> {{VERSION_TERMINOS}}
            <span class="legal-separador">•</span>
            <strong>Última actualización:</strong> {{FECHA_DOCUMENTOS}}
        </p>
    </header>

    <section>
        <h4>1. Objeto y alcance</h4>
        <p>
            Los presentes Términos y Condiciones regulan el acceso, navegación y uso de la aplicación
            <strong>{{NOMBRE_TIENDA}}</strong>, en adelante “la Aplicación”. La Aplicación es una
            herramienta tecnológica destinada a apoyar la operación interna del establecimiento,
            incluyendo la administración de ventas, inventarios, productos, proveedores, pedidos,
            usuarios, caja, cancelaciones, devoluciones, reportes y demás procesos relacionados.
        </p>
        <p>
            Estos términos son obligatorios para toda persona que tenga una cuenta activa dentro del
            sistema, independientemente de que su rol sea administrador, vendedor o cualquier otro que
            se incorpore posteriormente. El acceso a una función no significa que el usuario tenga
            autorización para utilizarla con fines distintos a los que le fueron asignados.
        </p>
        <p>
            La aceptación electrónica registrada en la Aplicación acredita que el usuario tuvo la
            oportunidad de leer el documento y manifestó su conformidad con su contenido. Si el usuario
            no está de acuerdo, deberá cerrar sesión y comunicarlo al responsable de la tienda.
        </p>
    </section>

    <section>
        <h4>2. Naturaleza y finalidad del sistema</h4>
        <p>
            La Aplicación funciona como una herramienta de control operativo. Su finalidad es facilitar
            el registro, consulta y seguimiento de información necesaria para el funcionamiento de la
            tienda. Entre sus funciones pueden encontrarse:
        </p>
        <ul>
            <li>Registrar ventas y relacionarlas con productos, cantidades, vendedores y formas de pago.</li>
            <li>Consultar existencias, movimientos, ajustes y disponibilidad de productos.</li>
            <li>Administrar productos, precios, proveedores, códigos de barras e imágenes.</li>
            <li>Abrir y cerrar sesiones de caja y consultar diferencias o movimientos relacionados.</li>
            <li>Registrar pedidos, faltantes, devoluciones y cancelaciones.</li>
            <li>Generar reportes operativos, historiales y elementos de auditoría.</li>
            <li>Administrar usuarios, roles, permisos, contraseñas y productos asignados.</li>
        </ul>
        <p>
            La Aplicación no sustituye la revisión humana de las operaciones. El usuario debe comprobar
            que la información mostrada corresponda con la operación física realizada.
        </p>
    </section>

    <section>
        <h4>3. Usuarios autorizados y roles</h4>
        <p>
            El acceso se encuentra limitado a personas expresamente autorizadas. Cada cuenta se asocia
            con un rol y con permisos determinados. El usuario reconoce que:
        </p>
        <ul>
            <li>Solo debe utilizar las funciones necesarias para realizar las actividades que le fueron asignadas.</li>
            <li>No debe intentar acceder a módulos, rutas o información restringida para su rol.</li>
            <li>La existencia de un enlace visible no implica autorización cuando el rol no permite la operación.</li>
            <li>Los permisos pueden modificarse, limitarse o retirarse cuando cambien las funciones del usuario.</li>
            <li>El responsable puede revisar y auditar las operaciones efectuadas desde cada cuenta.</li>
        </ul>
        <p>
            La cuenta es individual. No se permite crear cuentas genéricas compartidas ni operar de forma
            habitual con las credenciales de otra persona.
        </p>
    </section>

    <section>
        <h4>4. Registro, contraseña y seguridad de la cuenta</h4>
        <p>
            Las credenciales de acceso son personales, confidenciales e intransferibles. El usuario es
            responsable de proteger su contraseña y de evitar que otras personas utilicen su sesión.
            Para reducir riesgos deberá:
        </p>
        <ul>
            <li>Utilizar una contraseña difícil de adivinar y diferente de las usadas en otros servicios.</li>
            <li>Cambiar la contraseña temporal cuando la Aplicación lo solicite.</li>
            <li>No escribir ni dejar visible la contraseña cerca del equipo.</li>
            <li>No compartir la contraseña por mensajes, correo, llamadas o fotografías.</li>
            <li>Cerrar sesión al terminar su turno o cuando se aleje del equipo.</li>
            <li>Reportar inmediatamente accesos no reconocidos, pérdida de credenciales o actividad sospechosa.</li>
        </ul>
        <p>
            Las operaciones realizadas desde una sesión autenticada podrán considerarse asociadas al
            usuario de la cuenta, sin perjuicio de la investigación correspondiente cuando exista una
            posible suplantación, falla técnica o acceso no autorizado.
        </p>
    </section>

    <section>
        <h4>5. Obligaciones generales del usuario</h4>
        <p>El usuario se compromete a:</p>
        <ul>
            <li>Capturar información verdadera, suficiente y relacionada con operaciones reales.</li>
            <li>Revisar los datos antes de confirmar o guardar una operación.</li>
            <li>Seguir los procedimientos internos de venta, inventario, caja y devolución.</li>
            <li>Utilizar equipos, impresoras, lectores y cajones de dinero de manera responsable.</li>
            <li>Informar errores que puedan afectar existencias, precios, ventas, reportes o cortes.</li>
            <li>Respetar la confidencialidad de la información comercial y personal.</li>
            <li>Colaborar en revisiones, aclaraciones y auditorías internas cuando sea necesario.</li>
        </ul>
    </section>

    <section>
        <h4>6. Registro de ventas y formas de pago</h4>
        <p>
            Antes de completar una venta, el usuario deberá verificar el producto, cantidad, precio,
            descuentos autorizados, método de pago, referencia y total. Cuando la venta sea en efectivo,
            deberá confirmar el dinero recibido y el cambio entregado. Cuando sea con tarjeta,
            transferencia u otro método, deberá verificar que la operación se encuentre autorizada o
            respaldada por el comprobante correspondiente.
        </p>
        <p>
            El registro en el sistema no sustituye la recepción efectiva del pago. Una venta no debe
            marcarse como concluida cuando el cobro no se haya confirmado.
        </p>
    </section>

    <section>
        <h4>7. Inventario, productos y códigos de barras</h4>
        <p>
            Los registros de inventario deben reflejar, en la medida de lo posible, la existencia física.
            Los usuarios autorizados para crear o modificar productos deben revisar nombre, categoría,
            proveedor, precios, cantidad, tipo de inventario, modalidad de código y estado del producto.
        </p>
        <p>
            Los códigos de barras deberán asignarse al producto correcto. Cuando se utilice un lector,
            el usuario deberá confirmar visualmente que el producto detectado coincida con el artículo
            físico antes de completar la operación. La lectura correcta del código no elimina la obligación
            de revisar la información mostrada.
        </p>
    </section>

    <section>
        <h4>8. Caja, cortes y manejo de efectivo</h4>
        <p>
            La apertura y cierre de caja deberán realizarse con los montos reales y dentro del turno
            correspondiente. El usuario debe registrar correctamente entradas, salidas, ventas y efectivo
            contado. Cualquier diferencia deberá informarse y documentarse conforme al procedimiento
            interno.
        </p>
        <p>
            El sistema puede calcular montos esperados y diferencias, pero dichos resultados dependen
            de la calidad de la información capturada. El conteo físico y la validación del responsable
            continúan siendo necesarios.
        </p>
    </section>

    <section>
        <h4>9. Cancelaciones, devoluciones y correcciones</h4>
        <p>
            Las cancelaciones y devoluciones deberán responder a una operación real, contar con la
            autorización correspondiente y registrar el motivo cuando el procedimiento lo requiera.
            No deberán utilizarse para ocultar faltantes, modificar resultados, alterar comisiones o
            eliminar evidencia de una venta.
        </p>
        <p>
            Cuando exista un error de captura, deberá corregirse mediante la función autorizada del
            sistema. No se permite modificar directamente la base de datos, eliminar archivos o alterar
            registros fuera de los procedimientos establecidos.
        </p>
    </section>

    <section>
        <h4>10. Conductas prohibidas</h4>
        <p>Queda prohibido:</p>
        <ul>
            <li>Registrar operaciones ficticias o deliberadamente incorrectas.</li>
            <li>Modificar precios, cantidades, permisos o configuraciones sin autorización.</li>
            <li>Eliminar, ocultar, manipular o destruir registros para evitar una revisión.</li>
            <li>Compartir cuentas, contraseñas o sesiones abiertas.</li>
            <li>Utilizar datos de clientes, proveedores o usuarios para fines personales.</li>
            <li>Descargar, copiar o divulgar reportes sin necesidad operativa o autorización.</li>
            <li>Intentar evadir restricciones de rol, autenticación o seguridad.</li>
            <li>Introducir virus, scripts, extensiones o programas que comprometan el sistema.</li>
            <li>Interferir con el funcionamiento de impresoras, lectores, cajones, servidores o red.</li>
            <li>Realizar ingeniería inversa, copiar o redistribuir el código sin autorización.</li>
            <li>Usar la Aplicación para actividades ilícitas, fraudulentas o ajenas a la tienda.</li>
        </ul>
    </section>

    <section>
        <h4>11. Auditoría, trazabilidad y evidencia de operaciones</h4>
        <p>
            La Aplicación puede conservar registros relacionados con accesos, ventas, movimientos de
            inventario, aperturas y cierres de caja, cancelaciones, devoluciones, modificaciones,
            generación de reportes y aceptación de documentos legales. Estos registros pueden incluir
            el usuario, la acción, el detalle y la fecha correspondiente.
        </p>
        <p>
            La información de auditoría podrá utilizarse para aclaraciones, seguridad, prevención de
            fraude, control interno, solución de incidencias y determinación de responsabilidades. Los
            registros de auditoría no deberán modificarse salvo mediante procedimientos autorizados.
        </p>
    </section>

    <section>
        <h4>12. Confidencialidad</h4>
        <p>
            El usuario deberá tratar como confidencial la información a la que tenga acceso, incluyendo
            ventas, precios de compra, márgenes, inventarios, reportes, datos de proveedores, datos de
            clientes, credenciales, respaldos, configuraciones y cualquier información no destinada al
            público.
        </p>
        <p>
            Esta obligación continúa después de que la cuenta sea desactivada o termine la relación
            laboral, comercial o de colaboración. La información solo podrá compartirse con personas
            autorizadas y cuando exista una necesidad relacionada con la operación.
        </p>
    </section>

    <section>
        <h4>13. Propiedad de la información y propiedad intelectual</h4>
        <p>
            La autorización de uso no transfiere al usuario derechos sobre el código, diseño, marcas,
            logotipos, documentación, bases de datos o información comercial. El usuario recibe únicamente
            un permiso limitado, revocable, personal y no transferible para utilizar la Aplicación durante
            el tiempo en que conserve una cuenta autorizada.
        </p>
        <p>
            La información operativa generada dentro de la Aplicación será administrada por el responsable
            de la tienda, sin perjuicio de los derechos que correspondan a las personas titulares de datos
            personales.
        </p>
    </section>

    <section>
        <h4>14. Disponibilidad, mantenimiento y fallas</h4>
        <p>
            La Aplicación puede interrumpirse por mantenimiento, actualizaciones, fallas eléctricas,
            problemas de internet, errores de configuración, daños en equipos, servicios de terceros,
            incidentes de seguridad o causas fuera del control razonable del responsable.
        </p>
        <p>
            El usuario deberá reportar las fallas y evitar repetir operaciones cuando exista el riesgo de
            duplicarlas. Si una venta, devolución o movimiento no muestra confirmación, deberá verificarse
            su existencia antes de intentarlo nuevamente.
        </p>
    </section>

    <section>
        <h4>15. Copias de seguridad y conservación</h4>
        <p>
            Podrán realizarse respaldos periódicos para proteger la continuidad de la operación. Sin
            embargo, ningún respaldo garantiza la recuperación absoluta de información ante cualquier
            evento. Los usuarios deberán seguir los procedimientos definidos y evitar almacenar archivos
            críticos únicamente en equipos locales no respaldados.
        </p>
    </section>

    <section>
        <h4>16. Suspensión, bloqueo o cancelación de cuentas</h4>
        <p>
            El responsable podrá suspender, bloquear o desactivar una cuenta cuando termine la relación
            con el usuario, cambien sus funciones, exista riesgo de seguridad, se detecte un uso indebido,
            se compartan credenciales, se incumplan estos términos o sea necesario proteger la operación.
        </p>
        <p>
            La suspensión preventiva podrá mantenerse mientras se revisa un incidente. La desactivación
            de la cuenta no implica necesariamente la eliminación inmediata de los registros relacionados
            con operaciones realizadas previamente.
        </p>
    </section>

    <section>
        <h4>17. Responsabilidad y límites razonables</h4>
        <p>
            Cada usuario es responsable de actuar con cuidado, respetar sus permisos y revisar sus
            operaciones. En la medida permitida por la legislación aplicable, el responsable no responderá
            por afectaciones originadas exclusivamente por uso indebido, captura deliberadamente incorrecta,
            divulgación de credenciales, equipos no autorizados o incumplimiento de los procedimientos.
        </p>
        <p>
            Ninguna disposición de estos términos pretende excluir responsabilidades que legalmente no
            puedan limitarse ni afectar los derechos reconocidos por la legislación aplicable.
        </p>
    </section>

    <section>
        <h4>18. Modificaciones de la Aplicación y de estos términos</h4>
        <p>
            Las funciones, diseños, módulos y reglas de uso pueden actualizarse para mejorar la operación,
            corregir errores, reforzar la seguridad o cumplir nuevas obligaciones. Cuando se realice una
            modificación relevante de estos términos, se publicará una nueva versión y se solicitará una
            nueva aceptación.
        </p>
    </section>

    <section>
        <h4>19. Legislación aplicable y solución de controversias</h4>
        <p>
            Estos términos se interpretarán conforme a las leyes vigentes de los Estados Unidos Mexicanos.
            Cualquier diferencia deberá intentarse resolver primero mediante comunicación con el responsable
            de la tienda. Cuando no sea posible, se atenderá ante la autoridad competente conforme a las
            reglas legales aplicables.
        </p>
    </section>

    <section>
        <h4>20. Contacto</h4>
        <dl class="legal-datos-contacto">
            <div><dt>Empresa:</dt><dd>RexCoreSolutions</dd></div>
            <div><dt>Correo:</dt><dd>rexcoresolutions@gmail.com</dd></div>
        </dl>
    </section>
</article>
HTML;

        $privacidad = <<<'HTML'
<article class="legal-contenido-documento">
    <header class="legal-doc-cabecera">
        <span class="legal-doc-insignia">Aviso integral</span>
        <h3>Aviso de Privacidad Integral de RexCoreSolutions</h3>
        <p>
            <strong>Versión:</strong> {{VERSION_PRIVACIDAD}}
            <span class="legal-separador">•</span>
            <strong>Última actualización:</strong> {{FECHA_DOCUMENTOS}}
        </p>
    </header>

    <section>
        <h4>1. Identidad y contacto del responsable</h4>
        <p>
            <strong>RexCoreSolutions</strong> es responsable del tratamiento de los datos personales
            recabados y utilizados mediante la Aplicación.
        </p>
        <p>
            Para dudas, solicitudes o asuntos relacionados con privacidad y protección de datos,
            las personas titulares podrán comunicarse únicamente mediante el correo
            <strong>rexcoresolutions@gmail.com</strong>.
        </p>
    </section>

    <section>
        <h4>2. Alcance del aviso</h4>
        <p>
            Este aviso aplica a los datos personales tratados dentro de la Aplicación y a la información
            relacionada con cuentas de administradores, vendedores, personal autorizado, clientes que
            proporcionen datos para recibir comprobantes y contactos de proveedores cuando se registren
            en el sistema.
        </p>
        <p>
            El aviso describe qué información puede utilizarse, para qué finalidades, durante cuánto tiempo,
            con quién puede compartirse y qué mecanismos existen para ejercer derechos.
        </p>
    </section>

    <section>
        <h4>3. Datos personales de usuarios del sistema</h4>
        <p>Para crear y administrar cuentas pueden tratarse:</p>
        <ul>
            <li>Nombre completo.</li>
            <li>Correo electrónico.</li>
            <li>Identificador interno de usuario.</li>
            <li>Rol, permisos y productos asignados.</li>
            <li>Fotografía de perfil, cuando el usuario decida proporcionarla o sea requerida internamente.</li>
            <li>Fecha de registro, estado de la cuenta y usuario que realizó el alta.</li>
            <li>Indicadores relacionados con cambio obligatorio o recuperación de contraseña.</li>
        </ul>
    </section>

    <section>
        <h4>4. Datos de autenticación y seguridad</h4>
        <p>
            Para proteger el acceso pueden tratarse contraseñas almacenadas mediante funciones de hash,
            tokens temporales de recuperación, identificadores de sesión, registros de autenticación,
            eventos de seguridad e información necesaria para prevenir accesos no autorizados.
        </p>
        <p>
            Las contraseñas no deben almacenarse ni mostrarse en texto visible. Los tokens de recuperación
            deberán ser temporales y dejar de ser válidos cuando sean utilizados o alcancen su fecha de
            expiración.
        </p>
    </section>

    <section>
        <h4>5. Datos operativos asociados con una cuenta</h4>
        <p>
            La Aplicación puede relacionar al usuario con acciones como ventas, productos registrados,
            modificaciones de inventario, pedidos, reportes, aperturas o cierres de caja, cancelaciones,
            devoluciones y demás actividades efectuadas durante el uso del sistema.
        </p>
        <p>
            Estos datos se utilizan para identificar quién realizó una operación, atender aclaraciones,
            mantener trazabilidad y proteger la integridad de la información.
        </p>
    </section>

    <section>
        <h4>6. Datos de clientes</h4>
        <p>
            Durante una venta puede recabarse el correo electrónico del cliente cuando este lo proporcione
            para recibir un ticket, comprobante o información directamente relacionada con la operación.
            El correo no deberá utilizarse para publicidad o finalidades distintas sin informar previamente
            y, cuando corresponda, obtener el consentimiento necesario.
        </p>
        <p>
            La Aplicación no está diseñada para solicitar datos financieros completos de tarjetas. Las
            referencias de pago deberán limitarse a la información necesaria para identificar la operación,
            evitando capturar números completos, códigos de seguridad o contraseñas bancarias.
        </p>
    </section>

    <section>
        <h4>7. Datos de proveedores</h4>
        <p>
            Para administrar proveedores pueden tratarse nombre, correo, teléfono, logotipo, domicilio,
            país y demás datos de contacto necesarios para pedidos, seguimiento de productos, reportes,
            comunicación comercial y cumplimiento de la relación correspondiente.
        </p>
    </section>

    <section>
        <h4>8. Datos personales sensibles</h4>
        <p>
            La Aplicación no tiene como finalidad recopilar datos personales sensibles, como información
            médica, biométrica, genética, religiosa, política o relacionada con preferencias sexuales.
            Los usuarios deberán abstenerse de capturar este tipo de información en notas, observaciones
            o campos que no fueron diseñados para ello.
        </p>
        <p>
            Si posteriormente se incorpora una función que requiera datos sensibles, deberá actualizarse
            este aviso y establecerse el mecanismo de consentimiento que corresponda.
        </p>
    </section>

    <section>
        <h4>9. Finalidades primarias y necesarias</h4>
        <p>Los datos se utilizarán para:</p>
        <ul>
            <li>Crear, validar, administrar, recuperar y desactivar cuentas.</li>
            <li>Autenticar usuarios y mantener sesiones seguras.</li>
            <li>Asignar roles, permisos y productos a vendedores.</li>
            <li>Permitir el acceso a las funciones autorizadas.</li>
            <li>Registrar ventas, formas de pago y referencias necesarias.</li>
            <li>Enviar tickets o comprobantes al correo proporcionado por el cliente.</li>
            <li>Administrar productos, códigos de barras, inventario, proveedores y pedidos.</li>
            <li>Gestionar aperturas, movimientos y cierres de caja.</li>
            <li>Registrar cancelaciones, devoluciones y ajustes autorizados.</li>
            <li>Generar reportes e historiales operativos.</li>
            <li>Atender aclaraciones, incidencias y solicitudes de soporte.</li>
            <li>Prevenir accesos no autorizados, fraude, alteraciones y pérdida de información.</li>
            <li>Realizar revisiones y auditorías internas.</li>
            <li>Conservar evidencia de aceptación de documentos legales.</li>
            <li>Cumplir obligaciones legales, fiscales, administrativas o contractuales aplicables.</li>
        </ul>
        <p>
            Estas finalidades son necesarias para utilizar la Aplicación y mantener el control operativo
            del establecimiento.
        </p>
    </section>

    <section>
        <h4>10. Finalidades secundarias</h4>
        <p>
            La información podrá utilizarse de forma agregada o disociada para elaborar estadísticas,
            analizar rendimiento, identificar errores recurrentes, mejorar pantallas, evaluar tiempos de
            atención y planear funciones nuevas.
        </p>
        <p>
            Cuando alguna finalidad secundaria requiera datos identificables y no sea necesaria para la
            operación, se informará el mecanismo para manifestar la negativa sin afectar las funciones
            indispensables de la cuenta.
        </p>
    </section>

    <section>
        <h4>11. Principios aplicables al tratamiento</h4>
        <p>
            El tratamiento procurará realizarse de manera lícita, informada, proporcional y limitada a
            finalidades determinadas. Se buscará mantener los datos correctos, actualizados y necesarios
            para la operación, evitando recopilar información excesiva.
        </p>
        <p>
            El acceso se limitará conforme al rol y a las funciones de cada usuario. Las personas con
            permisos administrativos deberán utilizarlos únicamente para fines autorizados.
        </p>
    </section>

    <section>
        <h4>12. Consentimiento y puesta a disposición</h4>
        <p>
            El aviso se presenta al usuario dentro de la Aplicación. La aceptación electrónica permite
            dejar constancia de la versión conocida. En los casos en que el tratamiento sea necesario
            para administrar la relación con el usuario o cumplir obligaciones aplicables, la revocación
            puede implicar la desactivación de la cuenta o la imposibilidad de continuar utilizando ciertas
            funciones.
        </p>
    </section>

    <section>
        <h4>13. Remisiones y proveedores tecnológicos</h4>
        <p>
            Para operar la Aplicación pueden intervenir proveedores de alojamiento, infraestructura,
            correo electrónico, respaldo, soporte, mantenimiento o seguridad. Cuando estas personas
            traten datos por cuenta del responsable, deberán hacerlo conforme a las instrucciones
            correspondientes, mantener confidencialidad y aplicar medidas de seguridad razonables.
        </p>
        <p>
            El uso de servicios de terceros deberá limitarse a aquellos necesarios para la operación y
            evitar configuraciones que expongan públicamente bases de datos, respaldos o credenciales.
        </p>
    </section>

    <section>
        <h4>14. Transferencias</h4>
        <p>
            Los datos podrán comunicarse a autoridades competentes cuando exista una obligación legal,
            orden fundada o necesidad de colaborar con una investigación. También podrán compartirse
            con asesores jurídicos, contables o técnicos cuando sea indispensable y exista un deber de
            confidencialidad.
        </p>
        <p>
            Los datos personales no serán vendidos, alquilados ni comercializados. Cualquier transferencia
            adicional que requiera consentimiento será informada previamente.
        </p>
    </section>

    <section>
        <h4>15. Cookies, sesiones y almacenamiento local</h4>
        <p>
            La Aplicación puede utilizar cookies técnicas, variables de sesión y almacenamiento local del
            navegador para mantener la autenticación, proteger solicitudes, recordar preferencias,
            conservar temporalmente el estado de una pantalla o evitar que ciertos avisos se repitan
            durante la misma sesión.
        </p>
        <p>
            Estas tecnologías deberán utilizarse para funciones técnicas y no para publicidad de terceros.
            Eliminar las cookies puede cerrar la sesión o requerir una nueva autenticación.
        </p>
    </section>

    <section>
        <h4>16. Medidas de seguridad</h4>
        <p>
            Se procurará aplicar medidas administrativas, técnicas y físicas acordes con la naturaleza de
            la información y los riesgos identificados. Estas medidas pueden incluir:
        </p>
        <ul>
            <li>Control de acceso por usuario y rol.</li>
            <li>Contraseñas protegidas mediante hash.</li>
            <li>Tokens temporales para recuperación de acceso.</li>
            <li>Sesiones y validaciones contra solicitudes no autorizadas.</li>
            <li>Respaldos y procedimientos de recuperación.</li>
            <li>Registros de auditoría y revisión de incidencias.</li>
            <li>Actualización de componentes y restricciones de acceso a la base de datos.</li>
            <li>Capacitación y reglas de confidencialidad para usuarios autorizados.</li>
        </ul>
        <p>
            Ningún sistema es absolutamente invulnerable. Los usuarios deben contribuir protegiendo sus
            credenciales y reportando cualquier actividad sospechosa.
        </p>
    </section>

    <section>
        <h4>17. Vulneraciones de seguridad</h4>
        <p>
            Cuando se confirme una vulneración que pueda afectar significativamente los derechos de las
            personas titulares, se evaluará su alcance, la información comprometida, las medidas adoptadas
            y la necesidad de comunicar el incidente conforme a la legislación aplicable.
        </p>
    </section>

    <section>
        <h4>18. Conservación, bloqueo y eliminación</h4>
        <p>
            Los datos se conservarán durante el tiempo necesario para mantener la cuenta, administrar la
            operación, atender aclaraciones, cumplir obligaciones, realizar auditorías y determinar
            posibles responsabilidades. Una cuenta inactiva no implica que todas las operaciones
            históricas deban eliminarse inmediatamente.
        </p>
        <p>
            Cuando proceda la cancelación, los datos podrán permanecer bloqueados durante el periodo
            necesario para atender responsabilidades y posteriormente serán suprimidos o disociados,
            conforme a las posibilidades técnicas y obligaciones aplicables.
        </p>
    </section>

    <section>
        <h4>19. Derechos de acceso, rectificación, cancelación y oposición</h4>
        <p>La persona titular puede solicitar:</p>
        <ul>
            <li><strong>Acceso:</strong> conocer qué datos se conservan y las condiciones generales de su tratamiento.</li>
            <li><strong>Rectificación:</strong> corregir datos inexactos, incompletos o desactualizados.</li>
            <li><strong>Cancelación:</strong> solicitar el bloqueo y posterior supresión cuando legalmente proceda.</li>
            <li><strong>Oposición:</strong> solicitar que cese determinado tratamiento cuando exista una causa legítima.</li>
        </ul>
    </section>

    <section>
        <h4>20. Procedimiento para ejercer derechos</h4>
        <p>
            La solicitud podrá presentarse mediante el correo <strong>rexcoresolutions@gmail.com</strong> o directamente
            con el responsable. Deberá incluir:
        </p>
        <ol>
            <li>Nombre de la persona titular y un medio para recibir respuesta.</li>
            <li>Descripción clara del derecho que desea ejercer.</li>
            <li>Datos o cuenta respecto de los cuales presenta la solicitud.</li>
            <li>Documento o mecanismo que permita acreditar la identidad.</li>
            <li>Cuando actúe una persona representante, la documentación que acredite la representación.</li>
            <li>Elementos que ayuden a localizar o corregir la información.</li>
        </ol>
        <p>
            Se comunicará la determinación adoptada dentro del plazo legal aplicable. Si la solicitud
            resulta procedente, se hará efectiva dentro del periodo establecido por la legislación,
            considerando las ampliaciones justificadas que legalmente puedan corresponder.
        </p>
    </section>

    <section>
        <h4>21. Revocación del consentimiento y limitación de uso</h4>
        <p>
            Cuando el tratamiento dependa del consentimiento, la persona titular podrá solicitar su
            revocación. La revocación no tendrá efectos retroactivos y no impedirá conservar información
            necesaria para cumplir obligaciones, atender responsabilidades o defender derechos.
        </p>
        <p>
            También podrá solicitarse la limitación de usos secundarios. Cuando la información sea
            indispensable para autenticar la cuenta o registrar operaciones, limitarla puede impedir el
            uso total o parcial de la Aplicación.
        </p>
    </section>

    <section>
        <h4>22. Exactitud y actualización de los datos</h4>
        <p>
            Los usuarios deberán comunicar cambios en nombre, correo, rol u otros datos relevantes. El
            responsable podrá solicitar verificaciones razonables para evitar que una persona modifique
            información perteneciente a otra cuenta.
        </p>
    </section>

    <section>
        <h4>23. Cambios al aviso de privacidad</h4>
        <p>
            Este aviso podrá actualizarse por cambios legales, técnicos, administrativos u operativos.
            La versión vigente estará disponible dentro de la Aplicación. Cuando el cambio afecte de
            forma relevante las finalidades, datos o transferencias, se solicitará una nueva aceptación
            o se utilizará el mecanismo que legalmente corresponda.
        </p>
    </section>

    <section>
        <h4>24. Autoridad competente</h4>
        <p>
            Sin perjuicio del contacto directo con el responsable, la persona titular puede acudir ante
            la autoridad competente en materia de protección de datos personales cuando considere que
            su derecho ha sido vulnerado, conforme a la legislación vigente.
        </p>
    </section>

    <section>
        <h4>25. Datos de contacto</h4>
        <dl class="legal-datos-contacto">
            <div><dt>Empresa:</dt><dd>RexCoreSolutions</dd></div>
            <div><dt>Correo:</dt><dd>rexcoresolutions@gmail.com</dd></div>
        </dl>
    </section>
</article>
HTML;

        $terminos = strtr($terminos, $reemplazos);
        $privacidad = strtr($privacidad, $reemplazos);

        return [
            'version_terminos' => LEGAL_VERSION_TERMINOS,
            'version_privacidad' => LEGAL_VERSION_PRIVACIDAD,
            'fecha' => LEGAL_FECHA_DOCUMENTOS,
            'terminos_html' => $terminos,
            'privacidad_html' => $privacidad,
            'hash_terminos' => hash('sha256', $terminos),
            'hash_privacidad' => hash('sha256', $privacidad),
            'tienda' => $tienda
        ];
    }
}
