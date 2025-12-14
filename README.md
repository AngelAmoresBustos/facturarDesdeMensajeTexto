# 📄 Facturación electrónica por mensaje de texto (Arquitectura optimizada)

Este módulo implementa un **flujo completo de facturación electrónica** a partir de un **mensaje de texto**, integrando inteligencia artificial, procesamiento estructurado y generación de comprobantes electrónicos, con un enfoque en **rendimiento, mantenibilidad e integridad de datos**.

El sistema está diseñado para operar dentro de un **SAAS de facturación electrónica**, en entornos de hosting compartido, priorizando eficiencia y simplicidad arquitectónica.

---

## 🎯 Objetivo

Permitir que un usuario genere una factura electrónica escribiendo un mensaje de texto desde:

- el **chat interno de la aplicación**
- o un canal de mensajería externo(Telegram)

Ejemplo conceptual de mensaje:

> “Factura a Juan Pérez por 2 licencias anuales a 10 mas IVA y 1 servicio de soporte por 20 mas IVA”

---

## 🧠 Principios de diseño aplicados

- **Un solo endpoint público (HTTPS)**
- **Una sola respuesta JSON**
- Eliminación total de:
  - llamadas internas vía `cURL`
  - múltiples APIs internas
  - respuestas acumuladas o mezcladas
- Uso exclusivo de **funciones PHP internas** para procesos de negocio
- Separación clara entre:
  - entrada (API)
  - lógica de negocio
  - salida (JSON)

---

## 🏗️ Arquitectura general


---

## 🗂️ Estructura de archivos

/app
└── facturar_ai/
├── api_chat.php # Endpoint HTTPS público
├── chat_api_ai.php # Endpoint HTTPS público
├── facturar.php # Lógica completa de facturación
├── conexion.php # Conexión a base de datos (mysqli)
└── tools.php # Funciones utilitarias compartidas


---

## 📁 Descripción de archivos

### 🔹 api_chat.php y chat_api_ai.php (Endpoint)

Responsabilidades:
- Recibir la solicitud HTTPS
- Validar estructura mínima del request
- Llamar a la función principal de facturación
- Convertir la respuesta a JSON
- Enviar **una única salida** al cliente

No contiene:
- lógica de negocio
- consultas SQL
- procesos largos

---

### 🔹 facturar.php (Lógica de negocio)

Contiene el **flujo completo y secuencial** del proceso:

1. Pedir a la IA que transforme el mensaje en JSON con datos para facturación
2. Interpretación del JSON generado por IA
3. Creación o validación del cliente
4. Creación o validación de productos
5. Generación de la factura
6. Control transaccional (commit / rollback)
7. Retorno de resultados como **array PHP**

Todas las funciones:
- son internas
- retornan arrays
- no hacen `echo`
- no generan salida directa

---

### 🔹 conexion.php

- Inicializa **una sola conexión mysqli**
- Se carga una única vez por request (`require_once`)
- La conexión se reutiliza en todas las funciones

Esto evita:
- múltiples conexiones
- errores por redefinición
- sobrecarga innecesaria

---

### 🔹 tools.php

Archivo de utilidades compartidas:
- validaciones
- helpers
- transformaciones
- funciones comunes

No contiene:
- lógica de negocio
- acceso directo a la API

---

## 🔄 Flujo de ejecución

1. api_chat.php recibe POST

2. Validación mínima

3. Llamar a la AI via API con mensaje de texto y devuelve JSON

4. Llama a procesar_facturacion()

5. Se ejecuta el flujo completo

6. Retorna array final

7. api_chat.php responde con JSON



✔️ **Una entrada**  
✔️ **Un proceso**  
✔️ **Una salida**

---

## 🚀 Beneficios obtenidos

- Reducción drástica de latencia
- Eliminación de sobre-ingeniería
- JSON limpio y consistente
- Código más legible y mantenible
- Mayor control del flujo y errores
- Base sólida para escalar el sistema

---

## 🧩 Estado del proyecto

- En producción
- Flujo estable
- Arquitectura simplificada
- Optimizado para hosting compartido
- Preparado para futuras extensiones

---

## ✍️ Nota final

Este diseño prioriza **claridad, eficiencia y control**, evitando soluciones innecesariamente complejas.  
La arquitectura permite evolucionar el sistema sin introducir deuda técnica ni cuellos de botella.
