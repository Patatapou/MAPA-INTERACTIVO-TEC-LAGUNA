# Arquitectura de Persistencia Documental JSON

**Proyecto:** Mapa Interactivo ITL  
**Estado:** Planificación (sin implementar)  
**Versión:** 1.0

---

## 1. Objetivos

Establecer una capa de persistencia documental basada en archivos JSON que permita:

- Almacenar cuentas de administrador de forma estructurada (reemplazando credenciales hardcodeadas).
- Almacenar configuraciones generales del sistema sin secretos expuestos.
- Registrar eventos de auditoría (login, intentos fallidos, cambios administrativos).
- Almacenar sugerencias enviadas desde el mapa público.
- Mantener separación entre almacenamiento, lógica de negocio y controladores.
- Facilitar una futura migración a SQLite o MongoDB sin reescribir controladores.

---

## 2. Justificación Tecnológica

Se eligió un sistema basado en archivos JSON por las siguientes razones:

- **Volumen de operaciones bajo:** El panel administrativo es usado por un número reducido de administradores. No justifica un motor de base de datos.
- **Sin dependencias externas:** PHP 8.4.7 ya está disponible en el entorno. No se requiere instalar ni configurar un servidor de base de datos.
- **Simplicidad operacional:** Los archivos JSON son legibles, editables manualmente si es necesario, y no requieren mantenimiento de esquema complejo.
- **Compatibilidad con el entorno actual:** El proyecto ya utiliza `datos.json` para edificios. Extender el mismo patrón a usuarios y configuración es natural.
- **Facilidad de respaldo:** Los archivos JSON pueden copiarse como cualquier otro archivo.

---

## 3. Arquitectura General

### 3.1 Separación de responsabilidades

```
┌─────────────────────────────────────────────────────────────┐
│                    CAPA DE PRESENTACIÓN                      │
│  index.html  ←→  PHP/admin/panel.php  ←→  PHP/public/        │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                    CAPA DE CONTROLADORES                      │
│  login.php, saveEdificio.php, imagelist.php, panel.php       │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                    CAPA DE SERVICIOS                          │
│  AuthService, SuggestionService, SettingsService             │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                  CAPA DE REPOSITORIOS                         │
│  UserRepository, SettingsRepository, AuditLogRepository       │
│  SuggestionRepository                                         │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                  CAPA DE ALMACENAMIENTO                        │
│  PHP/data/users.json                                          │
│  PHP/data/settings.json                                       │
│  PHP/data/audit-log.jsonl                                     │
│  PHP/data/suggestions.json                                    │
└─────────────────────────────────────────────────────────────┘
```

### 3.2 Flujo de lectura y escritura (Mermaid)

```mermaid
sequenceDiagram
    participant C as Controlador (login.php)
    participant S as Servicio (AuthService)
    participant R as Repositorio (UserRepository)
    participant F as Archivo (users.json)

    C->>S: autenticar(usuario, password)
    S->>R: buscarPorUsuario(usuario)
    R->>F: leer archivo JSON
    F-->>R: datos del usuario
    R-->>S: registro de usuario o null
    S->>S: password_verify(password, hash)
    S-->>C: resultado de autenticación
    C-->>Usuario: redirigir o mostrar error
```

```mermaid
flowchart TD
    A[Petición HTTP] --> B[Controlador]
    B --> C[Servicio de negocio]
    C --> D[Repositorio JSON]
    D --> E{¿Archivo existe?}
    E -->|Sí| F[Leer con file_get_contents()]
    E -->|No| G[Devolver estructura vacía]
    F --> H[json_decode()]
    H --> I[Operar sobre datos]
    I --> J{¿Operación de escritura?}
    J -->|Sí| K[flock() + escribir temporal + reemplazo atómico]
    J -->|No| L[Devolver resultado]
    K --> M[json_encode() + file_put_contents()]
    M --> L
```

---

## 4. Estructura de Directorios

```
PHP/
├── public/
│   ├── index.php          # Router (ya existe)
│   ├── check-session.php  # Verificación de sesión (ya existe)
│   ├── send-suggestion.php # Procesamiento de sugerencias (ya existe)
│   ├── mapa.php           # Vista pública del mapa (ya existe)
│   └── img/               # Imágenes de edificios (ya existe)
├── admin/
│   ├── login.php          # Autenticación (ya existe)
│   ├── logout.php         # Cierre de sesión (ya existe)
│   ├── panel.php          # Panel de administración (ya existe)
│   ├── saveEdificio.php   # Guardado de edificios (ya existe)
│   └── imagelist.php      # Listado de imágenes (ya existe)
├── auth/
│   ├── auth_middleware.php # Middleware de autenticación (ya existe)
│   ├── csrf.php           # Protección CSRF (ya existe)
│   └── rate_limit.php     # Rate limiting (ya existe)
├── config/
│   └── credenciales.php   # Credenciales hardcodeadas (ya existe)
├── data/                  # NUEVO: capa de persistencia privada
│   ├── users.json         # NUEVO: cuentas de administrador
│   ├── settings.json      # NUEVO: configuración del sistema
│   ├── audit-log.jsonl    # NUEVO: registro de auditoría
│   └── suggestions.json   # NUEVO: sugerencias del mapa público
└── services/              # NUEVO: capa de lógica de negocio
    ├── AuthService.php    # NUEVO
    ├── SuggestionService.php # NUEVO
    └── SettingsService.php # NUEVO
└── repositories/          # NUEVO: capa de persistencia
    ├── UserRepository.php # NUEVO
    ├── SettingsRepository.php # NUEVO
    ├── AuditLogRepository.php # NUEVO
    └── SuggestionRepository.php # NUEVO
```

**Nota:** El directorio `PHP/data/` debe estar fuera del acceso HTTP directo (no bajo `PHP/public/`).

---

## 5. Colecciones JSON

### 5.1 users.json

Almacena cuentas de administrador.

**Estructura de un documento de usuario:**

```json
{
  "admin_001": {
    "id": "admin_001",
    "username": "admin",
    "email": "admin@itllaguna.local",
    "password_hash": "$2y$10$...",
    "role": "administrador",
    "status": "activo",
    "created_at": "2026-11-12T10:00:00-06:00",
    "updated_at": "2026-11-12T10:00:00-06:00",
    "last_login_at": null,
    "schema_version": 1
  }
}
```

**Campos:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | string | Identificador único (UUID o similar) |
| `username` | string | Nombre de usuario para login |
| `email` | string | Correo electrónico |
| `password_hash` | string | Hash generado con `password_hash()` |
| `role` | string | Rol del usuario (ej: `administrador`) |
| `status` | string | Estado de la cuenta (`activo`, `inactivo`, `bloqueado`) |
| `created_at` | string | Fecha de creación (ISO 8601) |
| `updated_at` | string | Última modificación (ISO 8601) |
| `last_login_at` | string|null | Último inicio de sesión exitoso |
| `schema_version` | int | Versión del esquema (para migraciones futuras) |

### 5.2 settings.json

Almacena configuraciones generales del sistema.

**Estructura:**

```json
{
  "site_name": "Mapa Interactivo ITL",
  "maintenance_mode": false,
  "session_timeout_hours": 2,
  "max_login_attempts": 5,
  "login_window_hours": 1,
  "suggestions_email": "migel2013+mapa@outlook.com",
  "schema_version": 1
}
```

**Nota:** Este archivo NO debe contener contraseñas SMTP, claves API ni secretos. Esos valores deben ir en variables de entorno.

### 5.3 audit-log.jsonl

Registra eventos importantes en formato JSONL (una línea JSON por registro).

**Estructura de un registro:**

```json
{"event":"login_success","user_id":"admin_001","username":"admin","ip":"127.0.0.1","timestamp":"2026-11-12T10:00:00-06:00"}
{"event":"login_failed","username":"admin","ip":"127.0.0.1","reason":"invalid_credentials","timestamp":"2026-11-12T10:01:00-06:00"}
{"event":"logout","user_id":"admin_001","username":"admin","ip":"127.0.0.1","timestamp":"2026-11-12T10:30:00-06:00"}
{"event":"building_created","user_id":"admin_001","building_id":"5","ip":"127.0.0.1","timestamp":"2026-11-12T11:00:00-06:00"}
{"event":"building_updated","user_id":"admin_001","building_id":"5","ip":"127.0.0.1","timestamp":"2026-11-12T11:30:00-06:00"}
{"event":"suggestion_received","suggestion_id":"sug_001","building":"5","ip":"192.168.1.10","timestamp":"2026-11-12T12:00:00-06:00"}
```

**Campos comunes:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `event` | string | Tipo de evento (login_success, login_failed, logout, building_created, etc.) |
| `user_id` | string|null | ID del usuario (si aplica) |
| `username` | string|null | Nombre de usuario (para eventos sin sesión) |
| `ip` | string | Dirección IP del cliente |
| `timestamp` | string | Fecha y hora en ISO 8601 |
| Otros | varies | Campos específicos del evento |

### 5.4 suggestions.json

Almacena sugerencias enviadas desde el mapa público.

**Estructura de un documento de sugerencia:**

```json
{
  "sug_001": {
    "id": "sug_001",
    "type": "building_info",
    "building_id": "5",
    "description": "La aula 5A está cambiada de ubicación",
    "contact_email": "usuario@correo.com",
    "status": "pendiente",
    "created_at": "2026-11-12T12:00:00-06:00",
    "reviewed_at": null,
    "reviewed_by": null,
    "schema_version": 1
  }
}
```

**Campos:**

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | string | Identificador único de la sugerencia |
| `type` | string | Tipo de sugerencia (`building_info`, `new_building`, `correction`, `other`) |
| `building_id` | string|null | ID del edificio relacionado (coincide con `datos.json`) |
| `description` | string | Descripción de la sugerencia |
| `contact_email` | string|null | Correo de contacto opcional |
| `status` | string | Estado de revisión (`pendiente`, `revisada`, `aprobada`, `rechazada`) |
| `created_at` | string | Fecha de creación (ISO 8601) |
| `reviewed_at` | string|null | Fecha de revisión |
| `reviewed_by` | string|null | Usuario que revisó |
| `schema_version` | int | Versión del esquema |

---

## 6. Modelos de Documentos (Resumen)

| Colección | Archivo | Formato | Clave primaria | Índice natural |
|-----------|---------|---------|----------------|----------------|
| Usuarios | `users.json` | JSON objeto | `id` | `username` |
| Configuración | `settings.json` | JSON objeto | clave | clave |
| Auditoría | `audit-log.jsonl` | JSONL | n/a (secuencial) | `timestamp` |
| Sugerencias | `suggestions.json` | JSON objeto | `id` | `status`, `building_id` |

---

## 7. Repositorios

### 7.1 UserRepository

**Responsabilidad:** Persistencia y consulta de cuentas de usuario.

**Operaciones:**

| Operación | Descripción |
|-----------|-------------|
| `buscarPorId(string $id): array|null` | Buscar usuario por ID |
| `buscarPorUsername(string $username): array|null` | Buscar usuario por nombre de usuario |
| `crear(array $datos): string` | Crear nuevo usuario, devolver ID generado |
| `actualizar(string $id, array $datos): bool` | Actualizar campos de usuario |
| `eliminar(string $id): bool` | Eliminar usuario |
| `listar(): array` | Listar todos los usuarios |
| `actualizarUltimoLogin(string $id): bool` | Actualizar `last_login_at` |

### 7.2 SettingsRepository

**Responsabilidad:** Persistencia y consulta de configuración del sistema.

**Operaciones:**

| Operación | Descripción |
|-----------|-------------|
| `obtener(string $clave, mixed $default = null): mixed` | Obtener un valor de configuración |
| `obtenerTodas(): array` | Obtener todas las configuraciones |
| `guardar(string $clave, mixed $valor): bool` | Guardar/actualizar una configuración |
| `eliminar(string $clave): bool` | Eliminar una configuración |

### 7.3 AuditLogRepository

**Responsabilidad:** Registro de eventos de auditoría en formato JSONL.

**Operaciones:**

| Operación | Descripción |
|-----------|-------------|
| `registrar(array $evento): bool` | Agregar un evento al final del archivo |
| `obtener(int $limite = 100): array` | Obtener los últimos N eventos |
| `filtrarPorUsuario(string $userId): array` | Obtener eventos de un usuario |
| `filtrarPorEvento(string $evento): array` | Obtener eventos de un tipo |

### 7.4 SuggestionRepository

**Responsabilidad:** Persistencia y consulta de sugerencias del mapa público.

**Operaciones:**

| Operación | Descripción |
|-----------|-------------|
| `buscarPorId(string $id): array|null` | Buscar sugerencia por ID |
| `crear(array $datos): string` | Crear nueva sugerencia, devolver ID |
| `actualizarEstado(string $id, string $estado, string $revisadoPor): bool` | Cambiar estado de revisión |
| `listar(string $estado = null): array` | Listar sugerencias, opcionalmente filtrar por estado |
| `eliminar(string $id): bool` | Eliminar sugerencia |

---

## 8. Servicios de Negocio

Los servicios encapsulan las reglas de negocio y coordenan los repositorios.

### 8.1 AuthService

- `autenticar(string $username, string $password): array|null` — Verifica credenciales, devuelve datos del usuario o null.
- `registrarLoginExitoso(string $userId): void` — Actualiza `last_login_at` y registra evento de auditoría.
- `registrarLoginFallido(string $username): void` — Registra evento de auditoría de fallo.
- `obtenerUsuario(string $userId): array|null` — Obtiene datos del usuario.

### 8.2 SuggestionService

- `crearSugerencia(array $datos): string` — Valida entradas, crea sugerencia, registra en auditoría.
- `obtenerSugerenciasPendientes(): array` — Obtiene sugerencias con estado `pendiente`.
- `revisarSugerencia(string $id, string $estado, string $revisadoPor): bool` — Cambia estado de revisión.

### 8.3 SettingsService

- `obtenerConfiguracion(string $clave): mixed` — Obtiene valor de configuración con fallback.
- `actualizarConfiguracion(string $clave, mixed $valor): bool` — Actualiza configuración.

---

## 9. Seguridad

### 9.1 Protección de credenciales

- Las contraseñas se almacenan únicamente como hash generado con `password_hash()`.
- Nunca se almacena la contraseña en texto plano.
- Verificación con `password_verify()`.
- `users.json` debe estar fuera del directorio público (`PHP/data/`, no `PHP/public/`).

### 9.2 Validación del lado del servidor

- Toda entrada recibida por POST/GET se valida y sanitiza antes de persistir.
- Los campos obligatorios se verifican.
- Los correos electrónicos se validan con `filter_var()`.
- Se elimina caracteres de control y saltos de línea en campos usados en cabeceras de correo.

### 9.3 Protección CSRF

- Los formularios de login y escritura incluyen un token CSRF generado por sesión.
- Validación con `hash_equals()` para evitar ataques de temporización.

### 9.4 Control de permisos

- Los repositorios son accedidos únicamente a través de servicios.
- Los servicios son invocados únicamente por controladores.
- Los controladores verifican autenticación mediante `requerirAdmin()` antes de delegar en servicios.

### 9.5 Bloqueo de archivos privados

- `PHP/data/` no está bajo `PHP/public/`, por lo que no es accesible directamente vía HTTP.
- El servidor PHP integrado (`php -S`) sirve archivos según el root configurado; `PHP/data/` queda fuera del alcance.

### 9.6 Prevención de corrupción de datos

- Escritura en archivo temporal + reemplazo atómico (`rename()`).
- Uso de `flock()` para bloqueo exclusivo durante escritura.
- Validación de JSON con `json_decode()` y `json_last_error()` antes de aceptar datos.
- `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE` para consistencia.

### 9.7 Registros de auditoría sin información sensible

- No se almacena contraseñas ni hashes en `audit-log.jsonl`.
- Se registran únicamente IDs de usuario, nombres de usuario, IPs, tipos de evento y timestamps.

---

## 10. Integridad y Concurrencia

### 10.1 Mecanismos de protección

| Mecanismo | Descripción |
|-----------|-------------|
| `flock()` | Bloqueo exclusivo (`LOCK_EX`) durante lectura y escritura de archivos JSON. |
| Archivo temporal | Escritura a `archivo.tmp` seguido de `rename()` atómico para reemplazo. |
| Validación JSON | `json_decode()` con verificación de `json_last_error()` antes de persistir. |
| `schema_version` | Campo en cada documento para rastrear versiones de esquema. |

### 10.2 Limitaciones de concurrencia en Windows

- En Windows, `rename()` sobre un archivo existente puede fallar si el archivo está abierto en otro proceso.
- `flock()` funciona en Windows pero tiene limitaciones en redes compartidas.
- El volumen esperado de operaciones (pocos administradores, uso esporádico) hace que estas limitaciones sean aceptables.

### 10.3 Copias de seguridad y recuperación

- Los archivos JSON pueden copiarse manualmente como respaldo.
- No se implementa respaldo automático en esta fase.
- Procedimiento de recuperación: restaurar archivo JSON desde la copia de seguridad más reciente.

---

## 11. Relación con los datos del mapa (`datos.json`)

- `datos.json` (raíz y `PHP/public/`) continúa almacenando información de edificios (nombre, descripción, aulas, imágenes).
- **No se migra** `datos.json` al nuevo sistema de persistencia.
- Los IDs de edificio en `users.json`, `settings.json` y `suggestions.json` hacen referencia a claves existentes en `datos.json` cuando corresponde (ej: `building_id` en sugerencias).
- Esto evita duplicar información y mantiene una única fuente de verdad para los datos del mapa.

---

## 12. Limitaciones Conocidas

| Limitación | Descripción |
|------------|-------------|
| Sin motor de consultas | Las búsquedas requieren leer todo el archivo en memoria. Aceptable para volúmenes bajos. |
| Sin transacciones ACID | No hay rollback automático si falla una operación multi-archivo. |
| Concurrencia limitada | `flock()` en Windows tiene restricciones; uso exclusivo por un administrador a la vez es el escenario esperado. |
| Sin índices | Cada consulta escanea toda la colección. |
| Respaldo manual | No hay respaldo automático programado. |

---

## 13. Migración Futura (SQLite / MongoDB)

La arquitectura está diseñada con Repository Pattern para facilitar sustitución:

1. Los controladores dependen de servicios, no de repositorios directamente.
2. Los servicios dependen de interfaces de repositorio.
3. Para migrar a SQLite o MongoDB, solo se implementan nuevos repositorios que cumplan la misma interfaz.
4. Los controladores y servicios no requieren cambios.

**No se implementa una abstracción genérica innecesaria.** La interfaz de repositorio se define según las operaciones reales necesarias actualmente.

---

## 14. Decisiones que Requieren Aprobación

1. **Ubicación de `PHP/data/`:** ¿Está bien que esté fuera de `PHP/public/` pero dentro de `PHP/`?
2. **Credenciales hardcodeadas:** El plan de migración (Documento 2) contempla moverlas a `users.json`. ¿Se aprueba este enfoque?
3. **Formato JSONL para auditoría:** ¿Es aceptable para el volumen esperado, o se prefiere un archivo JSON tradicional?
4. **Timeout de sesión:** Ya configurado en 2 horas en Etapa 2. ¿Se mantiene?

---

## 15. Resumen de Componentes

| Componente | Tipo | Ruta | Estado |
|------------|------|------|--------|
| UserRepository | Repositorio | `PHP/repositories/UserRepository.php` | Por crear |
| SettingsRepository | Repositorio | `PHP/repositories/SettingsRepository.php` | Por crear |
| AuditLogRepository | Repositorio | `PHP/repositories/AuditLogRepository.php` | Por crear |
| SuggestionRepository | Repositorio | `PHP/repositories/SuggestionRepository.php` | Por crear |
| AuthService | Servicio | `PHP/services/AuthService.php` | Por crear |
| SuggestionService | Servicio | `PHP/services/SuggestionService.php` | Por crear |
| SettingsService | Servicio | `PHP/services/SettingsService.php` | Por crear |
| users.json | Almacenamiento | `PHP/data/users.json` | Por crear |
| settings.json | Almacenamiento | `PHP/data/settings.json` | Por crear |
| audit-log.jsonl | Almacenamiento | `PHP/data/audit-log.jsonl` | Por crear |
| suggestions.json | Almacenamiento | `PHP/data/suggestions.json` | Por crear |
