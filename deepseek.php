<?php

function consumirAPIDeepSeek($mensaje, $apiKey, $opciones = []) {
    // Configuración por defecto
    $configuracionPorDefecto = [
        'modelo' => 'deepseek-chat',
        'max_tokens' => 4096,
        'temperatura' => 0.7,
        'url' => 'https://api.deepseek.com/v1/chat/completions'
    ];
    
    // Fusionar opciones con configuración por defecto
    $configuracion = array_merge($configuracionPorDefecto, $opciones);
    
    // Validar parámetros esenciales
    if (empty($mensaje) || empty($apiKey)) {
        return [
            'exito' => false,
            'error' => 'Mensaje y API key son requeridos',
            'datos' => null
        ];
    }
    
    // Construir el payload de la solicitud
    $construirPayload = function($mensajeUser, $config) {
        return [
            'model' => $config['modelo'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $mensajeUser
                ]
            ],
            'max_tokens' => $config['max_tokens'],
            'temperature' => $config['temperatura'],
            'stream' => false
        ];
    };
    
    // Configurar los headers de la solicitud
    $construirHeaders = function($apiKey) {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json'
        ];
    };
    
    // Realizar la solicitud HTTP
    $realizarSolicitud = function($url, $headers, $payload) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $respuesta = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        return [
            'respuesta' => $respuesta,
            'codigo_http' => $httpCode,
            'error' => $error
        ];
    };
    
    // Procesar la respuesta
    $procesarRespuesta = function($resultadoSolicitud) {
        if ($resultadoSolicitud['error']) {
            return [
                'exito' => false,
                'error' => 'Error cURL: ' . $resultadoSolicitud['error'],
                'datos' => null
            ];
        }
        
        if ($resultadoSolicitud['codigo_http'] !== 200) {
            return [
                'exito' => false,
                'error' => 'Error HTTP: ' . $resultadoSolicitud['codigo_http'],
                'respuesta_cruda' => $resultadoSolicitud['respuesta'],
                'datos' => null
            ];
        }
        
        $datos = json_decode($resultadoSolicitud['respuesta'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'exito' => false,
                'error' => 'Error decodificando JSON: ' . json_last_error_msg(),
                'respuesta_cruda' => $resultadoSolicitud['respuesta'],
                'datos' => null
            ];
        }
        
        return [
            'exito' => true,
            'error' => null,
            'datos' => $datos,
            'respuesta' => $datos['choices'][0]['message']['content'] ?? null,
            'uso_tokens' => $datos['usage'] ?? null
        ];
    };
    
    // Ejecutar el flujo completo
    $payload = $construirPayload($mensaje, $configuracion);
    $headers = $construirHeaders($apiKey);
    $resultadoSolicitud = $realizarSolicitud($configuracion['url'], $headers, $payload);
    
    return $procesarRespuesta($resultadoSolicitud);
}

// Función de ejemplo de uso
function ejemploUsoAPI() {
    // Reemplaza con tu API key real
    $apiKey = 'tu api_key_aqui';
        
    $mensaje = "Hola, ¿puedes explicarme qué es la inteligencia artificial?";
    $mensaje = '**Rol:** Actúa como un motor avanzado de Procesamiento de Lenguaje Natural (PLN) y Extracción de Información (IE), especializado en procesar solicitudes de clientes para la generación de facturas, con validación incorporada según normativa ecuatoriana implícita. **Tarea Principal:** Analizar el texto proporcionado por el cliente. Extraer la información relevante para la facturación, validar los datos según las reglas especificadas y devolver el resultado estructurado en formato JSON. Si `{{TEXTO_CLIENTE}}` es: “””actura a: Angel Amores con ci: 1802498756 con email: yo@angelamores.om y dirección: Pinllo Productos: 1 Firma electrónica para un año en 20 mas iva, 1 Plan mini de 120 documentos al año en 18 mas iva “”” **Proceso Detallado a Seguir:** 1. **Extracción de Datos:** Intenta identificar y extraer los siguientes campos del `{{TEXTO_CLIENTE}}`: * Nombre del cliente * Número de Identificación (Cédula o RUC) * Email del cliente * Dirección del cliente * Descripción del Producto/Servicio principal * Precio Unitario del producto/servicio * Cantidad (si se especifica) * Indicación sobre IVA 2. **Validación de Datos:** * **Campos Obligatorios:** Verifica la presencia de: * `nombre_cliente` * `numero_identificacion` * `descripcion_producto` * `precio_unitario` * **Validación de Cantidad:** Si no se extrae explícitamente una cantidad para el producto/servicio principal, se asume `1`. Si se coloca la cantidad en letras antes del producto o servicio como: “un borrado” o “una calculadora” o “dos mouse” o “una docena de marcadores” la salida deberá ser: {cantidad=1, descripcion =“borrado”} o {cantidad=1, descripción= “calculadora”} o {cantidad=2, descripcion =“mouse”} o {cantidad=1, descripcion=“docena de marcadores”}. * **Validación de IVA regla 1:** Si no se menciona explícitamente el IVA, pero se da un precio "base" (ej. "en 30"), y se añade "más IVA", aplica_iva es true. * **Validación de IVA regla 2:** Si solo se da un precio final e indica "IVA incluido", desglosa el precio dado con la siguiente formula {precio/1.15} y aplica_iva será true. * **Validación de IVA regla 3:** Si se menciona IVA “cero” o “0” o “exento” o no “aplica IVA” aplica_iva será false, si no se menciona y no hay "más IVA" o “IVA incluido”, asume false. * **Validación de IVA regla 4:** Si no hay mención explícita de IVA, considera aplica_iva: true como un comportamiento predeterminado para facturas, a menos que se indique lo contrario. * **Prioriza la extracción precisa**. * **Aplica rigurosamente las reglas de validación**. * **Asegúrate de que la salida JSON sea estrictamente conforme a la estructura especificada para éxito o error**. 3. **Generación de Salida JSON:** * **Si todas las validaciones son exitosas:** Devuelve un JSON con los datos extraídos. * **Si faltan campos obligatorios o hay errores de validación:** Devuelve un JSON indicando los errores. Procesa el {{TEXTO_CLIENTE}} según estas directrices. **Formato de Salida JSON (Éxito):** La estructura debe ser la siguiente. Asegúrate de que los tipos de datos sean correctos (strings, números, booleanos). **json { "estado_procesamiento": "exitoso", "datos_factura": { "cliente": { "nombre": "String", "identificacion": { "tipo": "String (CI o RUC)", "numero": "String" }, "email": "String (o null si no se encuentra)", "direccion": "String (o Ambato si no se encuentra)" }, "items": [ { "descripcion": "String", "precio_unitario": Number, // Float o Integer "cantidad": Number, // Integer "aplica_iva": Boolean // true o false } // Podría extenderse a múltiples ítems si el sistema lo soporta en el futuro ] } } { "estado_procesamiento": "error", "mensajes_error": [ "String con descripción del error 1", "String con descripción del error 2" // etc. ], "campos_faltantes_obligatorios": [ "String con nombre del campo faltante 1", "String con nombre del campo faltante 2" // etc., si aplica ], "datos_parciales_extraidos": { // Opcional: incluir aquí los datos que sí se pudieron extraer antes del error, // para facilitar la corrección por parte del usuario. // Por ejemplo: // "nombre_cliente": "Angel Amores", // "numero_identificacion_original": "180249875", // El CI incorrecto // "descripcion_producto": "firma electrónica para dos años" } } { "estado_procesamiento": "exitoso", "datos_factura": { "cliente": { "nombre": "Angel Amores", "identificacion": { "tipo": "CI", "numero": "1802498756" }, "email": "yo@angelamores.com", "direccion": "Ambato" }, "items": [ { "descripcion": "firma electrónica para dos años", "precio_unitario": 30, "cantidad": 1, "aplica_iva": true } ] } } { "estado_procesamiento": "error", "mensajes_error": [ "El número de identificación `010203040` no cumple con el formato de CI (10 dígitos) o RUC (13 dígitos).", "El campo obligatorio `nombre_cliente` no fue encontrado o no pudo ser inferido claramente." ], "campos_faltantes_obligatorios": [ "nombre_cliente" // Asumiendo que `Luis` no es suficiente para el sistema como nombre completo. // Si el sistema considera `Luis` válido, este campo no estaría aquí. ], "datos_parciales_extraidos": { "numero_identificacion_original": "010203040", "descripcion_producto": "un teclado", "precio_unitario": 20, "aplica_iva": true } }';
    
    $resultado = consumirAPIDeepSeek($mensaje, $apiKey, [
        'max_tokens' => 1024,
        'temperatura' => 0.8
    ]);
    
    if ($resultado['exito']) {
        echo "✅ Respuesta exitosa:\n";
        echo "Respuesta: " . $resultado['respuesta'] . "\n";
        echo "Tokens usados: " . ($resultado['uso_tokens']['total_tokens'] ?? 'N/A') . "\n";
    } else {
        echo "❌ Error: " . $resultado['error'] . "\n";
        
        if (isset($resultado['respuesta_cruda'])) {
            echo "Respuesta cruda: " . $resultado['respuesta_cruda'] . "\n";
        }
    }
    
    return $resultado;
}

// Función para probar con diferentes escenarios
function probarAPI() {
    $testCases = [
        [
            'mensaje' => 'Hola, ¿cómo estás?',
            'apiKey' => 'test_key',
            'esperado' => 'error' // Debería fallar por API key inválida
        ],
        [
            'mensaje' => '',
            'apiKey' => 'test_key', 
            'esperado' => 'error' // Debería fallar por mensaje vacío
        ]
    ];
    
    foreach ($testCases as $test) {
        echo "Probando: '{$test['mensaje']}'\n";
        $resultado = consumirAPIDeepSeek($test['mensaje'], $test['apiKey']);
        
        if ($resultado['exito'] && $test['esperado'] === 'exito') {
            echo "✓ Test pasado\n";
        } elseif (!$resultado['exito'] && $test['esperado'] === 'error') {
            echo "✓ Test de error pasado\n";
        } else {
            echo "✗ Test fallado\n";
        }
        
        echo "---\n";
    }
}

// Ejemplo de uso básico
function usoBasico() {
    $apiKey = 'sk-5eda371bef4d413f830884d26ff750b2'; // Reemplazar con tu key
    
    $resultado = consumirAPIDeepSeek(
        "Explica la teoría de la relatividad en términos simples",
        $apiKey
    );
    
    if ($resultado['exito']) {
        return $resultado['respuesta'];
    }
    
    return "Error: " . $resultado['error'];
}

// Descomenta la siguiente línea para probar (después de configurar tu API key)
ejemploUsoAPI();

?>