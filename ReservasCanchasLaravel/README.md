# ReservasCanchasLaravel

Aplicación Laravel para administrar canchas y reservas mediante un servicio SOAP. Incluye registro e inicio de sesión, tokens JWT HS256 y protección de las operaciones SOAP mediante JWT y WS-Security UsernameToken.

## Tecnologías

- PHP 8.3 y Laravel 13.
- MySQL 8 o compatible.
- NuSOAP para publicar el servicio existente.
- `firebase/php-jwt` para emitir y verificar JWT con HS256.
- Extensión SOAP de PHP (`SoapClient`) para que Laravel invoque el servicio.
- SoapUI 5.10 para pruebas manuales.

## Requisitos

- Laragon con PHP 8.3, Apache y MySQL.
- Composer 2.
- Extensiones PHP `pdo_mysql`, `soap`, `dom`, `mbstring` y `openssl` habilitadas.
- SoapUI 5.10, opcional para las pruebas manuales.

En Laragon, abre **PHP > Extensions** y habilita `soap` y `pdo_mysql`. Comprueba la configuración con `php -m` y confirma que también esté habilitada para Apache.

## Instalación en Laragon

1. Descarga o clona el repositorio en `C:\laragon\www\ReservasCanchasLaravel`.
2. Abre una terminal en la carpeta del proyecto e instala las dependencias:

   ```bash
   composer install
   ```

3. Crea la configuración local y la clave de Laravel:

   ```powershell
   Copy-Item .env.example .env
   php artisan key:generate
   ```

4. Configura en `.env` la conexión MySQL (host, puerto, nombre de la base de datos, usuario y contraseña). La base `reservascanchas` debe existir y contener previamente las tablas heredadas `canchas`, `usuarios` y `reservas`, con sus relaciones y tipos de claves compatibles. En particular, `reservas.cancha_id` debe referenciar `canchas.id`, `reservas.usuario_id` debe referenciar `usuarios.id` y `reservas.id` debe ser compatible con la clave foránea que agregará Cobros. Las migraciones del proyecto no crean ni reemplazan estas tablas heredadas.
5. Una vez configurados `.env` y la estructura heredada, ejecuta las migraciones Laravel:

   ```bash
   php artisan migrate
   ```

   Las migraciones aplican los cambios pendientes del proyecto, incluido agregar `role` a `users` y crear las tablas `cobros`, `implementos` y `movimientos_inventario`. La tabla heredada `reservas` debe existir antes de aplicar la migración de cobros; se agrega su clave foránea sin modificar esa tabla.

   Antes de aplicar migraciones a una base existente, realiza un respaldo y ejecuta únicamente `php artisan migrate`. No ejecutes `php artisan migrate:fresh`.

## Variables de entorno

`.env` contiene la configuración privada local y no se incluye en Git ni en el ZIP. No reutilices `APP_KEY` como secreto JWT.

Genera un secreto JWT independiente de 32 bytes o más:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Copia el resultado en `JWT_SECRET` dentro de `.env`. Configura además:

```dotenv
JWT_ALGORITHM=HS256
JWT_TTL=60
JWT_ISSUER=http://127.0.0.1:8000
SOAP_ENDPOINT=http://127.0.0.1:8000/soap
SOAP_SECURITY_USERNAME=soap-client
SOAP_SECURITY_PASSWORD=PEGA_UNA_CLAVE_LOCAL_ALEATORIA
```

Genera también una contraseña aleatoria para `SOAP_SECURITY_PASSWORD`; usa los mismos valores de Username y PasswordText en SoapUI. Mantén `JWT_SECRET` y `SOAP_SECURITY_PASSWORD` fuera del repositorio, capturas y entregas públicas. Si cambias variables después de cachear la configuración, ejecuta `php artisan config:clear`.

## Iniciar la aplicación

Con Apache de Laragon activo, configura el sitio para servir la carpeta `public` y usa la URL local indicada en `APP_URL`. También puedes iniciar Laravel para pruebas normales con:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

La pantalla de inicio y registro están en `/login` y `/register`. Para probar la invocación de Laravel hacia su propio endpoint SOAP desde el navegador, usa Apache de Laragon; el servidor PHP integrado es de un solo proceso en Windows y no atiende de forma concurrente esa llamada de retorno.

## Registro e inicio de sesión

El formulario web solicita nombre, correo, contraseña y confirmación. La contraseña requiere al menos 12 caracteres, mayúscula, minúscula, número y símbolo; Laravel la guarda con hashing. Se reutiliza el modelo y la tabla `users` existentes.

También están disponibles estos endpoints JSON:

### Registrar

`POST /api/register`

```json
{
  "name": "Usuario de prueba",
  "email": "prueba@example.com",
  "password": "EjemploSeguro123!",
  "password_confirmation": "EjemploSeguro123!"
}
```

### Iniciar sesión

`POST /api/login`

```json
{
  "email": "prueba@example.com",
  "password": "EjemploSeguro123!"
}
```

La respuesta válida incluye `access_token`, `token_type: Bearer` y `expires_in`. Las credenciales incorrectas no generan token. En el formulario web, el JWT queda guardado del lado del servidor en la sesión y no se imprime en la página.

Ejemplo de respuesta de registro:

```json
{
  "exito": true,
  "mensaje": "Usuario registrado correctamente. Ya puedes iniciar sesión.",
  "usuario": { "id": 1, "name": "Usuario de prueba", "email": "prueba@example.com" }
}
```

Ejemplo ilustrativo de inicio de sesión; el valor del token es un marcador y no sirve para autenticarse:

```json
{
  "exito": true,
  "token_type": "Bearer",
  "access_token": "TOKEN_GENERADO_POR_EL_LOGIN",
  "expires_in": 3600,
  "usuario": { "id": 1, "name": "Usuario de prueba", "email": "prueba@example.com" }
}
```

Si la contraseña no coincide, `/api/login` devuelve HTTP 422 sin `access_token`.

`GET /api/me` acepta el token en `Authorization: Bearer <token>`. `POST /api/logout` revoca el token actual hasta su expiración. La revocación usa la caché configurada por Laravel; ejecuta las migraciones para disponer de la tabla `cache` si usas `CACHE_STORE=database`.

## Cobros

Todas las rutas requieren `Authorization: Bearer <JWT>` además del permiso indicado. Los roles se leen de `users.role`; `users` no es la tabla de usuarios de reserva `usuarios`.

| Método y ruta | Nombre | Roles |
| --- | --- | --- |
| `POST /api/cobros` | `api.cobros.generar` | administrador, recepcionista |
| `GET /api/cobros/{cobro}` | `api.cobros.consultar` | administrador, recepcionista, cajero |
| `PATCH /api/cobros/{cobro}/estado` | `api.cobros.actualizarEstado` | administrador, cajero |

Crear un cobro requiere una reserva existente y un monto positivo. El estado y la moneda los asigna el servidor (`pendiente`, `COP`); la reserva no puede tener otro cobro. El endpoint no filtra por estado de reserva mientras siga pendiente acordar cuáles estados son elegibles.

```http
POST /api/cobros
Authorization: Bearer <JWT>
Content-Type: application/json

{"reserva_id": 123, "monto": "45000.00"}
```

Marcar pagado registra método (`efectivo`, `transferencia` o `tarjeta`) y fecha del servidor:

```http
PATCH /api/cobros/1/estado
Authorization: Bearer <JWT>
Content-Type: application/json

{"estado": "pagado", "metodo_pago": "transferencia", "referencia_transaccion": "REF-001"}
```

Solo administrador puede anular, y el servicio vuelve a comprobar ese rol. Solo se aceptan cambios desde `pendiente`; no hay flujo para pagos parciales, devoluciones ni endpoint para modificar monto. La inmutabilidad funcional del monto sigue pendiente de confirmación. Las respuestas JSON actuales usan `exito`, `mensaje` y/o `datos`; el contrato definitivo y los códigos de éxito siguen pendientes de confirmación.

Al consultar, la API invoca el SOAP interno con UsernameToken y devuelve solo la reserva vinculada al cobro. Si SOAP falla o no entrega esa reserva, el endpoint devuelve un error controlado HTTP 502. Para cajero, Laravel emite y consume internamente la capability de 30 segundos; nunca se envía esa credencial en la respuesta REST.

## Implementos e inventario

| Método y ruta | Nombre | Roles |
| --- | --- | --- |
| `GET /api/implementos` | `api.implementos.listar` | administrador, recepcionista, encargado de inventario |
| `POST /api/implementos` | `api.implementos.registrar` | administrador, encargado de inventario |
| `POST /api/implementos/{implemento}/ajustar-stock` | `api.implementos.ajustarStock` | administrador, encargado de inventario |

Ejemplo de registro:

```http
POST /api/implementos
Authorization: Bearer <JWT>
Content-Type: application/json

{"codigo": "BAL-01", "nombre": "Balón", "categoria": "deportes", "cantidad_disponible": 4}
```

El estado se permite como `activo`, `mantenimiento` o `retirado`; si se omite, comienza como `activo`. Una cantidad cero es válida y no cambia automáticamente el estado. Los códigos duplicados se rechazan y no modifican existencias.

Ejemplo de entrada o salida:

```http
POST /api/implementos/1/ajustar-stock
Authorization: Bearer <JWT>
Content-Type: application/json

{"tipo_movimiento": "salida", "cantidad": 1, "motivo": "Daño reportado"}
```

La cantidad debe ser un entero positivo. El servicio bloquea la fila del implemento dentro de una transacción y registra en `movimientos_inventario` el actor, fecha y cantidades anterior y posterior. Actualmente no se bloquean ajustes por estado del implemento; esa regla sigue pendiente. No hay rutas para editar o borrar movimientos.

## Pruebas automatizadas

Ejecuta la suite con:

```bash
php artisan test --compact
```

Las pruebas usan SQLite `:memory:` y preparan sus esquemas aislados sin ejecutar `migrate:fresh`. Cubren permisos, validación, unicidad, transiciones, flujo de capability desde la ruta de consulta, filtrado de reserva, auditoría y rollback del ajuste si falla la creación del movimiento. SQLite no permite verificar la concurrencia real de `lockForUpdate()`; esa comprobación queda para una ejecución de integración contra MySQL/InnoDB.

## Verificación manual SOAP para cobros

- Para administrador y recepcionista, SoapUI puede invocar `consultarReservas` con su JWT normal y el encabezado WS-Security UsernameToken configurado en la sección de SoapUI.
- Para cajero, usa `GET /api/cobros/{cobro}` con su JWT normal. Laravel genera la capability únicamente dentro de `api.cobros.consultar`, llama SOAP con UsernameToken y filtra por la reserva del cobro.
- No existe endpoint público para emitir capabilities. El JWT normal de cajero enviado directamente a SOAP debe ser rechazado; no copies ni expongas capabilities en SoapUI, registros o respuestas REST.
- La prueba de punta a punta necesita el WSDL y las credenciales SOAP de `.env` configurados, además de un servidor capaz de atender la llamada SOAP interna mientras procesa la petición REST. Las pruebas automatizadas mockean la conexión saliente de cobros y conservan las pruebas existentes de WS-Security y SOAP.

No se necesitan nuevas variables de entorno para Cobros e Implementos. Se reutilizan `JWT_SECRET`, `SOAP_ENDPOINT`, `SOAP_SECURITY_USERNAME` y `SOAP_SECURITY_PASSWORD`; configura valores locales y no los publiques.

## Servicio SOAP

- Endpoint: `http://127.0.0.1:8000/soap`
- WSDL: `http://127.0.0.1:8000/soap?wsdl`

La consulta GET del WSDL es pública. Todas las solicitudes SOAP POST requieren WS-Security UsernameToken con `PasswordText`. Las operaciones de negocio también requieren un JWT válido como primer parámetro. La operación `ValidateTokenService` requiere WS-Security y valida el JWT que recibe.

Operaciones del WSDL:

| Operación | Parámetros SOAP (JWT primero en operaciones de negocio) |
| --- | --- |
| `ValidateTokenService` | `token` |
| `registrarCancha` | `token`, `codigo`, `nombre`, `tipo`, `estado` |
| `consultarCancha` | `token`, `id` |
| `listarCanchas` | `token` |
| `actualizarCancha` | `token`, `id`, `nombre`, `tipo`, `estado` |
| `registrarReserva` | `token`, `canchaId`, `usuarioId`, `fecha`, `hora`, `estado` |
| `consultarReservas` | `token` |

La firma HS256 se comprueba con la clave original compartida. Se verifican formato, algoritmo, firma, `iss`, `sub`, `iat`, `exp` y `jti`; los tokens revocados se rechazan. No se requiere generar un segundo token para comparar firmas.

Ejemplo de cuerpo SOAP para validar un token. Sustituye los valores entre corchetes por credenciales locales y el JWT obtenido al iniciar sesión:

```xml
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:urn="urn:ReservasCanchas"
                  xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
  <soapenv:Header>
    <wsse:Security>
      <wsse:UsernameToken>
        <wsse:Username>[SOAP_SECURITY_USERNAME]</wsse:Username>
        <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">[SOAP_SECURITY_PASSWORD]</wsse:Password>
      </wsse:UsernameToken>
    </wsse:Security>
  </soapenv:Header>
  <soapenv:Body>
    <urn:ValidateTokenService><token>[JWT_LOCAL]</token></urn:ValidateTokenService>
  </soapenv:Body>
</soapenv:Envelope>
```

La respuesta SOAP contiene JSON serializado. Para un token válido, `return` incluye `exito: true`; para uno inválido o vencido, incluye `exito: false`.

El código conserva las reglas de negocio: código de cancha único, reserva única por cancha/fecha/hora, cancha activa y usuario existente. La base de datos refuerza las restricciones de unicidad.

## Validar el JWT de Laravel hacia SOAP

El formulario `/dashboard` tiene un botón **Validar mi sesión con SOAP**. También puedes llamar `POST /api/soap/validate-token` con el JWT en el encabezado `Authorization: Bearer ...`. Laravel utiliza `SoapClient` de PHP y añade WS-Security con credenciales de `.env`; el token no se registra ni se devuelve al cliente SOAP.

La extensión `soap` debe estar habilitada tanto en CLI como en Apache. Si la llamada Laravel-a-SOAP falla, revisa `SOAP_ENDPOINT`, las credenciales WS-Security y que Apache pueda atender solicitudes concurrentes.

## Pruebas con SoapUI 5.10

Abre `soapui/ReservasCanchasLaravel-soapui-project.xml`. El proyecto conserva las operaciones de negocio y trae ejemplos de validación para JWT válido, alterado y vencido. Configura localmente las propiedades del proyecto:

- `soapUsername`: el valor de `SOAP_SECURITY_USERNAME`.
- `soapPassword`: el valor de `SOAP_SECURITY_PASSWORD`.
- `jwtValid`: pega `access_token` de una respuesta exitosa de `/api/login`.
- `jwtAltered`: copia el JWT válido y cambia un carácter de la firma.
- `jwtExpired`: genera un JWT vencido localmente con la clave de `.env`:

  ```powershell
  php artisan tinker --execute '$claims = ["iss" => config("jwt.issuer"), "sub" => "1", "iat" => time() - 120, "nbf" => time() - 120, "exp" => time() - 1, "jti" => (string) Illuminate\Support\Str::uuid()]; echo Firebase\JWT\JWT::encode($claims, config("jwt.secret"), "HS256");'
  ```

No guardes esos valores en el XML del proyecto ni los subas a GitHub. Las operaciones de canchas y reservas usan `jwtValid`; los requests incluyen el encabezado WS-Security de ejemplo.

## Estructura

- `app/Http/Controllers`: controladores web, autenticación y SOAP.
- `app/Services`: reglas de cancha/reserva, servicio JWT y conexión SOAP.
- `app/Repositories`: acceso a las entidades de negocio existentes.
- `app/Soap/SoapFunctions.php`: operaciones registradas en NuSOAP.
- `database/migrations`: esquema Laravel para cuentas y servicios del framework.
- `resources/views/auth`: formularios de registro/inicio y panel de sesión.
- `soapui`: proyecto y guía de pruebas SoapUI.

## Seguridad y entrega

- Nunca incluyas `.env`, `vendor`, `node_modules`, tokens reales ni credenciales en Git o en una entrega.
- El JWT usa una clave propia `JWT_SECRET`; `APP_KEY` se mantiene separada.
- Los endpoints de autenticación tienen límite de intentos.
- En producción, sirve SOAP por HTTPS: `PasswordText` y tokens Bearer no deben viajar por HTTP público.
- El SQL de muestra contiene instrucciones `DROP TABLE`. Haz respaldo y úsalo únicamente en una base desechable o recién creada.
- Para preparar una entrega sin secretos, instala dependencias después de descargar el ZIP y configura `.env` localmente.
