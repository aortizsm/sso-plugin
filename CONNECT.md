# Conectar una app externa al SSO de DevLearn

Este documento explica cómo recibir y validar el inicio de sesión SSO que Moodle envía a tu app.

---

## Flujo completo

```
Usuario en Moodle
      │
      │  1. Abre: /local/devlearn/launch.php?appid=TU_APPID
      ▼
  Moodle verifica sesión activa y capability
      │
      │  2. Construye payload con datos del usuario
      │  3. Firma el payload con HMAC-SHA256
      │  4. Devuelve una página HTML con formulario oculto
      │     <form method="post" action="https://tuapp.com/sso/receive">
      │       <input type="hidden" name="payload"   value="BASE64URL">
      │       <input type="hidden" name="signature" value="HEX">
      │     </form>  →  se auto-envía via JS en window.onload
      │
      │  5. El navegador hace POST a tu app (datos en el body, NO en la URL)
      ▼
  Tu app recibe la request POST
      │
      │  6. Decodifica el payload
      │  7. Verifica la firma HMAC
      │  8. Verifica que el token no expiró (iat + 120s)
      │  9. Verifica que el nonce no fue usado antes
      │  10. Busca al usuario en tu whitelist local
      └──► Crea sesión y enruta al usuario
```

> Los datos **nunca viajan en la URL**. El navegador hace un POST HTTP normal al endpoint de tu app.

---

## Estructura del payload

El parámetro `payload` es el JSON del usuario codificado en **Base64URL** (RFC 4648 §5, sin padding `=`).

Una vez decodificado tiene esta forma:

```json
{
  "userid":    42,
  "username":  "jperez",
  "email":     "jperez@institución.edu",
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

> Si el usuario **no viene de un curso** (o no está matriculado en él), el campo `course` llega como `null`.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `userid` | int | ID interno del usuario en Moodle |
| `username` | string | Nombre de usuario de Moodle |
| `email` | string | Correo electrónico |
| `firstname` | string | Nombre |
| `lastname` | string | Apellido |
| `appid` | string | Identificador de la pasarela que inició el SSO |
| `iat` | int | Timestamp Unix de emisión (issued at) |
| `nonce` | string | Token único de 32 chars hex para anti-replay |
| `course` | object\|null | Datos del curso de origen (ver tabla siguiente) |

**Campos dentro de `course`** (cuando no es `null`):

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | int | ID del curso en Moodle |
| `shortname` | string | Nombre corto del curso |
| `fullname` | string | Nombre completo del curso |
| `idnumber` | string | Número de identificación externo del curso |
| `category` | int | ID de la categoría a la que pertenece |
| `startdate` | int | Fecha de inicio (timestamp Unix) |
| `enddate` | int | Fecha de fin (timestamp Unix, 0 = sin fecha) |

---

## Verificación en tu app

### PHP

```php
<?php
// sso/receive.php en tu app

define('SSO_SECRET', 'el-secreto-que-pusiste-en-moodle');
define('SSO_MAX_AGE', 120); // segundos de vida del token

function verifyDevlearnSSO(string $rawPayload, string $receivedSig): array {
    // 1. Decodificar Base64URL → JSON
    $json = base64_decode(strtr($rawPayload, '-_', '+/'));
    if ($json === false) {
        throw new Exception('Payload inválido');
    }

    // 2. Verificar firma HMAC-SHA256 (comparación segura)
    $expectedSig = hash_hmac('sha256', $json, SSO_SECRET);
    if (!hash_equals($expectedSig, $receivedSig)) {
        throw new Exception('Firma inválida');
    }

    // 3. Decodificar payload
    $payload = json_decode($json, true);
    if (!$payload) {
        throw new Exception('JSON inválido');
    }

    // 4. Verificar expiración
    if (time() - (int)$payload['iat'] > SSO_MAX_AGE) {
        throw new Exception('Token expirado');
    }

    // 5. Verificar nonce (anti-replay)
    if (nonceAlreadyUsed($payload['nonce'])) {
        throw new Exception('Token ya utilizado');
    }
    markNonceAsUsed($payload['nonce']);

    return $payload;
}

// --- Punto de entrada ---
// Los datos llegan via POST (formulario auto-enviado por Moodle)
try {
    $payload = verifyDevlearnSSO(
        $_POST['payload'] ?? '',
        $_POST['signature'] ?? ''
    );
} catch (Exception $e) {
    http_response_code(403);
    exit('Acceso denegado: ' . $e->getMessage());
}

// 6. Buscar usuario en tu whitelist local
$user = buscarEnWhitelist($payload['email']); // o por username
if (!$user) {
    http_response_code(403);
    exit('Usuario no autorizado');
}

// 7. Crear sesión y redirigir
session_start();
$_SESSION['user'] = $user;
header('Location: /dashboard');
exit;
```

### Node.js / Express

```js
const crypto = require('crypto');

const SSO_SECRET = 'el-secreto-que-pusiste-en-moodle';
const SSO_MAX_AGE = 120; // segundos

// Necesitas body-parser o express.urlencoded() para leer el POST:
// app.use(express.urlencoded({ extended: false }));

app.post('/sso/receive', async (req, res) => {
  try {
    const { payload: rawPayload, signature: receivedSig } = req.body;

    // 1. Base64URL → JSON string
    const jsonStr = Buffer.from(
      rawPayload.replace(/-/g, '+').replace(/_/g, '/'),
      'base64'
    ).toString('utf8');

    // 2. Verificar firma HMAC-SHA256
    const expectedSig = crypto
      .createHmac('sha256', SSO_SECRET)
      .update(jsonStr)
      .digest('hex');

    if (!crypto.timingSafeEqual(
      Buffer.from(expectedSig, 'hex'),
      Buffer.from(receivedSig,  'hex')
    )) {
      throw new Error('Firma inválida');
    }

    // 3. Parsear payload
    const payload = JSON.parse(jsonStr);

    // 4. Verificar expiración
    if (Math.floor(Date.now() / 1000) - payload.iat > SSO_MAX_AGE) {
      throw new Error('Token expirado');
    }

    // 5. Anti-replay (guardar nonce en Redis/DB)
    if (await nonceAlreadyUsed(payload.nonce)) {
      throw new Error('Token ya utilizado');
    }
    await markNonceAsUsed(payload.nonce, SSO_MAX_AGE);

    // 6. Buscar en whitelist
    const user = await findInWhitelist(payload.email);
    if (!user) return res.status(403).send('Usuario no autorizado');

    // 7. Crear sesión y redirigir
    req.session.user = user;
    res.redirect('/dashboard');

  } catch (e) {
    res.status(403).send('Acceso denegado: ' + e.message);
  }
});
```

### Python / Flask

```python
import hmac, hashlib, base64, json, time
from flask import Flask, request, redirect, session, abort

app = Flask(__name__)
SSO_SECRET = b'el-secreto-que-pusiste-en-moodle'
SSO_MAX_AGE = 120  # segundos

@app.route('/sso/receive', methods=['POST'])
def sso_receive():
    # Los datos llegan en el body del POST (formulario auto-enviado por Moodle)
    raw_payload = request.form.get('payload', '')
    received_sig = request.form.get('signature', '')

    # 1. Base64URL → bytes → JSON
    padding = '=' * (-len(raw_payload) % 4)
    json_str = base64.urlsafe_b64decode(raw_payload + padding).decode('utf-8')

    # 2. Verificar firma HMAC-SHA256
    expected_sig = hmac.new(SSO_SECRET, json_str.encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected_sig, received_sig):
        abort(403, 'Firma inválida')

    # 3. Parsear
    payload = json.loads(json_str)

    # 4. Verificar expiración
    if time.time() - payload['iat'] > SSO_MAX_AGE:
        abort(403, 'Token expirado')

    # 5. Anti-replay (Redis, DB, etc.)
    if nonce_already_used(payload['nonce']):
        abort(403, 'Token ya utilizado')
    mark_nonce_used(payload['nonce'], ttl=SSO_MAX_AGE)

    # 6. Buscar en whitelist
    user = find_in_whitelist(payload['email'])
    if not user:
        abort(403, 'Usuario no autorizado')

    # 7. Crear sesión y redirigir
    session['user'] = user
    return redirect('/dashboard')
```

---

## Implementar anti-replay del nonce

El nonce dura lo mismo que el token (120 s). Guárdalo en caché y recházalo si ya apareció.

### Con Redis (recomendado)

```php
// PHP
function nonceAlreadyUsed(string $nonce): bool {
    $redis = new Redis();
    $redis->connect('127.0.0.1');
    // SETNX devuelve false si la clave ya existe
    return !$redis->set("sso_nonce:$nonce", 1, ['NX', 'EX' => 120]);
}

function markNonceAsUsed(string $nonce): void {
    // Ya marcado en nonceAlreadyUsed() con SETNX
}
```

### Sin Redis (tabla SQL mínima)

```sql
CREATE TABLE sso_nonces (
    nonce      VARCHAR(64) PRIMARY KEY,
    expires_at INT NOT NULL
);
```

```php
function nonceAlreadyUsed(string $nonce): bool {
    $pdo = getPDO();
    // Limpiar expirados
    $pdo->exec("DELETE FROM sso_nonces WHERE expires_at < " . time());
    $row = $pdo->query("SELECT 1 FROM sso_nonces WHERE nonce = " . $pdo->quote($nonce))->fetch();
    return (bool)$row;
}

function markNonceAsUsed(string $nonce): void {
    $pdo = getPDO();
    $pdo->exec(
        "INSERT INTO sso_nonces (nonce, expires_at) VALUES (" .
        $pdo->quote($nonce) . ", " . (time() + 120) . ")"
    );
}
```

---

## Configurar la pasarela en Moodle

1. Ir a **Administración del sitio → Plugins locales → Manage SSO gateways**  
   (o a `/local/devlearn/index.php`)
2. Crear nueva pasarela:

   | Campo | Valor de ejemplo |
   |-------|-----------------|
   | Application ID | `miapp1` |
   | Name | `Mi App en Desarrollo` |
   | Destination URL | `https://tuapp.com/sso/receive` |
   | Shared secret | cadena larga aleatoria |
   | Active | ✓ |

3. Copiar exactamente el **Shared secret** — es el mismo valor que `SSO_SECRET` en tu app.

---

## URL de lanzamiento

Para dirigir a un usuario a tu app desde Moodle (por ejemplo en un link de menú o botón):

**Sin contexto de curso:**
```
https://tu-moodle.com/local/devlearn/launch.php?appid=miapp1
```

**Desde dentro de un curso** (incluye datos del curso en el payload):
```
https://tu-moodle.com/local/devlearn/launch.php?appid=miapp1&courseid=7
```

El usuario debe tener sesión activa en Moodle. Si no la tiene, Moodle lo llevará al login primero.

Al visitar la URL, Moodle muestra una página de transición que envía automáticamente un **HTTP POST** al endpoint de tu app (`Destination URL` configurada en la pasarela). El usuario no ve ningún dato — solo el mensaje "Redirigiendo..." durante un instante.

Si el `courseid` no existe o el usuario no está matriculado en él, el campo `course` del payload llega como `null`.

---

## Errores comunes

| Síntoma | Causa | Solución |
|---------|-------|----------|
| Firma inválida | Secret no coincide entre Moodle y tu app | Copiar el secret exactamente, sin espacios |
| Token expirado | Más de 120 s entre emisión y recepción | Verificar que los relojes de ambos servidores están sincronizados (NTP) |
| Token ya utilizado | Nonce repetido | Revisar implementación de anti-replay; no reutilizar links |
| Usuario no autorizado | No está en tu whitelist | Agregar el email/username a tu tabla local de usuarios |
| Error 403 sin mensaje | `appid` inactiva en Moodle | Activar la pasarela desde el panel admin |

---

## Checklist de seguridad

- [ ] HTTPS en la URL de destino (nunca HTTP en producción)
- [ ] `hash_equals` / `timingSafeEqual` para comparar firmas (evita timing attacks)
- [ ] Validar expiración del token (`iat + 120s`)
- [ ] Implementar anti-replay con nonce (Redis o tabla SQL)
- [ ] Secret de al menos 32 caracteres aleatorios
- [ ] No loguear el `payload` ni el `signature` completos en los logs de tu app
- [ ] Whitelist de usuarios gestionada localmente en tu app
