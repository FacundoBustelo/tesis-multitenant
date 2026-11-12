# Arnés experimental

`pilot-run --no-monitor` permite el diagnóstico pareado sin recolector periódico de Docker,
PostgreSQL ni FPM. El monitoreo sigue habilitado por defecto. Las capturas y contadores de
frontera, calentamiento y controles de integridad se conservan; el manifiesto registra
esta decisión. `restoration.json` acredita la restauración exacta previa a cada medición.
Los fallos de k6 conservan log, código de salida, muestras y validación posterior.

Estado: implementado. **Piloto y confirmatoria requieren ejecución explícita.** Los tests aislados usan HTTP simulado. La evidencia histórica del 2026-11-02 queda en `results/<estrategia>/integration-*/` y `results/integration-report.md`; corresponde a la agenda anterior. La agenda corregida tiene pruebas aisladas y requiere validación real posterior en una sesión nueva. Los comandos siguientes no se ejecutaron en esta corrección.

## Integración real previa al piloto

Este procedimiento usa exclusivamente F0. `init` construye las imágenes, migra, genera los quince registros y captura el snapshot; no ejecuta carga. El comando de integración comprueba aislamiento, rechazos de seguridad, identidades, hashes deterministas, autenticación y CRUD HTTP puntuales. Cambia y elimina registros intencionalmente y verifica dos restauraciones exactas, incluida auditoría, tokens y secuencias. k6 carga el script real con un VU y **seis iteraciones de lectura en total**, sin ventana de carga sostenida. Los manifiestos llevan `phase=integration`; esas muestras no son resultados piloto ni evidencia de capacidad.

```bash
python3 benchmarks/scripts/bench.py init --strategy shared_tables --profile F0 --session phase8-f0
python3 benchmarks/scripts/bench.py preflight --strategy shared_tables --session phase8-f0
python3 benchmarks/scripts/integration.py --strategy shared_tables --session phase8-f0
BENCH_MARKER=unused docker compose -f benchmarks/compose.yaml -p tesis-benchmark-shared-tables-phase8-f0 stop
```

Repetir esos pasos sustituyendo estrategia/proyecto por `separate_schemas`/`tesis-benchmark-separate-schemas-phase8-f0` y `separate_databases`/`tesis-benchmark-separate-databases-phase8-f0`. No reinicializar una sesión existente. La integración exige comenzar en el snapshot F0 intacto. El preflight solo lee; ante un rechazo inesperado, detener restauraciones y reconciliar la causa. Las pruebas negativas reales utilizan únicamente el clúster experimental (marcador/destino incorrecto y copia corrupta del último dump); los tests aislados cubren volúmenes de desarrollo e inventarios peligrosos sin conectarse a esos destinos.

La exportación CSV conserva corrida, bloque, estrategia y perfil. Los artefactos se revisan buscando contraseñas experimentales y los tokens temporales efectivamente emitidos. Los dumps contienen hashes de contraseñas de los actores sintéticos y se conservan privados; nunca contraseñas en claro ni tokens en el snapshot inicial. La integración termina con los datos restaurados y elimina su fixture privado de tokens; detener los servicios conserva volúmenes y evidencia.

## Preparación y comandos posteriores

Ejecutar desde la raíz del repositorio. Requiere Python 3.12, Docker Engine/Compose, acceso al daemon y dependencias de `src/vendor` instaladas. La imagen copia el código y vendor, excluye `.env`, almacenamiento y cachés PHP; no modifica `src`. Las imágenes iniciales pueden requerir descarga. Las credenciales son exclusivamente del entorno desechable, sin puertos publicados. Los resultados contienen datos locales y deben conservarse con acceso restringido.

Estos comandos **inician trabajo experimental y no se ejecutaron durante la implementación**:

```bash
# Sólo si todavía no se inicializó esa sesión: compila, migra y guarda F0.
python3 benchmarks/scripts/bench.py init --strategy shared_tables --profile F0 --session phase8-f0

# Ejecutar UNA corrida piloto. Restaura, emite tokens, calienta, verifica y mide.
python3 benchmarks/scripts/bench.py pilot-run --strategy shared_tables --session phase8-f0 --mode R --vus 1 --duration 120s --block piloto-01
python3 benchmarks/scripts/bench.py pilot-run --strategy shared_tables --session phase8-f0 --mode W --vus 1 --duration 120s --block piloto-01
python3 benchmarks/scripts/bench.py pilot-run --strategy shared_tables --session phase8-f0 --mode M --vus 1 --duration 120s --block piloto-01

# Exportar muestras de una corrida terminada (reemplazar RUTA_CORRIDA).
python3 benchmarks/scripts/export.py RUTA_CORRIDA
```

Para las otras estrategias, usar `--strategy separate_schemas` o `--strategy separate_databases` tanto en `init` como en `pilot-run`. Cada una tiene proyecto, red y volumen propios. Detener un proyecto antes de medir otro:

```bash
BENCH_MARKER=unused docker compose -f benchmarks/compose.yaml -p tesis-benchmark-shared-tables-phase8-f0 stop
```

No hay barrido automático de campañas ni comando confirmatorio. El perfil se elige al inicializar; una sesión no se resembra ni cambia de perfil. Una inicialización interrumpida conserva su rechazo de sesión/volumen existente y requiere revisión. Para otro código o perfil, usar un identificador nuevo. El arnés no proporciona limpieza indiscriminada ni borrado de volúmenes.

### Sesiones históricas y futuras

`--session phase8-f0` crea `results/sessions/phase8-f0/<estrategia>/`, proyecto `tesis-benchmark-<estrategia-con-guiones>-phase8-f0` y sus volúmenes propios. El identificador admite 1–32 letras minúsculas, dígitos o guiones y comienza con una letra. Debe repetirse en **todos** los comandos `bench.py` y `integration.py` dirigidos a esa sesión. Los manifiestos nuevos registran sesión y proyecto; fixtures de tokens, muestras y snapshots usan esa ruta. El mismo identificador permite agrupar las tres estrategias, pero cada una mantiene su proyecto separado.

Omitir `--session` conserva las rutas/proyectos históricos: no selecciona automáticamente la versión nueva. Las sesiones del 2026-11-02 y todos sus artefactos permanecen intactos; `pilot-run` con las fuentes actuales las rechaza por hashes distintos. No editar sus hashes, manifiestos ni dumps para evitar ese rechazo. `init --session` registra los hashes actuales en una sesión nueva y ejecutará la preparación habitual sólo cuando se autorice iniciar infraestructura. No se crearon sesiones ni snapshots nuevos durante esta corrección.

Para explorar D2 posteriormente, usar por ejemplo `--session phase8-d2 --profile D2` al inicializar; detener otros proyectos experimentales antes de medir. Una nueva sesión necesita su propio baseline, sin regenerar los dumps históricos. La inicialización y la integración de sesiones con nombre sólo se verificaron con dobles de prueba en esta fase; la comprobación real sigue pendiente.

### Agenda de lectura

R alterna por tenant cuatro posiciones: listado User, consulta User, listado Staff y consulta Staff sobre el mismo documento. Conserva un request por iteración. El ordinal local avanza al siguiente ID de `t.ids` después de esas cuatro posiciones y vuelve al inicio al agotar el inventario. M usa `12*floor(i/T)+j`, con tenant `i mod T`: doce lecturas sobre tres documentos con ambos roles y el ciclo POST/PATCH/DELETE, quince solicitudes y mezcla 80/20. No hay contadores mutables por VU ni consultas adicionales para seleccionar IDs. La agenda lógica es común a las tres estrategias.

La repetición por rol es deliberada; el recorrido es secuencial y favorece localidad, no representa selección aleatoria. Los listados mantienen la primera página de veinte. Para `T` tenants y `N` documentos por tenant, el período R tiene `4*T*N` solicitudes; M tiene `T*N/mcd(N,3)` bloques y `15*T*N/mcd(N,3)` solicitudes nominales. Cada documento aparece una vez por rol en R y `3/mcd(N,3)` veces por rol en el período de M. Las cifras por perfil y la primera cobertura de M se detallan en el [protocolo](../src/docs/benchmark-protocol.md). Cobertura potencial no significa cobertura observada durante una ventana limitada ni garantiza completar requests interrumpidos.

El calentamiento conserva veinte solicitudes por tenant (diez listados/diez consultas), reiniciando su ordinal local. La integración mínima conserva dos por tenant. Víctima y perturbador R conservan sus tenants fijos y un request por iteración; cada uno recorre el período de `4*N` lecturas con ambos roles. W, `me` y `auth` mantienen su comportamiento.

Autenticación y solicitud mínima:

```bash
python3 benchmarks/scripts/bench.py pilot-run --strategy shared_tables --session phase8-f0 --mode auth --block piloto-auth-01
python3 benchmarks/scripts/bench.py pilot-run --strategy shared_tables --session phase8-f0 --mode me --vus 8 --block piloto-me-01
```

Login usa un ciclo cada diez segundos durante diez minutos, siempre respetando el límite 10/min/IP. No sirve para estimar la capacidad máxima del login. CRUD usa tokens preemitidos de una hora; setup exige al menos quince minutos restantes. El banco contiene 128 tokens de cada tipo por tenant en todas las estrategias. La carga mixta emite bloques de quince solicitudes, por lo que `--rate` representa bloques/s; R representa solicitudes/s y W ciclos/s.

Interferencia, en corridas independientes restauradas:

```bash
# Víctima fija a una iteración/s, sin perturbador.
python3 benchmarks/scripts/bench.py pilot-run --strategy shared_tables --session phase8-f0 --mode interference --victim-rate 1 --noise-rate 0 --block aislamiento-01
# Misma víctima, perturbador de escritura a cinco ciclos/s.
python3 benchmarks/scripts/bench.py pilot-run --strategy shared_tables --session phase8-f0 --mode interference --victim-rate 1 --noise-rate 5 --noise-mode W --block aislamiento-01
```

Las tasas anteriores son ejemplos de uso, no capacidades medidas. Se calibrarán durante el piloto. Los tenants víctima/perturbador iniciales son los ordinales 1/2. k6 admite `VICTIM` y `NOISE_TENANT` para rotarlos al congelar la campaña; el CLI inicial mantiene ese par fijo.

## Restauraciones y estado comparable

El marcador del clúster, la sesión, el proyecto Docker, el volumen exacto, la ausencia de puertos DB, el propietario de cada base y todos los marcadores tenant se comprueban antes de restaurar. El inventario debe coincidir exactamente con el snapshot. Se valida el hash de **todos** los dumps antes de limpiar el primero. Con nginx/app detenidos se rechazan otros clientes activos; `pg_restore --clean --if-exists --exit-on-error` opera únicamente dentro del conjunto validado. No se usa `DROP DATABASE FORCE` ni patrones de borrado.

El snapshot se toma sin tokens y auditoría. Restaurar reproduce exactamente el estado capturado, incluidas secuencias; se ejecuta ANALYZE después. Cada corrida agrega el mismo número de tokens y aplica veinte lecturas auditadas por tenant, en orden, con un único VU. El calentamiento es de **trabajo fijo**, no de duración fija: la duración apropiada y el volumen de trabajo definitivo se decidirán con el piloto. No se promete residencia completa en caché.

La puerta previa a medición verifica hashes y cantidades de records, ausencia de residuos, tokens, auditoría por acción y tenant, secuencia activa de records y avance agregado de secuencias de auditoría. Los valores físicos de secuencias se conservan completos, aunque auditoría global y local necesitan normalización para compararse. Los chequeos de estado también acceden a datos y forman parte del acondicionamiento documentado de caché.

Para comparar archivos `measurement-start-state.json` de estrategias equivalentes:

```bash
python3 benchmarks/scripts/compare_states.py RUTA_SHARED/measurement-start-state.json RUTA_SCHEMAS/measurement-start-state.json RUTA_DATABASES/measurement-start-state.json
```

Una comparación fallida excluye ese bloque de la comparación confirmatoria hasta resolver la causa. La matriz confirmatoria deberá exigir esta comprobación entre sus integrantes; el piloto permite inspeccionarlos después de cada corrida.

## Resultados y observabilidad

Cada corrida guarda manifiesto y bloque, configuración piloto, hashes de código, imágenes/digests, esquema SQL, PHP/FPM, cgroups, PostgreSQL, host y estados inicial/final. Guarda muestras k6 comprimidas, resumen, recursos y `validation.json` con ciclos iniciados/completados/incompletos, diferencia iniciados menos completados, errores, iteraciones descartadas y records residuales identificados por título. Un POST con respuesta perdida puede dejar un residual: se detecta por inspección posterior y se elimina solamente al restaurar la próxima corrida.

Los contadores anteriores/posteriores corresponden a la ejecución de k6 y su drenaje; no son deltas exclusivos de una ventana HTTP exacta. El resumen k6 también incluye drenaje. Las muestras HTTP llevan `window=measurement|drain` según el inicio de solicitud y las muestras `observed_request_ms` según su finalización. Para throughput de completadas dentro de ventana usar timestamps y los límites de inicio de escenario exportados en `scenario_start_epoch_ms`; no dividir el total con drenaje por la duración configurada. Conservar esta distinción durante el análisis del piloto. La autenticación tiene su duración propia de 600 segundos.

Los ciclos completos se miden con `write_cycle_ms`; los endpoints tienen etiquetas POST/PATCH/DELETE. Errores de PATCH no impiden intentar DELETE, pero el ciclo queda incompleto. Una interrupción abrupta se refleja en iniciados menos completados incluso si no llegó a ejecutarse el contador de incompletos.

El muestreo de Docker solicita datos aproximadamente cada segundo y PostgreSQL/FPM cada cinco iteraciones; el costo de consulta puede alargar ese período. Se guardan timestamps reales y fallos del recolector. Durante el piloto se evaluará la perturbación del monitoreo. `pg_stat_io` y WAL son del clúster, no del tenant; FPM tiene cinco workers y su cola puede dominar desde ocho VUs. No se atribuirán automáticamente esos efectos a PostgreSQL.

## Tests sin campañas

```bash
python3 -m unittest discover -s benchmarks/tests -v
node benchmarks/tests/workload.test.cjs
php benchmarks/tests/payload.php
php -l benchmarks/fixtures/manage.php
php -l benchmarks/fixtures/data.php
sh -n benchmarks/init-db.sh
BENCH_MARKER=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa docker compose -f benchmarks/compose.yaml -p tesis-benchmark-validation config --quiet
```

Estos tests no crean fixtures PostgreSQL ni envían solicitudes reales. El piloto posterior debe confirmar las imágenes, permisos efectivos, migraciones, atomicidad de restauraciones, sintaxis/compatibilidad real de k6, estabilidad, precisión de las ventanas y recursos del generador. Un rechazo de la puerta de seguridad es un fallo explícito, no una autorización para relajarla.
