<?php
// clase para validar la integridad del JSON devuelto por la AI
class ValidadorFacturaJSON {
    
    private $errores = [];
    
    /**
     * Valida el JSON completo de la factura
     * 
     * @param string $json JSON string a validar
     * @return array Array con resultado de validación
     */
    public function validarJSON($json) {
        $this->errores = [];
        // Validar que sea un JSON válido
        $datos = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errores[] = "JSON inválido: " . json_last_error_msg();
            return $this->generarRespuesta(false);
        }
        
        // Validar estructura principal
        if (!isset($datos['estado_procesamiento']) || !isset($datos['datos_factura'])) {
            $this->errores[] = "Datos incompletos: faltan campos principales";
            return $this->generarRespuesta(false);
        }
        
        // Validar datos del cliente
        $this->validarCliente($datos['datos_factura']);
        
        // Validar items
        $this->validarItems($datos['datos_factura']);
        
        return $this->generarRespuesta(empty($this->errores));
    }
    
    /**
     * Valida los datos del cliente
     * 
     * @param array $datosFactura
     */
    private function validarCliente($datosFactura) {
        if (!isset($datosFactura['cliente'])) {
            $this->errores[] = "Falta la información del cliente";
            return;
        }
        
        $cliente = $datosFactura['cliente'];
        
        // Validar nombre (obligatorio y alfanumérico)
        if (!isset($cliente['nombre']) || empty($cliente['nombre'])) {
            $this->errores[] = "El nombre del cliente es obligatorio";
        } elseif (!$this->esAlfanumerico($cliente['nombre'])) {
            $this->errores[] = "El nombre del cliente debe ser alfanumérico";
        }
        
        // Validar identificación
        if (!isset($cliente['identificacion'])) {
            $this->errores[] = "La identificación del cliente es obligatoria ";
        } else {
            $identificacion = $cliente['identificacion'];
            
            // Validar tipo de identificación
            if (!isset($identificacion['tipo']) || empty($identificacion['tipo'])) {
                $this->errores[] = "El tipo de identificación es obligatorio ";
            }
            
            // Validar número de identificación
            if (!isset($identificacion['numero']) || empty($identificacion['numero'])) {
                $this->errores[] = "El número de identificación es obligatorio ";
            }
        }
    }
    
    /**
     * Valida los items de la factura
     * 
     * @param array $datosFactura
     */
    private function validarItems($datosFactura) {
        if (!isset($datosFactura['items']) || !is_array($datosFactura['items'])) {
            $this->errores[] = "Los items son obligatorios y deben ser un array ";
            return;
        }
        
        if (empty($datosFactura['items'])) {
            $this->errores[] = "Debe haber al menos un item en la factura ";
            return;
        }
        
        foreach ($datosFactura['items'] as $index => $item) {
            $numeroItem = $index + 1;
            
            // Validar descripción (obligatorio y alfanumérico)
            if (!isset($item['descripcion']) || empty($item['descripcion'])) {
                $this->errores[] = "La descripción del item #{$numeroItem} es obligatoria";
            } elseif (!$this->esAlfanumerico($item['descripcion'])) {
                $this->errores[] = "La descripción del item #{$numeroItem} debe ser alfanumérica ";
            }
            
            // Validar precio unitario (obligatorio y numérico)
            if (!isset($item['precio_unitario'])) {
                $this->errores[] = "El precio unitario del item #{$numeroItem} es obligatorio ";
            } elseif (!is_numeric($item['precio_unitario'])) {
                $this->errores[] = "El precio unitario del item #{$numeroItem} debe ser numérico ";
            } elseif ($item['precio_unitario'] < 0) {
                $this->errores[] = "El precio unitario del item #{$numeroItem} debe ser mayor o igual a 0 ";
            }
            
            // Validar cantidad (obligatorio y numérico)
            if (!isset($item['cantidad'])) {
                $this->errores[] = "La cantidad del item #{$numeroItem} es obligatoria ";
            } elseif (!is_numeric($item['cantidad'])) {
                $this->errores[] = "La cantidad del item #{$numeroItem} debe ser numérica ";
            } elseif ($item['cantidad'] <= 0) {
                $this->errores[] = "La cantidad del item #{$numeroItem} debe ser mayor a 0 ";
            }
            
            // Validar aplica_iva (obligatorio y boolean)
            if (!isset($item['aplica_iva'])) {
                $this->errores[] = "El campo aplica_iva del item #{$numeroItem} es obligatorio ";
            } elseif (!is_bool($item['aplica_iva'])) {
                $this->errores[] = "El campo aplica_iva del item #{$numeroItem} debe ser booleano ";
            }
        }
    }
    
    /**
     * Valida si un string es alfanumérico (permite espacios y algunos caracteres especiales)
     * 
     * @param string $valor
     * @return bool
     */
    private function esAlfanumerico($valor) {
        // Permite letras, números, espacios y algunos caracteres especiales comunes
        return preg_match('/^[a-zA-Z0-9\s\-\_\.\,\@áéíóúÁÉÍÓÚ\s\-\_\.\,\@áéíóúñÑ]+$/u', $valor);
    }
    
    /**
     * Genera la respuesta de validación
     * 
     * @param bool $esValido
     * @return array
     */
    private function generarRespuesta($esValido) {
        return [
            'valido' => $esValido,
            'errores' => $this->errores,
            'total_errores' => count($this->errores)
        ];
    }
    
    /**
     * Obtiene los errores de validación
     * 
     * @return array
     */
    public function obtenerErrores() {
        return $this->errores;
    }
}
?>