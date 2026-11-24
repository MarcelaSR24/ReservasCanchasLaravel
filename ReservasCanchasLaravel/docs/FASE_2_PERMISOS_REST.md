# Matriz de permisos REST — fase 2

Todas las rutas REST deben incluir `jwt.auth`, excepto `POST /api/login`. Esto incluye las rutas preexistentes `/api/register` y `/api/soap/validate-token`; el formulario web `/register` se mantiene como flujo web público. Los endpoints de negocio todavía no están implementados; esta matriz documenta los alias que deberán aplicarse cuando se creen.

| Operación | Roles permitidos | Middleware de rol |
| --- | --- | --- |
| `login` | Público | Ninguno |
| `generarCobro` | `administrador`, `recepcionista` | `can.manage.billing` |
| `consultarCobro` | `administrador`, `recepcionista`, `cajero` | `can.consult.billing` |
| `actualizarEstadoCobro` | `administrador`, `cajero` | `can.update.payment.status` |
| `listarImplementos` | `administrador`, `recepcionista`, `encargado_inventario` | `can.list.equipment` |
| `registrarImplemento` | `administrador`, `encargado_inventario` | `can.manage.equipment` |

`can.consult.billing` y `can.list.equipment` implementan las combinaciones alternativas (OR) en una sola comprobación. No deben componerse con middlewares consecutivos que conviertan la autorización en AND.

Los cuatro middlewares base conservan sus responsabilidades: `is.administrator`, `can.manage.billing`, `can.update.payment.status` y `can.manage.equipment`. Los middlewares de autorización responden 403 con `{"error":"Permisos insuficientes para ejecutar este método"}` cuando el usuario autenticado no tiene uno de los roles permitidos.

La protección de `consultarReservas()` por rol no se cambia en esta fase. Su diseño de autorización limitada al flujo `consultarCobro` queda pendiente de autorización independiente.
