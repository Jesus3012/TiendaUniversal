(function () {
    'use strict';

    const config = window.POS_BASCULA_CONFIG || {};
    const panel = document.getElementById('basculaPosPanel');
    if (!panel) return;

    const status = document.getElementById('basculaStatus');
    const productoNombre = document.getElementById('basculaProductoNombre');
    const productoPrecio = document.getElementById('basculaProductoPrecio');
    const pesoValor = document.getElementById('basculaPesoValor');
    const pesoDetalle = document.getElementById('basculaPesoDetalle');

    let productoActivo = null;
    let ultimaLectura = null;
    let historial = [];
    let pesoAplicado = null;
    let timer = null;
    let consultando = false;

    function url(ruta) {
        return String(config.url || 'http://127.0.0.1:8787').replace(/\/$/, '') + ruta;
    }

    async function solicitar(ruta, opciones = {}) {
        const headers = Object.assign({ 'Accept': 'application/json' }, opciones.headers || {});
        if (config.token) headers['X-POS-Hardware-Token'] = config.token;
        const respuesta = await fetch(url(ruta), Object.assign({}, opciones, { headers, cache: 'no-store' }));
        const texto = await respuesta.text();
        let datos = {};
        try { datos = JSON.parse(texto); } catch (_) {}
        if (!respuesta.ok || datos.ok === false) {
            throw new Error(datos.message || `Servicio local no disponible (${respuesta.status})`);
        }
        return datos;
    }

    function cambiarEstado(texto, clase = '') {
        if (!status) return;
        status.className = 'bascula-status' + (clase ? ' ' + clase : '');
        status.innerHTML = `<i class="fas fa-circle"></i> ${escapeHtml(texto)}`;
    }

    function formatearPeso(peso) {
        return (Number(peso) || 0).toFixed(3);
    }

    function productoEsPeso(producto) {
        return String(producto?.tipo_venta || '') === 'peso';
    }

    window.seleccionarProductoBascula = function (producto) {
        if (!productoEsPeso(producto)) return false;
        productoActivo = Object.assign({}, producto, {
            tipo_venta: 'peso', unidad_medida: 'kg', decimales_cantidad: 3
        });
        historial = [];
        pesoAplicado = null;
        if (productoNombre) productoNombre.textContent = productoActivo.nombre || 'Producto por peso';
        if (productoPrecio) productoPrecio.textContent = `$${Number(productoActivo.precio || 0).toFixed(2)} por kg`;
        panel.classList.add('producto-seleccionado');
        pesoDetalle.textContent = 'Coloca el producto sobre la báscula.';
        iniciar();
        return true;
    };

    function aplicarPeso(peso, automatico = false) {
        peso = Math.round((Number(peso) || 0) * 1000) / 1000;
        if (!productoActivo || peso < Number(config.pesoMinimoKg || 0.005)) return;
        if (pesoAplicado !== null && Math.abs(pesoAplicado - peso) < 0.0005) return;

        const existente = carrito.find(p => Number(p.id) === Number(productoActivo.id));
        if (existente) {
            existente.cantidad = peso;
            existente.tipo_venta = 'peso';
            existente.unidad_medida = 'kg';
            existente.decimales_cantidad = 3;
            existente.stock = Number(productoActivo.stock || existente.stock || 0);
        } else {
            const iconoData = typeof getIconoPorCategoria === 'function'
                ? getIconoPorCategoria(productoActivo.categoria, productoActivo.nombre)
                : { icono: 'fas fa-weight-scale', color: 'icon-primary' };
            carrito.push(Object.assign({}, productoActivo, {
                cantidad: peso,
                tipo_venta: 'peso',
                unidad_medida: 'kg',
                decimales_cantidad: 3,
                icono: iconoData.icono,
                iconoColor: iconoData.color
            }));
        }

        pesoAplicado = peso;
        guardarCarrito();
        renderCarrito();

        if (!automatico) {
            Swal.fire({
                icon: 'success', title: 'Peso aplicado',
                text: `${productoActivo.nombre}: ${formatearPeso(peso)} kg`,
                toast: true, position: 'top-end', showConfirmButton: false, timer: 1400
            });
        }
    }

    window.capturarPesoBascula = function () {
        if (!productoActivo) {
            Swal.fire({ icon:'info', title:'Selecciona un producto por peso', confirmButtonColor:'#f97316' });
            return;
        }
        if (!ultimaLectura || !ultimaLectura.connected) {
            Swal.fire({ icon:'warning', title:'Báscula sin conexión', text:'Inicia el servicio local de hardware.', confirmButtonColor:'#f97316' });
            return;
        }
        aplicarPeso(ultimaLectura.weightKg, false);
    };

    window.tararBascula = async function () {
        try {
            cambiarEstado('Enviando tara...');
            await solicitar('/bascula/tara', { method: 'POST' });
            historial = [];
            pesoAplicado = null;
            cambiarEstado('Báscula conectada', 'conectada');
        } catch (error) {
            cambiarEstado('Error de tara', 'error');
            Swal.fire({ icon:'error', title:'No se pudo tarar', text:error.message, confirmButtonColor:'#f97316' });
        }
    };

    function lecturaEstable(peso, estableServicio) {
        const requerido = Math.max(1, Number(config.lecturasEstables || 3));
        const variacion = Math.max(0, Number(config.variacionEstableKg || 0.003));
        historial.push(peso);
        if (historial.length > requerido) historial.shift();
        if (historial.length < requerido) return false;
        const min = Math.min(...historial);
        const max = Math.max(...historial);
        return (estableServicio !== false) && (max - min <= variacion);
    }

    async function consultarPeso() {
        if (consultando || !config.activo) return;
        consultando = true;
        try {
            const datos = await solicitar('/bascula/peso');
            ultimaLectura = datos;
            const peso = Math.max(0, Number(datos.weightKg || 0));
            const estable = lecturaEstable(peso, datos.stable);
            pesoValor.textContent = formatearPeso(peso);
            pesoDetalle.textContent = estable ? 'Peso estable' : 'Esperando estabilidad...';
            cambiarEstado(datos.simulation ? 'Simulación activa' : 'Báscula conectada', 'conectada');

            if (
                productoActivo
                && config.autoCaptura
                && estable
                && peso >= Number(config.pesoMinimoKg || 0.005)
            ) {
                aplicarPeso(peso, true);
            }
        } catch (error) {
            ultimaLectura = null;
            historial = [];
            cambiarEstado('Servicio local desconectado', 'error');
            pesoDetalle.textContent = 'Ejecuta hardware_local/iniciar_hardware.bat';
        } finally {
            consultando = false;
        }
    }

    function iniciar() {
        if (timer || !config.activo) return;
        consultarPeso();
        timer = window.setInterval(consultarPeso, Math.max(200, Number(config.intervaloMs || 350)));
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden && timer) {
            clearInterval(timer); timer = null;
        } else if (!document.hidden) {
            iniciar();
        }
    });

    iniciar();
})();
