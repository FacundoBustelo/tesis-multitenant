# PoC: tablas compartidas, esquemas separados y bases independientes

Laravel 13, PHP 8.4, PostgreSQL 16 y Nginx. `TENANCY_STRATEGY=shared_tables|separate_schemas` selecciona tablas compartidas (predeterminado) o un esquema por tenant en la misma base. Ambas variantes conservan API, permisos, `tenant_id`, scopes y auditoría transaccional. Compose, Dockerfile, contrato `RecordRepository` y migraciones originales se conservan. La tercera variante `separate_databases` se describe más abajo. Ninguna variante traslada documentos al cambiar estrategia.

## Ejecutar

Todos los comandos siguientes se ejecutan desde la raíz del repositorio (directorio de `compose.yaml`).

```bash
docker compose up -d --build
docker compose exec -T app composer install --no-interaction
```

Para una instalación nueva, si no existe `src/.env`:

```bash
cp src/.env.example src/.env
docker compose exec -T app php artisan key:generate
```

El ejemplo configura `DB_CONNECTION=pgsql`, `DB_HOST=db`, `DB_PORT=5432`, `DB_DATABASE=tesis`, `DB_USERNAME=tesis`, `DB_PASSWORD=tesis_local` y URL `http://localhost:8080`. Conservar una clave existente. Después de cambios de configuración:

```bash
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan migrate --no-interaction
docker compose exec -T app php artisan db:seed --no-interaction
docker compose exec -T app php artisan route:list --path=api
```

La migración incremental se detiene si encuentra usuarios existentes: deben recibir una asignación explícita a organizaciones mediante una migración de datos antes de introducir el NOT NULL. No asigna datos preexistentes a una organización arbitraria. No es necesario `migrate:fresh` en desarrollo.

## Selección e instalación de la estrategia

En `src/.env` configurar `TENANCY_STRATEGY=shared_tables` o `TENANCY_STRATEGY=separate_schemas`. Después de cambiarla, limpiar la configuración y reiniciar procesos persistentes si los hubiera. `tenancy:install` aplica las migraciones centrales pendientes, incluidas las originales, el registro `public.tenant_schemas` y una secuencia exclusiva para records de esquemas; es repetible y no reconstruye tablas existentes.

```bash
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan tenancy:install --no-interaction
```

Las identidades, `tenants`, tokens Sanctum y metadatos de provisionamiento siempre viven en `public`. Las tablas originales `public.records` y `public.audit_logs` siguen disponibles para la estrategia 1. En la estrategia 2 cada `tenant_<ID>` contiene `records`, `audit_logs` y `migrations`. Los nombres se generan desde IDs internos positivos, se validan contra el tenant exacto y se citan como identificadores SQL. Los slugs recibidos en login nunca se usan como nombres de esquema.

Para organizaciones ya existentes en el directorio central:

```bash
docker compose exec -T app php artisan tenancy:provision --tenant=alpha --no-interaction
docker compose exec -T app php artisan tenancy:migrate --tenant=alpha --no-interaction
docker compose exec -T app php artisan tenancy:migrate --all --no-interaction
```

`tenancy:provision` crea el esquema y aplica sus migraciones pendientes. `tenancy:migrate` exige exactamente `--tenant=slug` o `--all`; este último sólo recorre tenants registrados. No crea organizaciones ni adopta esquemas sin registrar. El seeder de PoC crea el directorio de fixtures y, bajo `separate_schemas`, los provisiona administrativamente antes de insertar documentos. No hay endpoints nuevos ni provisionamiento automático en solicitudes.

El provisionamiento y cada lote de migraciones se ejecutan en una transacción PostgreSQL con advisory lock por tenant y bloqueo de la fila central. DDL, estado e historial se confirman juntos. Un fallo revierte el lote y permite reintentarlo; un esquema incompleto o con migraciones pendientes se rechaza al resolver el contexto. Cada esquema mantiene su propio historial y lotes, sin duplicados al repetir comandos. Las migraciones por tenant se agregan a `database/tenant-migrations` y exponen `up(string $schema, int $tenantId)`; deben usar la conexión predeterminada y DDL transaccional. Migrar todos los tenants es una serie de transacciones independientes: si uno falla, los anteriores pueden haber confirmado y se puede reintentar.

Cambiar la estrategia selecciona otro conjunto de documentos y auditoría; no copia ni fusiona datos. Identidades y tokens siguen siendo centrales. Un tenant sin esquema disponible falla de forma cerrada (500 en la API, sin emitir token en login), después de verificar las credenciales; credenciales inválidas continúan devolviendo 401. El administrador debe provisionar o migrar antes de habilitar tráfico hacia esa estrategia.

## Modelo y permisos

- `tenants`: directorio de organizaciones, slug único.
- `users`: identidades de consulta, una organización por usuario.
- `staff`: identidades administrativas separadas, una organización por identidad. No existe privilegio de superadministrador global ni vínculo obligatorio con `users`.
- `records`: documentos genéricos de la organización, con título y contenido opcional. No pertenecen a un usuario particular: todos los usuarios de la organización pueden leerlos.
- `audit_logs`: tenant, actor ID, tipo `user`/`staff`, acción, tipo/ID de recurso y timestamp UTC. No guarda credenciales, tokens ni contenido de documentos.
- `personal_access_tokens`: tokens Sanctum almacenados como hash, con expiración de una hora. Logout revoca el token actual.

Un mismo correo puede existir en distintos tenants y en ambos tipos de identidad. Es único por tenant dentro de cada tabla de actores. Una identidad de usuario no se convierte en staff por cambiar un encabezado o un parámetro.

| Acción sobre records | User | Staff |
|---|---|---|
| Listar / consultar dentro del tenant | Sí | Sí |
| Crear / editar / eliminar dentro del tenant | No, 403 | Sí |
| Consultar / editar / eliminar ID de otro tenant | 404 | 404 |

## API v1

Todas las respuestas son JSON. Para rutas protegidas enviar `Authorization: Bearer TOKEN` y `Accept: application/json`.

| Método | Ruta | Resultado |
|---|---|---|
| POST | `/api/v1/auth/users/login` | Autenticar usuario |
| POST | `/api/v1/auth/staff/login` | Autenticar staff |
| GET | `/api/v1/auth/me` | Identidad actual y tipo |
| POST | `/api/v1/auth/logout` | Revocar token, 204 |
| GET | `/api/v1/records` | Paginación, `per_page` 1–100, predeterminado 20 |
| GET | `/api/v1/records/{id}` | Consultar registro |
| POST | `/api/v1/records` | Crear registro, 201 |
| PUT / PATCH | `/api/v1/records/{id}` | Actualización parcial, 200 |
| DELETE | `/api/v1/records/{id}` | Eliminar, 204 |

Login recibe `tenant` (slug), `email` y `password`. El slug es un selector público: las credenciales se verifican contra esa organización y ese tipo de actor. Una vez autenticado, el tenant procede exclusivamente de la identidad recuperada por Sanctum. Se ignoran encabezados de selección de tenant y filtros de pertenencia en el listado. Las escrituras rechazan `tenant_id`, `user_id`, `staff_id` e `id` no vacíos. Sólo persisten atributos validados. Título obligatorio al crear, máximo 200 caracteres; contenido nullable, máximo 10000 caracteres. Credenciales inválidas: 401; validación: 422; login limitado a 10 solicitudes/minuto/IP: 429.

Ejemplo con fixtures locales:

```bash
curl -sS -X POST http://localhost:8080/api/v1/auth/staff/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"tenant":"alpha","email":"staff@example.test","password":"Poc-password-2026!"}'
```

Copiar el campo `token` de la respuesta:

```bash
TOKEN='pegar-token-aqui'
curl -sS http://localhost:8080/api/v1/records -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
curl -sS -X POST http://localhost:8080/api/v1/records \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"title":"Documento de prueba","content":"Contenido genérico"}'
curl -sS -X POST http://localhost:8080/api/v1/auth/logout -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

## Controles de aislamiento y trazabilidad

1. Sanctum autentica sólo tokens Bearer en esta API; no usa la sesión web como alternativa. Mapa polimórfico explícito `user`/`staff`.
2. `ResolveTenant` establece un contexto scoped desde el actor autenticado y lo limpia en `finally`. No hay tenant predeterminado. `Record` y `AuditLog` fallan al consultar sin contexto.
3. Scope global agrega el predicado tenant a consultas Eloquent de records/auditoría. Las búsquedas por ID se realizan dentro del repositorio después del middleware; no hay binding implícito previo al contexto.
4. Eventos del modelo impiden reasignar tenant y guardar/eliminar instancias ajenas. Las políticas vuelven a comprobar tenant y rol.
5. NOT NULL y FK en pertenencias; unicidad `(tenant_id,email)` y `(tenant_id,id)`; auditoría con FK compuestas a actores y CHECK que exige un único actor consistente. Un actor de otro tenant no puede insertarse en la auditoría.
6. Índices `(tenant_id,id)` para listado/búsqueda, y `(tenant_id,created_at)` / `(tenant_id,resource_type,resource_id)` para trazabilidad.
7. Login, logout, listado, consulta, creación, actualización y eliminación exitosos generan eventos. Escritura del evento y operación comparten transacción: un fallo del logger revierte la mutación. `record.list` representa la consulta paginada, sin enumerar cada resultado. Al eliminar un registro se conserva su ID en auditoría sin FK hacia el documento eliminado.

`User`, `Staff` y `Tenant` son el directorio de identidad y no tienen scope global: es necesario buscar credenciales antes de tener contexto. Su uso debe quedar restringido a autenticación/aprovisionamiento; no hay endpoints que permitan listarlos o administrarlos.

## Capa intercambiable

`RecordController` depende de `RecordRepository`; el provider selecciona `SharedTableRecordRepository` o `SeparateSchemaRecordRepository`. Los adaptadores comparten CRUD Eloquent y delegan la selección física a `TenantTableResolver`. `Record` y `AuditLog` resuelven tablas calificadas (`public.records` o `tenant_<ID>.records`, por ejemplo). No se modifica `search_path`. Autenticación, tokens, documentos y auditoría usan la misma conexión predeterminada; las transacciones existentes conservan atomicidad incluso entre `public` y un esquema de tenant.

`TenantContext` se establece desde el actor autenticado, en login únicamente después de validar sus credenciales, y se limpia en `finally`. Al seleccionar un esquema verifica registro, estado, tablas/columnas esperadas e historial de migraciones. Las instancias y consultas capturan el tenant al crearse y rechazan su reutilización bajo otro tenant, incluso si una consulta ya se ejecutó o se convirtió al builder base. Se mantienen los scopes, políticas y comprobaciones de pertenencia existentes. Cada tabla de un esquema agrega también un CHECK que fija su `tenant_id`, además de las FK centrales y las restricciones de auditoría equivalentes.

Ambas variantes dependen de una secuencia central para asignar IDs de records globales dentro de su estrategia. `shared_tables` conserva `public.records_id_seq`; `separate_schemas` usa `public.tenant_records_id_seq` con `INCREMENT 1 CACHE 1`, mediante `DEFAULT nextval(...)` en PostgreSQL. No hay una consulta Laravel adicional para reservar IDs ni se consume la secuencia original al usar esquemas. Así un ID ajeno sigue devolviendo 404 en lugar de coincidir con un ID local. Los IDs pueden tener huecos tras rollback y las dos secuencias pueden producir números coincidentes entre estrategias. La secuencia nueva está vinculada al ciclo de vida del registro central; una reversión de esas migraciones centrales requiere retirar primero los esquemas dependientes.

## Fixtures reproducibles

`DatabaseSeeder` crea `alpha`, `beta`, `gamma`, con un usuario, un staff y cinco registros cada uno (3/3/3 identidades y tenants, 15 documentos en total). En cada organización: `user@example.test`, `staff@example.test`, contraseña `Poc-password-2026!`. Exclusivamente datos de PoC local. Repetir el seeder no duplica fixtures ni reinicia credenciales existentes; no restaura documentos modificados manualmente. No depende de IDs específicos ni de timestamps fijos. Los seeders no simulan operaciones de API y no generan auditoría de esas operaciones.

## Pruebas sobre PostgreSQL 16

Preparar primero el rol exclusivo descrito en «Preparación segura de pruebas» y crear una vez la base dedicada:

```bash
docker compose exec -T db createdb -U tesis -O tesis_test_runner tesis_test
```

Si ya existe, `createdb` informará ese hecho; continuar con las pruebas. `phpunit.xml` fuerza conexión PostgreSQL/base `tesis_test`. La suite usa `RefreshTenantDatabase`, que comprueba ambiente `testing`, driver, nombre configurado y **`current_database()` de la conexión efectiva**, exigiendo exactamente `tesis_test` antes de `RefreshDatabase` y de cada limpieza específica. La limpieza valida todos los nombres antes de ejecutar DDL, elimina sólo los esquemas registrados y la secuencia de pruebas conocida, y luego reconstruye las tablas centrales. Un nombre inválido o una discrepancia de conexión aborta antes de cualquier DDL destructivo. Las configuraciones incorrectas se prueban con dobles de conexión, sin tocar desarrollo. Agregar pruebas de integración con el mismo trait protegido. No utilizar una base con datos que se quieran conservar como base de pruebas. No requiere SQLite.

```bash
docker compose exec -T app php artisan config:clear
docker compose exec -T -e TENANCY_STRATEGY=shared_tables app php artisan test --compact
docker compose exec -T -e TENANCY_STRATEGY=separate_schemas app php artisan test --compact
docker compose exec -T app vendor/bin/pint app database routes tests bootstrap/app.php bootstrap/providers.php config/sanctum.php --format agent
```

Las pruebas ejercitan tokens reales y reinician los guards entre solicitudes del mismo proceso de PHPUnit para reproducir solicitudes HTTP independientes. Cubren CRUD, aislamiento de los tres tenants y ambos actores, permisos, manipulación de pertenencia, credenciales dentro del tenant, tokens inválidos/expirados/revocados, rate limiting, validación, contexto ausente, políticas con instancias ajenas, FK compuestas, NOT NULL, unicidad, auditoría sin secretos y rollback cuando falla la auditoría. Incluyen explícitamente una prueba del bypass mediante SQL directo.

Verificación del 2026-10-30 sobre PostgreSQL 16.15 (comandos de la matriz anterior):

| Etapa / estrategia | Aprobadas | Omitidas | Aserciones | Fallos finales |
|---|---:|---:|---:|---:|
| Línea base original, `shared_tables` | 19 | 0 | 177 | 0 |
| Selección del adaptador, `shared_tables` | 19 | 0 | 177 | 0 |
| Matriz final, `shared_tables` | 38 | 12 | 246 | 0 |
| Matriz final, `separate_schemas` | 50 | 0 | 334 | 0 |

Los 17 casos originales de `TenantIsolationTest` se conservan y se ejecutan sobre ambas variantes; sus consultas físicas seleccionan la tabla de la estrategia. La batería nueva comprueba atomicidad de creación/edición/eliminación y auditoría ya escrita, rollback de tokens en login/logout, alternancia de tres tenants sobre el mismo backend PostgreSQL sin cambiar `search_path`, limpieza tras excepciones, consultas e instancias reutilizadas, identificadores inválidos, esquema no disponible, equivalencia de índices, restricciones físicas y repetibilidad/recuperación de comandos. Los 12 casos omitidos en tablas compartidas verifican exclusivamente esquemas. Las 38 restantes incluyen la batería común y pruebas unitarias de seguridad.

Durante la integración se detectaron y corrigieron dos fallos: el constructor de consultas auxiliares de Laravel necesitaba conservar el tenant y la secuencia nueva sobrevivía a `migrate:fresh` por no tener dependencia de ciclo de vida. La matriz final es posterior a esas correcciones. Los tiempos de PHPUnit no son resultados de rendimiento. No se ejecutaron benchmarks. La ficha para futuros experimentos está en [docs/benchmark-protocol.md](docs/benchmark-protocol.md).

## Límites y comparación experimental pendiente

- Ambas estrategias dependen de Laravel para seleccionar el tenant y autorizar. Los esquemas separan objetos y su resolución; los privilegios PostgreSQL determinan cuáles puede consultar el rol. El rol actual puede acceder a todos los esquemas, por lo que esta separación no impide SQL arbitrario autorizado por ese rol. Las tablas compartidas tampoco aplican aislamiento de permisos por fila en PostgreSQL. No se activó RLS. SQL directo, `withoutGlobalScopes`, operaciones masivas y accesos administrativos pueden evitar scopes y eventos Eloquent. Las FK impiden referencias inválidas, no lecturas de otro tenant. El usuario PostgreSQL de Compose tiene privilegios elevados: no representa una configuración de producción.
- Los eventos de auditoría son inmutables mediante instancias Eloquent, pero SQL directo/administradores pueden alterarlos. No hay firma, cadena de hashes, almacenamiento externo inmutable ni política de retención.
- No se auditan intentos rechazados (401/403/404/422), fallos de login ni `/auth/me`; no existe endpoint público de auditoría. Los eventos describen operaciones aceptadas, no prueba de entrega HTTP. No se guarda cuerpo anterior/posterior ni un request ID para correlación distribuida.
- No hay registro público, invitaciones, recuperación de contraseña, MFA, bloqueo de cuentas ni administración de pertenencias. Un cambio administrativo de tenant exigiría revocar los tokens existentes. El reset de contraseña del scaffold no tiene endpoints y no está adaptado al correo por tenant.
- No se implementaron bloqueos de edición ni control de versiones: actualizaciones concurrentes siguen last-write-wins. No se probaron Octane, workers persistentes ni fallos de infraestructura; deben verificarse antes de usarlos.
- Para comparar estrategias, seguir la ficha reproducible y conservar la misma API, permisos, fixtures, paginación, auditoría, carga de datos, distribución de tenants, concurrencia, recursos Docker, versiones e índices equivalentes. Medir por separado autenticación y CRUD con tokens preemitidos. Mantener las lecturas auditadas en las tres variantes: agregan escrituras y costo transaccional.
- Pendientes: harness de carga, medición p50/p95, throughput, tasas de error y CPU/memoria/IO, calentamiento, repeticiones y reporte de configuración. Los tiempos de PHPUnit son tiempos de verificación, no métricas experimentales. No se generaron resultados de rendimiento.

## Archivos de la implementación

- Selección y contexto: `config/tenancy.php`, `app/Tenancy/{TenancyStrategy,TenantContext,TenantTableResolver,TenantQueryBuilder,SchemaName,SchemaRegistry,TenantProvisioner}.php` y `app/Providers/AppServiceProvider.php`.
- Persistencia: adaptadores en `app/Repositories`, modelos centrales en `app/Models`, resolución y pertenencia en `app/Models/Concerns/BelongsToTenant.php`. Contrato, endpoints y políticas originales conservados.
- Administración: `app/Console/Commands/Tenancy{Install,Provision,Migrate}.php`, migración central incremental `database/migrations/2026_10_30_220000_create_tenant_schema_registry.php` y migraciones por esquema en `database/tenant-migrations`. Las migraciones anteriores permanecen intactas.
- Fixtures y verificación: `database/seeders/DatabaseSeeder.php`, `phpunit.xml`, `tests/Feature/{TenantIsolationTest,TenantRuntimeTest,SeparateSchemasTest}.php`, `tests/Unit/{TestDatabaseGuardTest,SchemaNameTest}.php`, `tests/Concerns/RefreshTenantDatabase.php`, `tests/Support/TestDatabaseGuard.php` y una migración de prueba en `tests/Fixtures`.

El repositorio tenía todos los archivos de la aplicación sin seguimiento en Git al comenzar esta implementación; no hay un commit base de la aplicación contra el cual informar un diff completo. Boost ya estaba instalado y se ejecutó su instalación de guidelines como preparación requerida por `AGENTS.md`. No se agregaron dependencias.

## Tercera estrategia: bases independientes

`TENANCY_STRATEGY=separate_databases` conserva API, contrato de repositorio y permisos.
Se utiliza el mismo servicio PostgreSQL: `tesis` contiene identidad, tokens, registro
`tenant_databases` y auditoría de autenticación; `tesis_tenant_<ID>` contiene records,
auditoría CRUD, metadatos de pertenencia e historial de migraciones. Las tablas de
las otras estrategias permanecen disponibles; cambiar estrategia no traslada datos.

Después de seleccionar la estrategia y limpiar configuración:

```bash
docker compose exec -T app php artisan tenancy:install --no-interaction
docker compose exec -T app php artisan tenancy:provision --tenant=alpha --no-interaction
docker compose exec -T app php artisan tenancy:migrate --all --no-interaction
docker compose exec -T app php artisan db:seed --no-interaction
```

El seeder crea alpha/beta/gamma y sus bases con cinco records cada una. No provisiona
solicitudes HTTP. Los nombres derivan del ID central, nunca del slug o encabezados.
El runtime verifica estado ready, base efectiva, marcador, columnas e historial.
Cada contexto abre un alias único; al cerrarlo revierte transacciones pendientes,
purga la conexión y configuración y bloquea su reconexión. Modelos y builders no se
pueden reutilizar entre contextos. No se cambia la conexión predeterminada.

CREATE DATABASE requiere privilegios administrativos y ocurre fuera de transacciones.
Un advisory lock de sesión serializa comandos por tenant. Las migraciones de
`database/tenant-database-migrations` reciben conexión e ID explícitos y se confirman
con su historial local. Un fallo de lote deja estado failed y permite reintentar.
Una interrupción antes de crear el marcador necesita reconciliación administrativa:
comprobar registro, propietario y procedencia; no adoptar ni eliminar automáticamente
bases desconocidas. Ejecutar migraciones con el tráfico de ese tenant detenido.

La auditoría de autenticación confirma con los tokens en la central; CRUD confirma
con auditoría local. Un error del logger revierte ambas operaciones dentro de su base.
No existe atomicidad entre bases, ni FK PostgreSQL entre bases independientes.
Las referencias locales a tenant/actor son históricas y se validan en la aplicación;
CHECK y NOT NULL locales no reemplazan FK centrales. Cambios administrativos de
pertenencia/eliminación exigen coordinación y revocación de tokens. No hay 2PC ni
outbox; una futura consolidación de auditoría requeriría entrega idempotente.

Los IDs se reservan con una secuencia central exclusiva antes de insertar el record:
conservan números globalmente únicos dentro de la estrategia, agregan una consulta
central por creación y pueden tener huecos. Identidad y asignación de IDs siguen
siendo dependencias centrales. PostgreSQL comparte recursos entre todas las bases.
Los workers deben abrir/cerrar contexto por tarea; Octane concurrente no se declara
compatible sin validación específica de su ciclo de ejecución.

### Preparación segura de pruebas

PHPUnit usa exclusivamente el rol `tesis_test_runner` (LOGIN CREATEDB, sin SUPERUSER,
CREATEROLE ni membresía en roles de desarrollo), contraseña local `test_only_local`.
Preparar una vez el rol y crear `tesis_test` con ese propietario. Si ya existe con
otro propietario, reconstruir únicamente esa base desechable administrativamente;
no usar desarrollo para pruebas. No otorgar al rol propiedad de bases de desarrollo.

```bash
docker compose exec -T db psql -U tesis -d postgres -v ON_ERROR_STOP=1 -c "CREATE ROLE tesis_test_runner LOGIN CREATEDB PASSWORD 'test_only_local';"
docker compose exec -T db createdb -U tesis -O tesis_test_runner tesis_test
docker compose exec -T -e TENANCY_STRATEGY=shared_tables app php artisan test --compact
docker compose exec -T -e TENANCY_STRATEGY=separate_schemas app php artisan test --compact
docker compose exec -T -e TENANCY_STRATEGY=separate_databases app php artisan test --compact
```

La tercera matriz usa bases `tesis_test_<ejecución>_tenant_<ID>` y reconstruye fixtures
entre casos: un rollback central no limpia bases independientes. La limpieza verifica
ambiente, base central efectiva, nombres exactos, propietario y marcadores de todo el
conjunto antes de borrar. No usa wildcard ni DROP DATABASE FORCE. Ante un fallo de
proceso que deje bases registradas, `php tests/recover-test-databases.php` recupera el
ID de ejecución registrado y aplica las mismas verificaciones; si no coinciden,
requiere revisión administrativa. El rol de pruebas no es propietario de desarrollo.

El protocolo de benchmarks contiene las diferencias físicas y transaccionales de
las tres variantes. No se ejecutaron benchmarks ni se estimaron resultados.

Verificación final del 2026-11-02, matriz de corrección (no benchmarks):

| Estrategia | Aprobadas | Omitidas específicas de otras estrategias | Aserciones |
|---|---:|---:|---:|
| `shared_tables` | 38 | 23 | 246 |
| `separate_schemas` | 50 | 11 | 334 |
| `separate_databases` | 49 | 12 | 337 |

Pint también pasó. Las pruebas específicas de bases verifican destinos físicos,
IDs únicos, equivalencia de índices, cierre y reutilización de recursos, CHECK,
estados pendientes, rechazo de bases sin registro, marcadores manipulados,
recuperación de migraciones y exclusión de provisionamiento desde otra sesión.
Las omisiones corresponden a estrategias distintas; la batería funcional común se
ejecuta en las tres. Las FK físicas entre actor y auditoría se prueban en las dos
primeras; en bases independientes se verifica el rechazo por la aplicación.
