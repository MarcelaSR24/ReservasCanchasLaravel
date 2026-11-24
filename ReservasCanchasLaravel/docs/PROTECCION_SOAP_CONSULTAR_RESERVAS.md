# Protección de `consultarReservas()` por rol

## Flujo de acceso

- Administrador y recepcionista usan su JWT normal y deben seguir presentando WS-Security UsernameToken en la solicitud SOAP.
- El cajero solo obtiene una credencial temporal al pasar por la ruta autenticada `api.cobros.consultar`. Esa ruta de cobros todavía no está implementada; no existe un endpoint de emisión de credenciales.
- La credencial temporal es un JWT HS256 separado, vinculado al ID en `sub`, con `token_use=soap_capability`, alcance `soap:consultarReservas`, audiencia `urn:ReservasCanchas#consultarReservas`, `jti` UUID y expiración de 30 segundos.
- El endpoint SOAP conserva su UsernameToken. El token temporal se envía en el argumento existente `token` de `consultarReservas()` y no se devuelve al cliente REST.
- SOAP carga el usuario desde `users` y comprueba el rol actual. El JWT normal del cajero no contiene el marcador, alcance y audiencia, y queda denegado. El encargado de inventario también queda denegado.
- Los JWT con marcadores de credencial temporal se rechazan en `jwt.auth` REST y en las operaciones SOAP existentes distintas de `consultarReservas()`. Esto impide que una credencial con alcance SOAP se reutilice como token de sesión REST o para otra operación SOAP.

## Consumo único y almacenamiento

La autorización temporal se consume antes de leer las reservas con `Cache::add()` y una clave derivada del `jti`. El TTL de la entrada es el tiempo restante hasta el `exp` del JWT. Si ya existe la clave o el JWT expiró, la llamada se rechaza. No se usa una secuencia separada `has()` y `put()`.

El entorno local utiliza el cache `file`. Laravel bloquea el archivo durante `add()`, lo que serializa consumidores concurrentes en procesos que comparten el mismo directorio de cache. Todos los procesos SOAP deben compartir `storage/framework/cache/data` en el mismo sistema de archivos. Servidores con discos locales distintos no comparten esta garantía; en ese despliegue se requiere un almacén compartido con inserción atómica, por ejemplo Redis.

Las pruebas de concurrencia usan dos procesos PHP y un almacén de archivos aislado; no dependen del cache `array` que PHPUnit usa para otras pruebas.

## Integración pendiente

La emisión exige el nombre de ruta `api.cobros.consultar`, el usuario resuelto por `jwt.auth`, sus claims verificados y un rol `cajero`. El flujo de cobros deberá llamar al servicio SOAP interno y no incluir la credencial en su respuesta ni en registros. La operación REST `consultarCobro` todavía no se implementa en esta fase.
