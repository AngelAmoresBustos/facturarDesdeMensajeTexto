<?php
/**
 * File Name: facturar.php
 * Descripción: Procesa los datos de la factura y realiza la facturación
 *
 * @param array $data Pedido de facturación
 * @return array Resultado del proceso. Forma: ['status' => int, 'result' => mixed]
 **/

include_once("../config/conexion.php");
include_once("../tools/funciones.php"); 
include_once("validar_json.php");

/**
 * Procesar los datos para hacer la factura desde chat de Telegram
 * 
 * @param string mensaje de texto con datos para hacer la factura
 * @return array $result con datos del proceso ["status"=>0,"result"=>"descripción del error/exito"]
 */
function iniciar_proceso_facturacion($prompt) {
    $result = consumirAPIDeepSeek($prompt,1);
    if ($result['status']) {
        $pedido = $result['response'];
        $validador = new ValidadorFacturaJSON();
        $response = $validador->validarJSON($pedido); 
        if ($response['valido']) {
            $pedido = json_decode($pedido, true);  
            $pedido['idEmpresa'] = $_SESSION["idEmpresa"] ?? null;
            $pedido['usuario'] = $_SESSION["id"] ?? null;
            $response = procesar_datos_factura($pedido);
            if(!$response["status"]) {
                $error_msg = "Error al consumir API: " . ($response['result'] ?? 'Respuesta inválida');
                registrar_error($error_msg, ['http_code' => $response['http_code'] ?? 0 ]);
                $status = false;
                $result = "error: $error_msg";
            } else {
                $status = true;
                $result = $response['result'];
            }
        } else {
            $status = false;
            foreach ($response['errores'] as $error) {
                $result = $error;
            }
        }
        return ['status'=> $status,'response' => $result];
    } else {
        return ['status'=> false,'response' => 'Error al procesar la solicitud.'];
    }
}


/**
 * Procesar los datos para hacer la factura desde chat interno de la app
 * 
 * @param string mensaje de texto con datos para hacer la factura
 * @return array $result con datos del proceso ["status"=>0,"result"=>"descripción del error/exito"]
 */
function iniciar_proceso_facturación_telegram($prompt) {
    $result = consumirAPIDeepSeek($prompt,2);
    if ($result['status']) {
        $pedido = $result['response'];
        $validador = new ValidadorFacturaJSON();
        $response = $validador->validarJSON(json_encode($pedido));  
        if ($response['valido']) {
            $id = $pedido['datos_factura']['id'] ?? null;
            $id = (int)substr($id, - 4);
            $pedido['idEmpresa'] = $id;
            $pedido['usuario'] = $id;
            $response = procesar_datos_factura($pedido);
            if(!$response["status"]) {
                $error_msg = "Error al consumir API: " . ($response['result'] ?? 'Respuesta inválida');
                registrar_error($error_msg, ['http_code' => $response['http_code'] ?? 0 ]);
                $status = false;
                $result = "error: $error_msg";
            } else {  
                $status = true;
                $result = $response['result'];
            }
        } else {
            $status = false;
            foreach ($response['errores'] as $error) {
                $result = $error;
            }
        }
        return ['status'=> $status,'response' => $result];
    } else {
        return ['status'=> false,'response' => 'Error al procesar la solicitud: '];
    }
}


/**
 * Procesar los datos para hacer la factura
 * 
 * @param array $data Datos del pedido
 * @return array $result con datos del proceso ["status"=>0,"result"=>"descripción del error/exito"]
 */
function procesar_datos_factura($data) {
    global $conexion;
    if(!isset($data['datos_factura'])) {
        return ['status' => 0, 'result' => 'Estructura de datos inválida'];
    }
    $datos = $data['datos_factura'];
    $idEmpresa = $data['idEmpresa']; 
    $usuario = $data['usuario'];
    try {       
        mysqli_autocommit($conexion, FALSE); 
        // Crear cliente
        $cliente = procesar_cliente($datos['cliente'] ?? [], $idEmpresa, $usuario);
        $clienteRes = crear_cliente($cliente);
        if (!isset($clienteRes['status']) || $clienteRes['status'] != 1) {
            mysqli_rollback($conexion);
            return ["status"=>0,"result"=>"Error: No se puede crear cliente"];
        }
        // Procesar items
        $detalle = [];
        foreach($datos['items'] as $key => $value){
            $value['idEmpresa'] = $idEmpresa;
            $value['usuario'] = $usuario;
            $detalle[] = $value;
        }
        // Procesar item 
        $items = procesar_items($detalle ?? []);
        $resultados = [];
        foreach($items as $key => $value){
            $nuevoId = crear_productos($value);
            if($nuevoId['status'] == 0){
                mysqli_rollback($conexion);
                return ["status"=>0,"result"=>"Error desconocido al crear producto: " . $nuevoId['result']];
            }
            $value['id'] = $nuevoId['id'];
            $resultados[] = $value;
        }
        // Armar pedido
        $pedido = [
            "cliente" => [
                "id" => $clienteRes['id'],
                "nombre" => $clienteRes['nombre'],
                "correo" => $clienteRes['email']
            ],
            "idEmpresa" => $idEmpresa,
            "usuario" => $usuario,
            "productos" => $resultados
        ];
        // Facturar
        $resultado = hacer_facturar($pedido);
        if($resultado['status'] == false){
            mysqli_rollback($conexion);
        }
        mysqli_autocommit($conexion,true);
        return $resultado;
    } catch (Exception $e) {
        mysqli_rollback($conexion);
        error_log("[".date('c')."] ERROR procesar_datos_factura: ".$e->getMessage()."\n", 3, __DIR__.'/api_errors.log');
        return ['status'=>0, 'result'=>'Error interno','message'=>$e->getMessage()];
    }
}


/**
 * Procesa los datos del cliente
 * 
 * @param array $cliente_data Datos del cliente
 * @param int $idEmpresa ID de la empresa
 * @param int $usuario ID del usuario
 * @return array Cliente procesado
 */
function procesar_cliente($cliente_data, $idEmpresa, $usuario) {
    return [
        'nombre' => $cliente_data['nombre'] ?? '',
        'tipo_identificacion' => $cliente_data['identificacion']['tipo'] ?? '',
        'numero_identificacion' => $cliente_data['identificacion']['numero'] ?? '',
        'email' => $cliente_data['email'] ?? '',
        'direccion' => $cliente_data['direccion'] ?? '',
        'idEmpresa' => $idEmpresa,
        'usuario' => $usuario
    ];
}

/**
 * Consumir API de DeepSeek
 * 
 * @param string $prompt del cliente
 * @param int tipo de proceso
 * @return array Respuesta de la API decodificada
 */
function crear_cliente($cliente){
    global $conexion;

    $idEmpresa = $cliente['idEmpresa'];
    $usuario = $cliente['usuario'];
    $documento = $cliente['numero_identificacion'];
    $documento = trim($documento);
    $razonsocial = $cliente['nombre'];
    $razonsocial = trim($razonsocial);
    $direccion = $cliente['direccion'] ?? 'Ambato';
    
    $correo = $cliente['email'] ?? buscarId("Select correo From empresa Where idempresa='$idEmpresa'","correo");
    
    if($razonsocial == "" || $documento == "" || $idEmpresa == "" || $usuario == ""){
        return ["status"=>0, "result"=>"Error: Datos incompletos"];
    }

    $id = buscarId("Select id From clientes Where documento='$documento' And idempresa='$idEmpresa'","id");
    if($id != 0){
        return ["status"=>1, "id"=>$id, "nombre"=>$razonsocial, "email"=>$correo];
    }

    if(strlen($documento) == 10) $tipodocumento="05";
    if(strlen($documento) == 13) $tipodocumento="04";
    if(trim($documento) == "9999999999999") $tipodocumento="07";

    $nombrecomercial = $razonsocial;
    $contacto = "";
    $tipocliente = buscarId("Select id From categoriacliente Where nombre='Consumidor Final' And idempresa='$idEmpresa'","id");
    $latitud = 0;
    $longitud = -78.455830;
    $ciudad = "Ambato";
    $telefono = "";
    $celular = "";
    $provincia = "";
    $plazo = 0;
    $vendedor = buscarId("Select id From vendedores Where idempresa='$idEmpresa'","id");
    $zona = buscarId("Select id From zonas Where idempresa='$idEmpresa'","id");
    $frecuencia = 30;
    $observaciones = "";
    $ruta = true;  
    $dia = 1;
    $semana = 1;
    $visita = hoyDia();
    $fechainicio = hoyDia();

    $query = "insert into clientes  
        (tipodocumento,  documento,      razonsocial,  nombrecomercial,  contacto,   tipocliente,  latitud,   longitud,  direccion, 
        telefono,        celular,        correo,       ciudad,           provincia,  plazo,        vendedor,  zona,      frecuencia,
        fechainicio,     observaciones,  usuario,      ruta,             dia,        semana,       visita,    idempresa)
    values(
        '$tipodocumento', '$documento',       '$razonsocial',  '$nombrecomercial', '$contacto',  '$tipocliente',  '$latitud',  '$longitud',  '$direccion', 
        '$telefono',      '$celular',         '$correo',       '$ciudad',          '$provincia', '$plazo',        '$vendedor', '$zona',      '$frecuencia',
        '$fechainicio',  '$observaciones',    '$usuario',      '$ruta',            '$dia',       '$semana',       '$visita',   '$idEmpresa' )";
    
    if(mysqli_query($conexion, $query)){
        $id = buscarId("Select id From clientes Where idempresa='$idEmpresa' order by id desc limit 1","id");
        return ["status"=>1, "id"=>$id, "nombre"=>$razonsocial, "email"=>$correo];
    } else {
        return ["status"=>0, "result"=>"Error al crear cliente: " . mysqli_error($conexion)];
    } 
}


/**
 * Procesa los items de la factura
 * 
 * @param array $items_data Array de items
 * @return array Items procesados
 */
function procesar_items($items_data) {
    return array_map('procesar_item', $items_data);
}


/**
 * Procesa un item individual
 * 
 * @param array $item_data Datos del item
 * @return array Item procesado
 */
function procesar_item($item_data) {
    $descripcion = $item_data['descripcion'] ?? '';
    $precio_unitario = floatval($item_data['precio_unitario'] ?? 0);
    $cantidad = intval($item_data['cantidad'] ?? 0);
    $aplica_iva = boolval($item_data['aplica_iva'] ?? false);
    $idEmpresa = intval($item_data['idEmpresa'] ?? 0);
    $usuario = intval($item_data['usuario'] ?? 0);
    
    return [
        'descripcion' => $descripcion,
        'precio_unitario' => $precio_unitario,
        'cantidad' => $cantidad,
        'aplica_iva' => $aplica_iva,
        'idEmpresa' => $idEmpresa,
        'usuario' => $usuario
    ];
}


/**
 * Crear producto
 * 
 * @param array $item_data Datos del producto
 * @return int id del producto
 */
function crear_productos($item_data) {
    global $conexion;

    $nombre = $item_data["descripcion"];
    $idEmpresa = $item_data['idEmpresa'];
    $usuario = $item_data['usuario'];    
    $precio1 = $item_data['precio_unitario'];
    $codigo = $item_data['codigo'] ?? "";

    if($nombre == "" || $idEmpresa == "" || $usuario == ""){
        return ["status"=>0, "result"=>"Datos incompletos!"];        
    }

    $iva = $item_data['aplica_iva'] ? buscarId("SELECT iva FROM parametros WHERE idempresa='$idEmpresa'", "iva") : 0; // Porcentaje de IVA
    $codigodebarras = "";
    $descripcion = "";
    $presentacion = "";
    $marca = "";
    $costo = 0;
    $existencia = 0;
    $utilidad1 = 0;
    $utilidad2 = 0;
    $precio2 = 0;
    $utilidad3 = 0;
    $precio3 = 0;
    $categoria = buscarId("Select id From categoriaproducto Where idempresa='$idEmpresa'","id");
    $familia = 0;
    $unidaddemedida = "UNIDAD";
    $existenciaminima = 0;
    $existenciamaxima = 0;
    $ubicacion = "";
    $observaciones = "";
    $imagen = "images/SinFoto.png";
    $imagenmarca = "";
    $tipo = "";

    //para crear nuevo registro
    $query  = "Insert Into productos(
        codigo,         nombre,          codigodebarras,    descripcion, presentacion,  marca,   imagen,    costo,       existencia, 
        utilidad1,      precio1,          utilidad2,        precio2,     utilidad3,     precio3, categoria, familia,     iva, 
        unidaddemedida, existenciaminima, existenciamaxima, ubicacion,   observaciones, tipo,    usuario,   imagenmarca, idempresa)
        Values(
        '$codigo',         '$nombre',           '$codigodebarras',   '$descripcion', '$presentacion',  '$marca',   '$imagen',    '$costo',       '$existencia',
        '$utilidad1',      '$precio1',          '$utilidad2',        '$precio2',     '$utilidad3',     '$precio3', '$categoria', '$familia',     '$iva',
        '$unidaddemedida', '$existenciaminima', '$existenciamaxima', '$ubicacion',   '$observaciones', '$tipo',    '$usuario',   '$imagenmarca', '$idEmpresa')";

    $resultado = mysqli_query($conexion, $query); 
    if(!$resultado){
        return ["status"=>0,"result"=>"Error " . mysqli_error($conexion)];
    }

    $id = buscarId("Select id From productos Where idempresa='$idEmpresa' order by id desc limit 1","id");
    if(empty($_POST['codigo'])){
        mysqli_query($conexion, "Update productos Set codigo='$id' Where id='$id'");
    }

    return ["status"=>1, "id"=>$id];  
}


/**
 * Crear producto
 * 
 * @param array $pedido Datos del pedido
 * @return array resultado del proceso ['status' => bool, 'result' => mixed]
 */
function hacer_facturar($pedido){
    global $conexion;

    $cliente = $pedido["cliente"];
    $items = $pedido["productos"];
    $idEmpresa = $pedido['idEmpresa'];
    $usuario = $pedido['usuario'];

    $plazo = 0;
    $idcliente = $cliente["id"];

    $date = new DateTime("now", new DateTimeZone('America/Guayaquil'));
    $hoy = $date->format('Y-m-d');
    $dia = $date->format('d');
    $mes = $date->format('m');
    $anio = $date->format('Y');

    $tipodoc = "01"; // 01 ) Factura.
    $ruc = buscarId("Select ruc From empresa Where idempresa='$idEmpresa'","ruc");

    $cParametros = json_decode(dameParametros($idEmpresa));
    $ambiente = $cParametros->ambiente;
    $emision = $cParametros->emision;
    $establecimiento = ponerCeros($cParametros->establecimiento,3);
    $puntodeemision = ponerCeros($cParametros->puntodeemision,3);
    $secuencial = ponerCeros($cParametros->factura);
    $inventario = $cParametros->inventario;
    $iva = $cParametros->iva;

    $clave = "12345678";
    $claveacceso = $dia.$mes.$anio.$tipodoc.$ruc.$ambiente.$establecimiento.$puntodeemision.$secuencial.$clave.$emision;
    $claveacceso .= digitoVerificador($claveacceso);

    //Nombre de archivos para enviar al cliente pdf y xml del resente documento
    $home = "suscriptores/".$idEmpresa."/sri/";
    $ticket = $home . "ticket/" . $claveacceso . ".pdf";
    $factura = $home . 'facturas/ride/' . $claveacceso . '.pdf';
    $pdf = ["ticket" => $ticket, "factura" => $factura];

    //Variables para operaciones
    $subtotal = 0;
    $subtotalsiniva = 0;
    $subtotalconiva = 0;
    $totaldescuento = 0;
    $descuento = 0;
    $x = 0;
    $y = 0;
    $ordendecompra = "";
    $idformadepago = "20";
    $idvendedor = buscarId("Select id From vendedores Where idempresa='$idEmpresa'","id"); 

    //para cuando no existe stock en los productos del pedido y se escoja enviar parcial
    if($inventario == "SI"){
        $sinExistencia = verificarexistencias2($pedido);
        if(count($sinExistencia) > 1){
            return ["status" => false, "result" => 100];
        }
    }

    $hora = horaActual();
    try {
            $query = "Insert Into factura(
            establecimiento, puntodeemision, secuencial,     fechadeemision, plazo,    vendedor,      cliente,   subtotal, subtotalsiniva,
            subtotalconiva,  iva,            descuento, ambiente, claveacceso,
            estado,          formadepago,    usuario,        latitud,        longitud, ordendecompra, hora,      idempresa) 
            Values(
            '$establecimiento', '$puntodeemision', '$secuencial',     '$hoy', '$plazo', '$idvendedor',   '$idcliente', '$subtotal', '$subtotalsiniva',
            '$subtotalconiva',  '$iva',            '$descuento', '$ambiente', '$claveacceso',
            'PROCESADA',       '$idformadepago',   '$usuario',        '$x',   '$y',     '$ordendecompra','$hora',      '$idEmpresa')";
            // Ejecutar consulta
            $result = mysqli_query($conexion,$query);
            if(!$result){
                return ["status" => false, "result" => "No se pudo generar la factura. Error: $establecimiento : " . mysqli_error($conexion)];
            }       
            
            $idFactura = buscarId("Select id From factura Where idempresa='$idEmpresa' order by id desc limit 1","id");  
            
            $query ="Update parametros Set factura = factura + 1 Where idempresa='$idEmpresa'";
            $result = mysqli_query($conexion,$query);
            if(!$result){
                return ["status" => false, "result" => "No se pudo generar la factura. Error: " . mysqli_error($conexion)];
            } 

            foreach($items as $item){
                $id = $item["id"];
                $producto = $item["descripcion"];
                $cantidad = $item["cantidad"];
                $precio = $item["precio_unitario"];
                $descuento = 0;
                $iva = $item["aplica_iva"] ? $cParametros->iva : 0;
                if($inventario == "SI"){
                    actualizaexistencia($id,$cantidad);
                    actualizaKardexOut($id,$cantidad,$precio);
                }
                
                $lote = "";
                $costo = buscarId("Select costo From productos Where id='$id'","costo");
                $query  = "Insert Into detallefactura(
                            idfactura, idproducto, detalle, lote, cantidad, precio, costo, descuento, iva, idempresa) 
                            Values(
                            '$idFactura', '$id', '$producto', '$lote', '$cantidad', '$precio', '$costo', '$descuento', '$iva', '$idEmpresa')";

                $result = mysqli_query($conexion,$query);
                if(!$result){
                    return ["status" => false, "result" => "No se pudo generar la factura. Error: " . mysqli_error($conexion)];
                }
                $xTotal = ($precio * $cantidad);
                $subtotal += $xTotal;
                $totaldescuento += $descuento;
                $iva == 0 ? $subtotalsiniva += $xTotal - $descuento : $subtotalconiva += $xTotal - $descuento;
            } 
            
            $query = "Update factura Set subtotal='$subtotal',subtotalconiva='$subtotalconiva',subtotalsiniva='$subtotalsiniva',descuento='$totaldescuento' where idempresa='$idEmpresa' And id = '$idFactura'";
            $result = mysqli_query($conexion,$query);
            if(!$result){
                return ["status" => false, "result" => "No se pudo generar la factura. Error: " . mysqli_error($conexion)];
            }
            
            $total = $subtotal + (($subtotalconiva * $iva)/100);
            $query = "update clientes Set saldo = saldo + '$total', visitado = true Where idempresa='$idEmpresa' And id = '$idcliente'";
            $result = mysqli_query($conexion,$query);
    } catch(Exception $e){
        return ["status" => false, "result" => "No se pudo generar la factura. Error: " . $e->getMessage()]; 
    } // fin try

    reenviarDocSRI($idFactura, $tipodoc); 

    $documentoimprimir = buscarId("Select documentoimprimir From parametros Where idempresa=$idEmpresa","documentoimprimir");
    $documentoimprimir = $documentoimprimir == "0" ? "factura" : $documentoimprimir;
    return ["status" => true, "result" => $pdf[$documentoimprimir]]; 
}


/**
 * Consumir API de DeepSeek
 * 
 * @param string $prompt del cliente
 * @param int tipo de proceso
 * @return array Respuesta de la API decodificada
 */
function consumirAPIDeepSeek($prompt,$proceso = 1) {
    if($proceso == 1) {
        include_once 'promptsystem.php';
    } else {
        include_once 'promptsystem2.php';
    }

    // Validar parámetros esenciales
    if (empty($prompt) || empty($apiKey_DS)) {
        return [
            'status' => false,
            'response' => 'Mensaje y API key son requeridos'
        ];
    }

    // $solicitud = $rol .' '. $tarea .' '. $texto_cliente . $prompt.' “””'.' '. $proceso .' '. $formatoSalida;
    $solicitud = $rol .' '. $tarea .' '. $texto_cliente . $prompt.' “””'.' '. $proceso .' '. $formatoSalida;

    // Configuración por defecto
    $configuracion = [
        'modelo' => 'deepseek-chat',
        'max_tokens' => 4096,
        'temperatura' => 0.7,
        'url' => 'https://api.deepseek.com/v1/chat/completions'
    ];
    
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
                'status' => false,
                'response' => 'Error cURL: ' . $resultadoSolicitud['error']
            ];
        }
        
        if ($resultadoSolicitud['codigo_http'] !== 200) {
            return [
                'status' => false,
                'response' => 'Error HTTP: ' . $resultadoSolicitud['codigo_http'],
                'respuesta_cruda' => $resultadoSolicitud['respuesta']
            ];
        }
        
        $datos = json_decode($resultadoSolicitud['respuesta'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => false,
                'response' => 'Error decodificando JSON: ' . json_last_error_msg(),
                'respuesta_cruda' => $resultadoSolicitud['respuesta']
            ];
        }

        $responseText = $datos['choices'][0]['message']['content'];
        $responseText = str_replace("```", "", $responseText);
        $responseText = str_replace("json", "", $responseText);
        $responseText = trim($responseText);

        return [
            'status' => true,
            'response' => $responseText ?? null
        ];
    };
    
    // Ejecutar el flujo completo
    $payload = $construirPayload($solicitud, $configuracion);
    $headers = $construirHeaders($apiKey_DS);
    $resultadoSolicitud = $realizarSolicitud($configuracion['url'], $headers, $payload);
    $result = $procesarRespuesta($resultadoSolicitud);
    return $result;
}


/**
 * Registra errores en un archivo de log
 * 
 * @param string $mensaje Mensaje de error
 * @param array $contexto Contexto adicional
 */
function registrar_error($mensaje, $contexto = []) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] ERROR: $mensaje";
    
    if (!empty($contexto)) {
        $log_entry .= " | Contexto: " . json_encode($contexto);
    }
    
    $log_entry .= PHP_EOL;
    
    error_log($log_entry, 3, 'api_errors.log');
}