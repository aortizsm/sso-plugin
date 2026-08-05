# Simple SSO

Plugin local de Moodle que proporciona Single Sign-On (SSO) seguro desde Moodle hacia aplicaciones externas mediante cargas útiles firmadas con HMAC-SHA256 entregadas vía HTTP POST.

## Descripción

Simple SSO permite que los usuarios autenticados en Moodle inicien sesión en aplicaciones externas sin necesidad de volver a autenticarse. Funciona construyendo una carga útil firmada con datos del usuario (opcionalmente incluyendo contexto de curso) y enviándola mediante POST a un endpoint externo configurado — los datos nunca viajan en la URL.

## Características

- **Gestión de pasarelas** — Interfaz CRUD para configurar destinos de apps externas (appid, nombre, endpoint URL, secreto compartido)
- **Cargas firmadas** — Firma HMAC-SHA256 que garantiza la integridad de los datos
- **Secretos cifrados** — Secretos compartidos almacenados cifrados en reposo con AES-256-CBC
- **Protección anti-replay** — Nonce criptográfico generado por cada lanzamiento
- **Expiración de token** — Timestamp `iat` para validación temporal
- **Contexto de curso** — Datos opcionales del curso al lanzar desde dentro de uno
- **Registro de auditoría** — Cada lanzamiento SSO se registra con usuario, app, estado, IP y nonce
- **Acceso por capacidades** — Permisos granulares para usar y gestionar pasarelas
- **Bilingüe** — Paquetes de idioma en inglés y español

## Requisitos

- Moodle 3.11+ (requiere >= 2021051700)
- PHP con extensión `openssl`

## Instalación

1. Coloca este plugin en `local/simplesso/` dentro de tu instalación de Moodle
2. Inicia sesión como administrador y visita **Notificaciones** para instalar las tablas
3. Configura las pasarelas en **Administración del sitio → Plugins locales → Gestionar pasarelas SSO**

## Tablas de Base de Datos

### `local_simplesso_gateway`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int | Clave primaria |
| appid | char(100) | Identificador único de aplicación |
| name | char(255) | Nombre legible de la pasarela |
| endpoint | char(255) | URL de destino para el POST SSO |
| sharedsecret | text | Secreto compartido cifrado con AES-256-CBC |
| active | int(1) | Si la pasarela está habilitada (por defecto 1) |
| timecreated | int(10) | Marca de tiempo de creación |
| timemodified | int(10) | Marca de tiempo de última modificación |

### `local_simplesso_log`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int | Clave primaria |
| userid | int(10) | ID del usuario en Moodle |
| appid | char(100) | ID de la pasarela |
| success | int(1) | 1 = éxito, 0 = fallo |
| reason | char(255) | Motivo del fallo (vacío si éxito) |
| nonce | char(64) | Nonce del lanzamiento |
| ip | char(45) | Dirección IP del cliente |
| timecreated | int(10) | Marca de tiempo del lanzamiento |

## Capacidades

| Capacidad | Contexto | Por defecto | Descripción |
|-----------|----------|-------------|-------------|
| `local/simplesso:usegateway` | Sistema | Usuario | Permiso para lanzar SSO a apps externas |
| `local/simplesso:managegateways` | Sistema | Gestor | Permiso para crear, editar y eliminar pasarelas |

## Uso

### URL de lanzamiento

```
https://tu-moodle.com/local/simplesso/launch.php?appid=TU_APPID
```

Con contexto de curso:

```
https://tu-moodle.com/local/simplesso/launch.php?appid=TU_APPID&courseid=7
```

### Flujo SSO

```
Usuario en Moodle
  │
  │  1. Visita launch.php?appid=XXX
  ▼
Moodle verifica sesión + capacidad
  │
  │  2. Construye la carga (usuario + curso opcional)
  │  3. Firma la carga con HMAC-SHA256
  │  4. Devuelve un formulario HTML auto-enviable
  │
  │  5. El navegador hace POST al endpoint de la app externa
  ▼
La app externa recibe POST con payload + firma
  │
  │  6. Decodifica el payload Base64URL
  │  7. Verifica la firma HMAC-SHA256
  │  8. Comprueba expiración del token (iat + 120s)
  │  9. Comprueba nonce (anti-replay)
  │  10. Busca al usuario en su whitelist local
  └──► Crea sesión y redirige al usuario
```

### Estructura de la carga

```json
{
  "userid":    42,
  "username":  "jperez",
  "email":     "jperez@ejemplo.edu",
  "firstname": "Juan",
  "lastname":  "Pérez",
  "appid":     "miapp1",
  "iat":       1746480000,
  "nonce":     "a3f8c21d9e4b7f0612345678abcdef90",
  "course": {
    "id":        7,
    "shortname": "MAT101",
    "fullname":  "Matemáticas I",
    "idnumber":  "MAT-2026-1",
    "category":  3,
    "startdate": 1740000000,
    "enddate":   1755000000
  }
}
```

El campo `course` es `null` cuando no se proporciona contexto de curso o el usuario no está matriculado.

### Verificar la firma (ejemplo PHP)

```php
$secreto = 'el-mismo-secreto-configurado-en-moodle';
$json = base64_decode(strtr($_POST['payload'], '-_', '+/'));
$firmaEsperada = hash_hmac('sha256', $json, $secreto);

if (!hash_equals($firmaEsperada, $_POST['signature'])) {
    http_response_code(403);
    exit('Firma inválida');
}

$carga = json_decode($json, true);

// Comprobar expiración
if (time() - $carga['iat'] > 120) {
    http_response_code(403);
    exit('Token expirado');
}

// Comprobar nonce (anti-replay)
if (nonceYaUsado($carga['nonce'])) {
    http_response_code(403);
    exit('Token ya utilizado');
}
```

Consulta [CONNECT.md](CONNECT.md) para ejemplos completos de integración en PHP, Node.js y Python.

## Estructura de Archivos

```
local/simplesso/
├── db/
│   ├── access.php        # Definición de capacidades
│   ├── install.xml       # Esquema de base de datos (XMLDB)
│   └── upgrade.php       # Pasos de actualización
├── lang/
│   ├── en/
│   │   └── local_simplesso.php  # Cadenas en inglés
│   └── es/
│       └── local_simplesso.php  # Cadenas en español
├── CONNECT.md            # Guía de integración para apps externas
├── gateway.php           # Página de creación/edición de pasarelas
├── index.php             # Página de gestión de pasarelas
├── launch.php            # Endpoint de lanzamiento SSO
├── locallib.php          # Funciones auxiliares (carga, firma, cifrado)
├── log.php               # Visor de registro de auditoría
├── settings.php          # Registro de ajustes de administración
└── version.php           # Metadatos del plugin
```

## Seguridad

- Secretos cifrados con AES-256-CBC usando la clave del sitio Moodle como clave maestra
- Firma HMAC-SHA256 para integridad de la carga
- Codificación Base64URL segura (RFC 4648) para el transporte
- Generación de nonce para protección anti-replay
- Entrega vía POST — los datos nunca aparecen en URLs ni en el historial del navegador
- Verificación de capacidades en todos los endpoints
- Clave de sesión (`sesskey`) requerida para todas las operaciones que modifican estado

## Licencia

GPL v3 o posterior — parte de Moodle.
