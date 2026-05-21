# AGENTS.md — DevLearn SSO

## Resumen del Proyecto

**local_devlearn** es un plugin local de Moodle (v0.1.0, alpha) que proporciona handoff SSO desde Moodle hacia aplicaciones externas. Los usuarios autenticados de Moodle son redirigidos a apps externas configuradas mediante un formulario HTML POST auto-enviable que lleva una carga JSON firmada.

## Arquitectura

### Flujo SSO

1. El usuario visita `/local/devlearn/launch.php?appid=XXX`
2. El plugin verifica la sesión de Moodle + capacidad `local/devlearn:usegateway`
3. Construye la carga con datos del usuario (+ datos opcionales de curso si se suministra `courseid`)
4. Firma el JSON de la carga con HMAC-SHA256 usando el secreto compartido descifrado de la pasarela
5. Devuelve un formulario HTML auto-enviable que hace POST de `payload` (Base64URL) + `signature` (hex) al endpoint externo
6. La app externa verifica la firma, comprueba expiración + nonce, busca al usuario, crea sesión

### Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `locallib.php` | Funciones centrales: construcción de carga, firma HMAC, cifrado/descifrado AES-256-CBC, Base64URL, generación de nonce, registro de logs, normalización de endpoints |
| `launch.php` | Endpoint público de lanzamiento SSO — renderiza formulario POST auto-enviable |
| `index.php` | Lista de pasarelas admin — alternar, eliminar, editar, enlaces de lanzamiento |
| `gateway.php` | Formulario admin de creación/edición de pasarelas — valida, cifra secreto, guarda |
| `log.php` | Visor admin de logs de auditoría con filtros (appid, éxito/fallo) |
| `settings.php` | Registra página externa de admin bajo plugins locales |
| `db/install.xml` | Dos tablas: `local_devlearn_gateway` + `local_devlearn_log` |
| `db/access.php` | Dos capacidades: `usegateway` (usuario), `managegateways` (gestor) |

### Base de Datos

- **`local_devlearn_gateway`** — almacena appid (único), nombre, endpoint, sharedsecret cifrado, flag activo, marcas de tiempo
- **`local_devlearn_log`** — entradas de auditoría con userid, appid, success, reason, nonce, ip, timecreated

### Modelo de Seguridad

- Secretos cifrados en reposo con AES-256-CBC (clave derivada de `CFG->passwordsaltmain` o `CFG->wwwroot + CFG->dataroot`)
- Firma HMAC-SHA256 sobre el JSON de la carga (antes de codificar en Base64URL)
- Nonce: cadena hex de 32 caracteres desde `random_bytes(16)` (con fallback)
- Ventana de validez del token: 120 segundos (aplicada por la app receptora)
- Datos entregados vía cuerpo POST — nunca en URLs
- Verificación de capacidades en todos los endpoints de gestión y lanzamiento
- Clave de sesión (`sesskey`) requerida para todas las operaciones que cambian estado

## Convenciones de Código

- Seguir los estándares de codificación de Moodle
- Todos los archivos incluyen cabecera de licencia GPL
- `defined('MOODLE_INTERNAL') || die();` en cada archivo PHP
- Usar `clean_param()` para sanitización de entrada
- Usar constantes `PARAM_*` para `required_param`/`optional_param`
- Cadenas cargadas vía `get_string()` con componente `local_devlearn`
- Páginas admin usan `admin_externalpage_setup()`
- Notificaciones vía `\core\notification::success/error()`
- Redirecciones tras acciones POST para prevenir reenvío

## Idiomas

- Inglés (`lang/en/local_devlearn.php`) — principal
- Español (`lang/es/local_devlearn.php`) — traducción completa

## Guía de Integración

Las apps externas deben:

1. Decodificar el payload Base64URL a JSON
2. Verificar la firma HMAC-SHA256 usando el secreto compartido
3. Comprobar que el timestamp `iat` está dentro de 120 segundos
4. Comprobar que el nonce no ha sido usado (anti-replay)
5. Buscar al usuario por email/username en su whitelist local
6. Crear sesión y redirigir

Ver `CONNECT.md` para ejemplos completos en PHP, Node.js/Express y Python/Flask.
