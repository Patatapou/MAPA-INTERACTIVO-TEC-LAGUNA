# Plan de Implementación: Persistencia Documental JSON

**Proyecto:** Mapa Interactivo ITL
**Estado:** Planificación (sin implementar)
**Versión:** 1.0

---

## 1. Diagnóstico Actual

El proyecto actualmente almacena:

- **Credenciales de administrador** hardcodeadas en `PHP/config/credenciales.php` (usuario `admin`, hash de contraseña).
- **Información de edificios** en `datos.json` (raíz y `PHP/public/`).
- **Sesiones de administrador** en `$_SESSION['admin']` (memoria del servidor, no persistente).
- **Sugerencias del mapa público** se envían por correo electrónico, no se almacenan localmente.
- **No hay registro de auditoría** de eventos administrativos.
- **No hay configuración persistente** del sistema (todo está hardcodeado o en variables de entorno).

### Archivos existentes relevantes

| Archivo            | Ruta                             | Función                                    |
| ------------------ | -------------------------------- | ------------------------------------------- |
| Credenciales       | `PHP/config/credenciales.php`  | Usuario y contraseña hardcodeada           |
| Datos edificios    | `datos.json` (raíz)           | Metadata de edificios para el mapa público |
| Datos edificios    | `PHP/public/datos.json`        | Copia para el panel admin PHP               |
| Router             | `PHP/public/index.php`         | Rutea peticiones a vistas PHP               |
| Login              | `PHP/admin/login.php`          | Autenticación con CSRF y rate limiting     |
| Guardado edificios | `PHP/admin/saveEdificio.php`   | Escribe `datos.json` directamente         |
| Panel admin        | `PHP/admin/panel.php`          | Vista de edición de edificios              |
| Listado imágenes  | `PHP/admin/imagelist.php`      | Lista imágenes de una carpeta              |
| Logout             | `PHP/admin/logout.php`         | Cierra sesión                              |
| Middleware auth    | `PHP/auth/auth_middleware.php` | `requerirAdmin()` para rutas protegidas   |
| CSRF               | `PHP/auth/csrf.php`            | Tokens CSRF                                 |
| Rate limit         | `PHP/auth/rate_limit.php`      | Límite de intentos de login                |

---

## 2. Objetivos

1. Reemplazar credenciales hardcodeadas por usuarios almacenados en `users.json`.
2. Almacenar configuración del sistema en `settings.json`.
3. Registrar eventos de auditoría en `audit-log.jsonl`.
4. Almacenar sugerencias del mapa público en `suggestions.json`.
5. Separar almacenamiento, lógica de negocio y controladores mediante Repository Pattern.
6. Mantener compatibilidad con `datos.json` (edificios) sin migrarlo.
7. Facilitar futura migración a SQLite o MongoDB.

---

## 3. Alcance

**Incluido:**

- Creación de directorio `PHP/data/` para archivos JSON privados.
- Creación de repositorios en `PHP/repositories/`.
- Creación de servicios en `PHP/services/`.
- Creación de archivos JSON iniciales (`users.json`, `settings.json`, `audit-log.jsonl`, `suggestions.json`).
- Modificación de `login.php` para autenticar contra `users.json`.
- Modificación de `saveEdificio.php` para usar servicios/repositorios.
- Modificación de `imagelist.php` (ya protegido, no requiere cambios de persistencia).
- Migración del primer administrador desde `credenciales.php` a `users.json`.
- Actualización de `.gitignore` para excluir `PHP/data/`.
- Actualización de `AGENTS.md`.

**No incluido (fuera de alcance):**

- Migración de `datos.json` (edificios) al nuevo sistema.
- Motor de consultas complejo.
- Transacciones ACID.
- Respaldos automáticos programados.
- Autenticación de dos factores (2FA).
- Recuperación de contraseña por correo.

---

## 4. Archivos Nuevos

| Archivo                 | Ruta                                          | Descripción                                   |
| ----------------------- | --------------------------------------------- | ---------------------------------------------- |
| Directorio de datos     | `PHP/data/`                                 | Almacenamiento privado de JSON (fuera de HTTP) |
| Usuarios                | `PHP/data/users.json`                       | Cuentas de administrador                       |
| Configuración          | `PHP/data/settings.json`                    | Configuración del sistema                     |
| Auditoría              | `PHP/data/audit-log.jsonl`                  | Registro de eventos JSONL                      |
| Sugerencias             | `PHP/data/suggestions.json`                 | Sugerencias del mapa público                  |
| Repositorio usuarios    | `PHP/repositories/UserRepository.php`       | Persistencia de usuarios                       |
| Repositorio settings    | `PHP/repositories/SettingsRepository.php`   | Persistencia de configuración                 |
| Repositorio auditoría  | `PHP/repositories/AuditLogRepository.php`   | Persistencia de auditoría JSONL               |
| Repositorio sugerencias | `PHP/repositories/SuggestionRepository.php` | Persistencia de sugerencias                    |
| Servicio auth           | `PHP/services/AuthService.php`              | Lógica de autenticación                      |
| Servicio sugerencias    | `PHP/services/SuggestionService.php`        | Lógica de sugerencias                         |
| Servicio settings       | `PHP/services/SettingsService.php`          | Lógica de configuración                      |

---

## 5. Archivos Existentes que Requieren Modificaciones

| Archivo                            | Cambios requeridos                                                                                      |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `PHP/admin/login.php`            | Autenticar contra `UserRepository` en lugar de `credenciales.php`. Registrar eventos de auditoría. |
| `PHP/admin/saveEdificio.php`     | Usar `AuthService` para verificar permisos. Registrar cambios de edificios en auditoría.             |
| `PHP/public/send-suggestion.php` | Almacenar sugerencia en `suggestions.json` además de enviar correo.                                  |
| `PHP/auth/auth_middleware.php`   | No requiere cambios (usa `$_SESSION['admin']`).                                                       |
| `PHP/config/credenciales.php`    | Mantener como fallback durante migración. Se revisará en fase posterior.                              |
| `.gitignore`                     | Agregar `PHP/data/` para excluir del repositorio.                                                     |
| `AGENTS.md`                      | Documentar nueva estructura de archivos y repositorios.                                                 |

---

## 6. Dependencias

- PHP 8.4.7 (ya disponible).
- Extensiones PHP ya habilitadas: `session`, `filter`, `hash`, `standard`, `json`.
- No se requieren nuevas dependencias externas.
- No se requiere gestor de paquetes (Composer no está en el proyecto).

---

## 7. Fases de Implementación

### Fase 1: Infraestructura de almacenamiento base

| Tarea | Descripción                           | Dependencias | Resultado esperado                                                                                  |
| ----- | -------------------------------------- | ------------ | --------------------------------------------------------------------------------------------------- |
| 1.1   | Crear directorio `PHP/data/`         | Ninguno      | Directorio existe, fuera de `PHP/public/`                                                         |
| 1.2   | Crear `PHP/repositories/`            | Ninguno      | Directorio existe                                                                                   |
| 1.3   | Crear `PHP/services/`                | Ninguno      | Directorio existe                                                                                   |
| 1.4   | Crear `UserRepository.php`           | 1.2          | Clase con operaciones CRUD de usuarios sobre JSON                                                   |
| 1.5   | Crear `SettingsRepository.php`       | 1.2          | Clase con operaciones de configuración                                                             |
| 1.6   | Crear `AuditLogRepository.php`       | 1.2          | Clase con operaciones JSONL de auditoría                                                           |
| 1.7   | Crear `SuggestionRepository.php`     | 1.2          | Clase con operaciones CRUD de sugerencias                                                           |
| 1.8   | Crear archivos JSON iniciales          | 1.1          | `users.json`, `settings.json`, `audit-log.jsonl`, `suggestions.json` con estructura válida |
| 1.9   | Agregar `PHP/data/` a `.gitignore` | Ninguno      | Archivos de datos excluidos del repositorio                                                         |

### Fase 2: Servicios de negocio

| Tarea | Descripción                    | Dependencias | Resultado esperado                                                    |
| ----- | ------------------------------- | ------------ | --------------------------------------------------------------------- |
| 2.1   | Crear `AuthService.php`       | 1.4, 1.6     | Autenticación contra `users.json`, registro de login en auditoría |
| 2.2   | Crear `SuggestionService.php` | 1.7, 1.6     | Crear y gestionar sugerencias, registrar en auditoría                |
| 2.3   | Crear `SettingsService.php`   | 1.5          | Obtener y actualizar configuración del sistema                       |

### Fase 3: Migración de autenticación

| Tarea | Descripción                                         | Dependencias | Resultado esperado                                                           |
| ----- | ---------------------------------------------------- | ------------ | ---------------------------------------------------------------------------- |
| 3.1   | Crear primer administrador en `users.json`         | 1.8          | Usuario `admin` con hash de contraseña migrado desde `credenciales.php` |
| 3.2   | Modificar `login.php` para usar `AuthService`    | 2.1, 3.1     | Login valida contra `users.json`, registra eventos de auditoría           |
| 3.3   | Verificar que `check-session.php` siga funcionando | 3.2          | Sesión de admin reconocida correctamente                                    |
| 3.4   | Probar login exitoso y fallido                       | 3.2          | Ambas funciones funcionan con el nuevo almacenamiento                        |
| 3.5   | Probar rate limiting con el nuevo sistema            | 3.2          | Rate limiting funciona contra `users.json`                                 |

### Fase 4: Persistencia de operaciones administrativas

| Tarea | Descripción                                                                    | Dependencias | Resultado esperado                                         |
| ----- | ------------------------------------------------------------------------------- | ------------ | ---------------------------------------------------------- |
| 4.1   | Modificar `saveEdificio.php` para usar `AuthService` y registrar auditoría | 2.1, 1.6     | Guardado de edificios verifica permisos y registra cambios |
| 4.2   | Modificar `send-suggestion.php` para almacenar en `suggestions.json`        | 2.2          | Sugerencias se guardan además de enviarse por correo      |
| 4.3   | Verificar que `imagelist.php` siga protegido                                  | 1.6          | Listado de imágenes requiere sesión de admin             |

### Fase 5: Documentación y validación

| Tarea | Descripción                                                                       | Dependencias | Resultado esperado                                                 |
| ----- | ---------------------------------------------------------------------------------- | ------------ | ------------------------------------------------------------------ |
| 5.1   | Actualizar `AGENTS.md` con nueva estructura                                      | 5.2          | Documentación refleja repositorios, servicios y archivos de datos |
| 5.2   | Actualizar `docs/architecture/ARQUITECTURA_PERSISTENCIA_JSON.md` si es necesario | Ninguno      | Arquitectura documentada y actualizada                             |
| 5.3   | Pruebas manuales de flujos completos                                               | 3.5, 4.2     | Login, guardado de edificios, sugerencias y auditoría funcionan   |

---

## 8. Migración de Credenciales

### Procedimiento paso a paso

1. **No modificar `credenciales.php` aún.** Mantenerlo como fallback durante la migración.
2. Crear `PHP/data/users.json` con el primer usuario administrador:
   - `id`: `admin_001`
   - `username`: `admin`
   - `email`: vacía o `admin@itllaguna.local`
   - `password_hash`: el mismo hash que está en `credenciales.php` (`password_hash('123456', PASSWORD_DEFAULT)`)
   - `role`: `administrador`
   - `status`: `activo`
   - `created_at`: fecha actual ISO 8601
   - `updated_at`: fecha actual ISO 8601
   - `last_login_at`: `null`
   - `schema_version`: `1`
3. Modificar `login.php` para intentar autenticar primero contra `UserRepository` (users.json). Si falla, intentar contra `credenciales.php` como fallback temporal.
4. Probar login con usuario `admin` y contraseña `123456`.
5. Una vez verificado que funciona, `credenciales.php` puede dejarse como archivo sin uso o eliminarse en una fase posterior (no en esta etapa).

### Cómo evitar perder el acceso al panel

- Mantener `credenciales.php` intacto hasta que se verifique que el login contra `users.json` funciona.
- Tener una copia de seguridad de `credenciales.php` antes de cualquier cambio.
- Probar el login en un navegador separado antes de cerrar la sesión activa actual.

---

## 9. Estrategia de Respaldos

- Los archivos JSON en `PHP/data/` pueden copiarse manualmente como respaldo.
- No se implementa respaldo automático en esta fase.
- Procedimiento de restauración: copiar el archivo JSON de respaldo sobre el archivo activo.
- Se recomienda respaldar antes de cualquier migración de credenciales o cambios masivos.

---

## 10. Pruebas

### 10.1 Pruebas manuales

| Prueba                             | Paso                                                        | Resultado esperado                                         |
| ---------------------------------- | ----------------------------------------------------------- | ---------------------------------------------------------- |
| Login con usuario migrado          | Ir a `index.php?p=login`, ingresar `admin` / `123456` | Redirigir a panel, sesión iniciada                        |
| Login con credenciales incorrectas | Ingresar contraseña incorrecta                             | Mostrar error "Credenciales incorrectas"                   |
| Rate limiting                      | 5 intentos fallidos seguidos                                | Bloquear por tiempo definido                               |
| Guardado de edificio               | Editar un edificio en el panel                              | Cambios guardados en `datos.json` y evento en auditoría |
| Sugerencia desde mapa              | Enviar formulario de sugerencias                            | Correo enviado + registro en `suggestions.json`          |
| Acceso sin sesión                 | Ir a `index.php?p=editar` sin login                       | Redirigir a login                                          |
| Archivos fuera de HTTP             | Intentar acceder a `PHP/data/users.json` directamente     | No accesible o 404                                         |

### 10.2 Pruebas de integridad

- Verificar que `users.json` siga siendo JSON válido después de múltiples operaciones.
- Verificar que `audit-log.jsonl` tenga una línea JSON por evento.
- Verificar que no se escriban contraseñas en texto plano en ningún archivo.

---

## 11. Criterios de Aceptación

- [X] `PHP/data/users.json` existe y contiene al menos un usuario administrador válido.
- [X] Login funciona contra `users.json` (y solo contra `users.json`).
- [X] `saveEdificio.php` registra eventos de auditoría al guardar edificios.
- [X] `send-suggestion.php` almacena sugerencias en `suggestions.json`.
- [X] `audit-log.jsonl` registra login exitosos, fallidos y cambios administrativos.
- [X] `PHP/data/` no es accesible directamente vía HTTP.
- [X] `.gitignore` excluye `PHP/data/`.
- [X] `AGENTS.md` documenta la nueva estructura de archivos.
- [X] Mapa público (`index.html`) funciona sin cambios.
- [X] Credenciales hardcodeadas en `credenciales.php` se eliminan en esta etapa (ya no se mantienen como fallback).

---

## 12. Riesgos

| Riesgo                                         | Impacto | Probabilidad | Mitigación                                                                   |
| ---------------------------------------------- | ------- | ------------ | ----------------------------------------------------------------------------- |
| Corrupción de JSON al escribir                | Medio   | Baja         | Usar `flock()`, archivo temporal y reemplazo atómico (`rename()`).       |
| Pérdida de acceso al panel durante migración | Alto    | Baja         | Mantener `credenciales.php` como fallback hasta verificar el nuevo sistema. |
| Concurrencia en Windows                        | Medio   | Baja         | `flock()` funciona; volumen bajo de operaciones hace que sea aceptable.     |
| Archivos JSON crecen sin límite               | Bajo    | Media        | `audit-log.jsonl` crece linealmente; rotación se evalúa en fase futura.   |

---

## 13. Orden de Ejecución

```
Fase 1 (Infraestructura) → Fase 2 (Servicios) → Fase 3 (Migración auth) → Fase 4 (Persistencia operaciones) → Fase 5 (Documentación y pruebas)
```

Cada fase debe completarse y verificarse antes de pasar a la siguiente. No se avanza si las pruebas de la fase actual fallan.

---

## 14. Decisiones que Requieren Aprobación

1. **Estructura de `users.json`:** ¿Es aceptable usar `password_hash()` del PHP para las contraseñas en el archivo JSON?
2. **Ubicación de `PHP/data/`:** ¿Está bien dentro de `PHP/` pero fuera de `PHP/public/`?
3. **Mantener `credenciales.php` como fallback:** ¿Se aprueba no eliminarlo en esta etapa?
4. **Formato JSONL para auditoría:** ¿Es aceptable para el volumen esperado?
5. **Timeout de sesión:** Ya configurado en 2 horas en Etapa 2. ¿Se mantiene?

---

## 15. Resumen de Entregables

| Documento               | Ruta                                                    | Estado                    |
| ----------------------- | ------------------------------------------------------- | ------------------------- |
| Arquitectura            | `docs/architecture/ARQUITECTURA_PERSISTENCIA_JSON.md` | Completo                  |
| Plan de implementación | `docs/plans/PLAN_IMPLEMENTACION_PERSISTENCIA_JSON.md` | Completo (este documento) |

**IMPORTANTE:** No se ha modificado ningún archivo PHP, JavaScript ni de configuración. Este es únicamente un plan de implementación para revisión y aprobación antes de comenzar el desarrollo.
