<?php
error_reporting(E_ERROR | E_PARSE);
if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $textoCliente = file_get_contents('php://input');
    $requestData = json_decode($textoCliente, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status'=>'error','response'=>'JSON inválido: '.json_last_error_msg()]);
        exit;
    }

    if (!isset($requestData['prompt']) || empty(trim($requestData['prompt']))) {
        http_response_code(400); // Bad Request
        echo json_encode(['status'=>'error', 'response' => 'Falta el parámetro --PROMPT-- o está vacío en la solicitud.']);
        exit;
    }

    $prompt = trim($requestData['prompt']);

    $response = iniciar_proceso_facturación_telegram($prompt);
    if($response['status']) {
        http_response_code(200);
        echo json_encode($response);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['status'=> false,'response' => 'Error al procesar la solicitud.']);
        registrar_error('Error al procesar la solicitud', ['response' => $result]);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status'=> false,'response' => 'Método no permitido. Solo se aceptan solicitudes POST.']);
}
?>