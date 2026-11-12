# Protocolo experimental: rendimiento multi-tenant

Estado: arnés implementado; **piloto y confirmatoria pendientes, sin resultados de rendimiento**. La guía operativa está en [benchmarks/README.md](../../benchmarks/README.md).

## Objetivo y unidad experimental

Comparar `shared_tables`, `separate_schemas` y `separate_databases` tal como están implementadas. Mantener API, negocio, auditoría, autorización, índices y durabilidad. La unidad de réplica es una corrida independiente restaurada, nunca una solicitud individual. Los tres integrantes de un bloque comparten dataset, agenda, carga, duración y configuración. Se registran orden y bloque.

Las lecturas generan auditoría transaccional, incluida la paginación con COUNT y orden por ID. Login tiene límite de 10/min/IP. Tokens expiran a la hora. Bases separadas reservan IDs centrales y abren conexiones tenant; estos costos pertenecen a la implementación evaluada. No hay FK entre bases ni transacción distribuida. No se eliminarán esas diferencias ni se agregarán optimizaciones particulares.

## Dos fases con puerta explícita

### Campaña piloto

Su propósito es verificar el arnés, restauración, determinismo, estado comparable, disponibilidad de recursos, costo del monitoreo y estabilidad. Comenzar por F0 con un VU y R/W/M, seguido por las otras estrategias y autenticación. Después explorar D2, 1/8/32 VUs y ventanas de 120/300/600 segundos. Ninguna combinación es una matriz confirmatoria comprometida.

Inspeccionar dispersión entre corridas, series temporales, deriva, cola FPM, saturación del generador, tiempo de conexión, frecuencia efectiva de muestreo, registros residuales, datos incompletos y costo de captura. Comparar monitoreo habilitado frente a una corrida diagnóstica sin monitoreo antes de decidir su frecuencia definitiva. El arnés inicial incorpora monitoreo; esa comparación diagnóstica debe prepararse explícitamente tras la primera validación.

Condiciones para cerrar el piloto:

- Restauraciones y manifests íntegros en las tres estrategias; bloque con estados comparables.
- Ninguna respuesta de un tenant contiene registros ajenos; controles funcionales y de payload aprobados.
- Errores explicados, ciclos incompletos/residuales contabilizados y ausencia de contaminación con desarrollo.
- Generador con capacidad suficiente y sin OOM, pérdida de muestras o errores del recolector no explicados.
- Duración y acondicionamiento de caché justificados mediante estabilidad observada; no declarar caliente todo el dataset por defecto.
- Decisión documentada sobre cuotas, ubicación del generador, tasas, repeticiones y precisión estadística.

El informe del piloto debe identificar limitaciones y cerrar los parámetros antes de autorizar la confirmatoria. Los resultados piloto no se reutilizarán como réplicas confirmatorias.

### Campaña confirmatoria

**Matriz pendiente de fijación.** No existe comando que la ejecute. Después del piloto se versionará un archivo congelado con perfiles, escenarios, orden de estrategias, semillas, bloques, tasas, duración, calentamiento, cuotas y criterios de exclusión.

Como candidatos iniciales, no compromisos: seis bloques independientes usando las seis permutaciones de estrategias; D1–D4, R/W/M y concurrencia 1/8/32. Ampliaciones se deciden antes de medir, usando incertidumbre y presupuesto observados en el piloto. Para p99 se busca al menos 10.000 observaciones por operación/corrida; por debajo, reportarlo como exploratorio con su tamaño muestral.

## Datos y escenarios

| Perfil | Tenants | Records/tenant | Total |
|---|---:|---:|---:|
| F0 | 3 | 5 | 15 |
| D1 | 3 | 10.000 | 30.000 |
| D2 | 30 | 1.000 | 30.000 |
| D3 | 100 | 300 | 30.000 |
| D4 | 30 | 10.000 | 300.000 |

Semilla 20261102, un user y un staff por tenant, títulos ASCII de 80 bytes, contenido de 1.024 bytes salvo cada décimo documento nullable, timestamps iniciales fijos. Se preserva el contenido lógico con hash independiente de IDs físicos. El perfil F0 es un control funcional y su distribución nullable discreta no representa los perfiles grandes.

R: mitad listado primera página de veinte, mitad consulta por ID. W: POST/PATCH/DELETE sobre un record privado por ciclo. M: seis listados, seis consultas y un ciclo de escritura, 80/20 por solicitudes. Lecturas distribuidas entre user/staff; escribe staff. Un conjunto de lectura inmutable evita carreras con eliminaciones. Los payloads de escritura tienen título identificable y contenido de 1.024 bytes. Se miden operaciones y ciclo completo, errores, ciclos iniciados/completados/incompletos y residuos.

La agenda de lectura usa el inventario real `t.ids`, ordenado durante la preparación, sin calcular IDs físicos ni consultar otros durante la medición. Cada ordinal local de cuatro posiciones representa: listado User, consulta User, listado Staff y consulta Staff. Ambas consultas usan deliberadamente el mismo documento; después se avanza a la siguiente posición del inventario y se vuelve al inicio al agotarlo. Es una selección secuencial reproducible, con localidad de acceso explícita, no un muestreo aleatorio. Los listados continúan accediendo a la primera página. La misma agenda se aplica a las tres estrategias, incluso cuando sus IDs físicos difieran.

En R, para el índice global `i` y `T` tenants, `group=floor(i/4)`, tenant `group mod T` e índice local `4*floor(group/T)+(i mod 4)`. Cada iteración conserva un único request. En M, el tenant es `i mod T` y la lectura `j` utiliza `12*floor(i/T)+j`: cada bloque recorre tres documentos con ambos roles y conserva quince solicitudes, incluidas las tres escrituras. No hay contadores mutables por VU; el mismo ordinal selecciona el mismo tenant, posición y rol independientemente de su asignación a VUs. La concurrencia puede alterar el orden temporal de ejecución y finalización.

Para `N` documentos por tenant, R completa un período en `4*T*N` solicitudes, consultando cada documento una vez por rol. M repite su selección de lectura tras `T*N/mcd(N,3)` bloques, equivalentes a `15*T*N/mcd(N,3)` solicitudes nominales; cada documento se consulta `3/mcd(N,3)` veces por rol. Este período se refiere a la agenda de lectura: las escrituras crean registros temporales nuevos. La primera cobertura completa de M se alcanza tras `T*ceil(N/3)` bloques completos, aunque sus frecuencias pueden no ser iguales hasta cerrar el período.

| Perfil | Período R: solicitudes | Período M: bloques | Período M: solicitudes nominales |
|---|---:|---:|---:|
| F0 | 60 | 15 | 225 |
| D1 | 120.000 | 30.000 | 450.000 |
| D2 | 120.000 | 30.000 | 450.000 |
| D3 | 120.000 | 10.000 | 150.000 |
| D4 | 1.200.000 | 300.000 | 4.500.000 |

Estos valores demuestran cobertura potencial de períodos completos, no cobertura observada en ventanas de 120/300/600 segundos. No se exige completar esos períodos para cada corrida ni se infiere haber leído todo el dataset. Interrupciones y errores pueden impedir completar solicitudes previstas; deben conservarse en el reporte. El calentamiento reinicia el ordinal para cada tenant y mantiene veinte lecturas, diez listados y diez consultas; la integración mínima conserva dos lecturas por tenant. Víctima y perturbador R usan su ordinal local sobre sus tenants fijos y completan su período en `4*N` lecturas cada uno.

CRUD usa tokens reales preemitidos fuera de medición, sin quitar el costo de Sanctum. `/auth/me` caracteriza solicitud autenticada sin CRUD; no se restan sus percentiles. Login/logout se evalúan a seis ciclos/min/IP por diez minutos, separados de CRUD y sin desactivar límites. p99 de login será exploratorio por muestra pequeña.

k6 implementa carga cerrada y abierta. La tasa de M es bloques de quince requests/s; W son ciclos de tres. La confirmatoria fijará tasas comunes tras calibración piloto. Las iteraciones descartadas no son éxitos ni deben desaparecer del reporte.

## Interferencia y condiciones de ejecución

Mantener víctima con carga fija; variar sólo tasa y tipo R/W del perturbador. Comparar víctima sola contra perturbador bajo y alto, usando restauración y bloques equivalentes. Congelar tasas comunes y rotación de pares después del piloto. Reportar p95/p99 de víctima, throughput, errores y factor p95 con interferencia dividido por p95 sin ella.

**Las tres estrategias comparten CPU, RAM, WAL, disco y conexiones de una instancia PostgreSQL. La separación física de objetos no garantiza aislamiento de rendimiento.** Identidad y tokens siguen centrales. Esta evaluación no representa servidores independientes ni resistencia ante SQL privilegiado.

Usar infraestructura experimental con clúster, red, volumen y roles propios, sin conexión al Compose de desarrollo. No reutilizar `.env`, `postgres_data` ni `tesis_test`. Restaurar solamente destinos inventariados, con propietarios y marcadores verificados. No hay limpieza por wildcard.

La integración del 2026-11-02 corresponde a la agenda anterior y conserva sus resultados y hashes originales. La agenda corregida inicia sesiones nuevas con `--session`, que asigna rutas, proyectos Compose y volúmenes distintos; captura los hashes vigentes al inicializar y mantiene el rechazo si las fuentes cambian después. No se actualizan hashes históricos para habilitar corridas nuevas. La creación y validación real de estos entornos se ejecutará por separado; las pruebas aisladas no acreditan esa integración.

Antes de medir comparar records, hashes, auditoría por acción/tenant, tokens, secuencia de records y efectos normalizados de secuencias de auditoría. El calentamiento piloto usa veinte lecturas por tenant: trabajo determinista, no una duración o estado de caché universal. El chequeo posterior también acondiciona cachés. Guardar estados físicos completos.

## Métricas, exportación y análisis

Medir p50/p95/p99 por endpoint, errores por status/transporte, throughput ofrecido/iniciado/completado/exitoso, ciclos completos y residuos. Conservar muestras y etiquetar drenaje; el resumen global k6 lo incluye y no es un estimador exclusivo de la ventana principal.

Capturar CPU/memoria/IO/red/cgroups de generador, nginx, app y DB; FPM y cola; actividad, conexiones, locks, temporales, buffers y contadores PostgreSQL. WAL y `pg_stat_io` son globales. Capturar host, versiones, digests, hashes, parámetros PostgreSQL, PHP/OPcache/FPM y esquemas. Los deltas de contadores de la ejecución incluyen drenaje y deben etiquetarse así.

Exportar JSON, JSON Lines comprimido y CSV, con bloque, estrategia, escenario, operación, timestamps y tamaño muestral. Los tokens temporales no se publican. Las métricas del generador deben conservarse para descartar su saturación como causa.

Los umbrales propuestos p95 ≤500 ms, p99 ≤1.000 ms y éxito ≥99% son **convenciones experimentales de este estudio**, no estándares universales ni requisitos actuales de la API. Sus incumplimientos bajo carga son resultados de saturación; no razones para borrar corridas.

Analizar razones/diferencias de throughput y p95 por corridas emparejadas dentro de bloques equivalentes, con intervalos bootstrap pareados del 95% y semilla fijada. No bootstrap de solicitudes como si fueran réplicas independientes. Comparaciones por pares: ajuste Holm por familia confirmatoria; diferencia práctica candidata del 10%. No promediar percentiles para construir un percentil global. Publicar incertidumbre, muestras pequeñas, fallos y exclusiones técnicas con sus artefactos. El script de análisis inferencial confirmatorio se preparará una vez congelada su matriz.

Amenazas: orden, cachés de SO y DB, checkpoints, autovacuum, auditoría creciente, conexiones, contención central, cola FPM, costo del monitoreo, estado inicial, variabilidad del host y datos sintéticos. No se evalúan fallos de infraestructura, migraciones con tráfico ni servidores independientes. Las conclusiones describirán estas implementaciones y este entorno, sin generalización automática.
