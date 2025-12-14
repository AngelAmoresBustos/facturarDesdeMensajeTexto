<?php
error_reporting(E_ERROR | E_PARSE);

include_once("../controller/start.php");
include_once("facturar.php");

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $textoCliente = file_get_contents('php://input');
    $requestData = json_decode($textoCliente, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status'=>'error','response'=>'JSON inválido: '.json_last_error_msg()]);
        exit;
    }

    if (!isset($requestData['prompt']) || empty(trim($requestData['prompt']))) {
        http_response_code(400); 
        echo json_encode(['status'=>'error', 'response' => 'Falta el parámetro --PROMPT-- o está vacío en la solicitud.']);
        exit;
    }

    $prompt = trim($requestData['prompt']);
    if(strtolower(substr($prompt, 0, 5)) === 'anula'){ 
        include_once("../tools/funciones.php");
        echo json_encode(anularFactura2($prompt));
        die();
    }

    $result = iniciar_proceso_facturacion($prompt);

    if ($result['status']) {
        http_response_code(200); // OK
        echo json_encode($result);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['status'=> false,'response' => 'Error al procesar la solicitud: ' . $result]);
        registrar_error('Error al procesar la solicitud', ['response' => $result]);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status'=> false,'response' => 'Método no permitido. Solo se aceptan solicitudes POST.']);
}
?>