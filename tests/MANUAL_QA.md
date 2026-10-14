# Editrix: checklist de pruebas manuales (QA y demo)

Lo que ya cubren las pruebas automáticas (ver `README.md` de esta carpeta) no se repite aquí: la lógica de reemplazo, el tope de resultados, el ciclo del job, el log, la migración. Esta lista cubre lo que solo se ve en el navegador, con sesión del panel de Craft.

Marca cada línea con `[x]` al probarla. Si algo falla, usa la plantilla de la sección 15 (versión y commit, navegador, usuario y edición). Lo que ya se sabe que no funciona o no está probado está en la sección 13: no hace falta reportarlo.

## 0. Preparación

- [ ] Sitio de pruebas con Craft 5, Editrix instalado y al menos un sitio con entradas, un global, un campo Matrix o CKEditor con bloques anidados, categorías y tags.
- [ ] Un sitio multi-sitio (2 o más sitios) para la sección 3.4.
- [ ] Cuatro usuarios: **admin**, **solo búsqueda** (permiso "Search content"), **buscar y reemplazar** ("Search and replace content") y **sin permisos de Editrix**.
- [ ] Puedes cambiar la edición (Standard / Pro) desde Configuración, Plugins.
- [ ] Anota cómo corre la cola en este entorno: `runQueueAutomatically` (por defecto activo) o un worker. La sección 4 necesita poder apagarla.
- [ ] Haz una copia de la base de datos antes de reemplazar contenido.
- [ ] Anota la versión y el commit que vas a probar (`version` en `composer.json` y `git rev-parse --short HEAD` en la carpeta del plugin). Todos los reportes la llevan.
- [ ] Qué se ha probado ya y dónde: un sitio local grande (MySQL, 845,000 entradas, un solo sitio) y uno con PostgreSQL y Neo 5 (un solo sitio), los dos en Pro. **Ninguno es multisitio ni Standard**: esas partes (3.4, el reemplazo en dos sitios y los bloqueos de Standard) solo se pueden probar en un entorno aparte.
- [ ] Si el sitio tiene Neo, comprueba la versión de Neo (en Neo 5 la tabla de bloques usa `primaryOwnerId`).

## 1. Instalación y ediciones

- [ ] Plugin instala sin errores y aparece "Editrix" en el menú con tres entradas: New Operation, Logs & History, Settings.
- [ ] Tras actualizar desde una versión anterior, las migraciones corren y existen las tablas `editrix_searches` y `editrix_log_items`.
- [ ] **Instalación nueva** con `composer require brand-industry/craft-editrix` y `php craft plugin/install editrix`: instala sin errores y la primera búsqueda funciona (la tabla de búsquedas se crea sola).
- [ ] **Actualización**: tras `composer update` y `php craft up`, si el sitio guarda el `project.yaml` en git y su versión del plugin quedó atrás, Craft termina con "Your project config files were created for different versions". No es un fallo del plugin. Con `php craft project-config/write` en desarrollo (con `allowAdminChanges` activo) el aviso desaparece y `php craft up` termina limpio. El README explica los pasos.
- [ ] **Standard**: Matrix, Categories, selector de varios sitios, filtros de alcance (Segmented) y exportar CSV/JSON no están disponibles (ocultos o desactivados, y nunca devuelven resultados de todos modos). La insignia del título dice Standard.
- [ ] **Pro**: aparecen todos esos elementos y la insignia dice Pro.
- [ ] Cambiar de Standard a Pro (y de vuelta) sin recargar manualmente no deja la pantalla en un estado raro. Recarga y confirma.
- [ ] La tabla de ediciones del README coincide con lo que ves en cada edición.

## 2. Pantalla de inicio

- [ ] New Operation muestra "What do you want to search?" con tres tarjetas: Text, Categories, Tags.
- [ ] En Standard, Categories y Tags aparecen bloqueadas con la etiqueta Pro y no se pueden abrir.
- [ ] Se ve la gráfica de actividad y "Recent Activity" con las últimas operaciones.
- [ ] "Re-run" en una actividad reciente reabre la búsqueda con el mismo texto y alcance, y la ejecuta.
- [ ] "Choose a different tool" regresa a la pantalla de inicio.

## 3. Búsqueda de texto

### 3.1 General Search

- [ ] Buscar una palabra que existe devuelve resultados agrupados, con entrada, sección, campo y contexto.
- [ ] Buscar algo que no existe muestra "No results found." sin errores.
- [ ] Buscar con el campo vacío no lanza una búsqueda ni muestra un error técnico.
- [ ] **Case insensitive** encuentra "HOTEL" al buscar "hotel". Sin la opción, no.
- [ ] **Whole words** no encuentra "cat" dentro de "concatenate".
- [ ] **Regex**: `(\w+) (\w+)` encuentra pares de palabras. Una expresión inválida como `(abc` muestra "Invalid regular expression" y no rompe la pantalla.
- [ ] Acentos y ñ ("café", "Zürich"): el texto resaltado coincide exactamente con lo buscado.
- [ ] Las casillas Entries, Globals, Matrix fields y Categories limitan dónde se busca. Con todas apagadas, la búsqueda avisa o no devuelve nada, sin error.
- [ ] Un título con HTML, por ejemplo `<script>alert(1)</script>`, se muestra como texto en los resultados (no se ejecuta).

### 3.2 Segmented Search (Pro)

- [ ] El panel lateral pide en orden: sección, tipo de entrada, campos. Mientras falta el anterior se ve "Select a section first." / "Select an entry type first."
- [ ] "Filter sections" filtra la lista. "Clear" borra la selección. "{n} selected" cuenta bien.
- [ ] El resumen lateral muestra cuántas entradas quedan dentro del alcance.
- [ ] Con un alcance de una sola sección, los resultados vienen solo de esa sección.
- [ ] Elegir un tipo de entrada sin campos buscables muestra "No searchable fields on the selected entry type(s)."

### 3.3 Cambios de modo

- [ ] Cambiar entre **General** y **Segmented** limpia los resultados anteriores (no pregunta).
- [ ] Cambiar entre **Search** y **Search & Replace** no deja resultados viejos ni el botón equivocado.
- [ ] En modo **Search** aparecen Export CSV (si tienes permiso) y **no** aparece "Replace Selected". En **Search & Replace** aparece "Replace Selected".
- [ ] Cambiar a Categories o Tags y volver no arrastra resultados ni filtros.

### 3.4 Varios sitios (Pro, sitio con 2 o más)

- [ ] Aparece el selector de sitio y la opción "All Sites". En un sitio único no aparece.
- [ ] "All Sites" devuelve resultados de cada sitio, agrupados por sitio.
- [ ] Un sitio concreto solo devuelve resultados de ese sitio.

## 4. Búsqueda en segundo plano

- [ ] Al buscar, la pantalla pasa por fases con su texto y su animación: "Sending your search…" y "Waiting for the server to pick up your search…" (tres puntos que laten), "Counting the entries to search…" (lupa que pulsa), "Scanning X of Y entries (Z%)…" (lupa que recorre las filas; las filas se iluminan cuando hay coincidencias) y "Preparing your results…" (palomita verde). Ninguna fase se queda sin texto.
- [ ] Debajo aparecen "Elapsed m:ss", que avanza cada segundo, y, tras unos 15 segundos de escaneo, "Up to about N min left". Recargando la página, el tiempo transcurrido sigue contando desde que empezó la búsqueda, no desde cero.
- [ ] Un consejo cambia cada ~8 segundos y el primero dice que se puede salir de la página. Sin Pro no aparece el consejo de Segmented Search.
- [ ] Con "reducir movimiento" activado en el sistema, las animaciones se detienen y el texto sigue ahí.
- [ ] La barra de progreso sube y el porcentaje coincide con X de Y.
- [ ] **Tiempos de referencia** (sitio local con 845,000 entradas, medidos el 2026-10-12 con el equipo ocupado, carga de ~5, con Pro y Matrix y globales activos; la primera vez pueden ser más; son cifras de referencia, no promesas): una palabra rara en todo el sitio, de 30 a 34 s; «hotel», que se corta en el tope de 25,000 filas, unos 110 s con Matrix y globales (31 s solo con entradas; con el tope de 3,000 de Standard, mucho menos); una sección de 27,000 entradas con una palabra rara, de 5 a 11 s. Si tarda mucho más, anota el equipo y la memoria asignada a Docker. Un Dry Run de 100 filas tardó 0,8 s y 8 MB. Para repetir las medidas: `EDITRIX_SLOW=1 EDITRIX_RARE=<palabra rara> phpunit --filter SearchTimingTest` (y `DryRunTimingTest`).
- [ ] El botón **Cancel search** detiene la búsqueda en pocos segundos y muestra lo encontrado hasta ese momento.
- [ ] Cancelar y lanzar otra búsqueda enseguida muestra solo los resultados de la nueva.
- [ ] **Recargar a mitad de búsqueda**: lanza una búsqueda grande y recarga la página. Te deja en la vista de búsqueda de texto, siguiendo la misma búsqueda: spinner, barra con movimiento, "Scanning X of Y entries", "N matches so far" y el botón **Cancel search**. El formulario se ve con los valores usados y deshabilitado.
- [ ] **Salir al menú y volver**: con una búsqueda corriendo, pulsa "Choose a different tool". En el menú, la tarjeta **Text** muestra la etiqueta "Search in progress · N%" (se actualiza sola). Al entrar a Text, vuelves a ver la búsqueda con su progreso, no un formulario vacío. Entrar a Categories o Tags no se ve afectado.
- [ ] **Una búsqueda a la vez**: con una búsqueda en curso, abrir Editrix en otra pestaña te lleva a esa misma búsqueda. Si alguien intenta lanzar otra (por ejemplo, con una llamada directa), el servidor responde "You already have a search running…".
- [ ] Cancelar durante la búsqueda la detiene en pocos segundos, muestra lo encontrado y deja buscar de nuevo.
- [ ] Si el worker murió y la búsqueda lleva más de 10 minutos sin avance, deja de contar como en curso: la etiqueta de la tarjeta desaparece y ya no bloquea nuevas búsquedas.
- [ ] Un segundo usuario puede buscar a la vez que el primero, hasta el límite del servidor (`maxConcurrentSearches`, 2 por defecto). Con el límite alcanzado ve "Other searches are using the server right now…".
- [ ] Al terminar una búsqueda, sal del plugin y vuelve: en **Recent searches** aparece con su número de resultados. **Open** muestra los mismos resultados sin buscar de nuevo y restaura el formulario (texto, secciones, opciones).
- [ ] Una búsqueda terminada abierta desde **Recent searches** se puede usar en Search & Replace (la selección y el preview funcionan).
- [ ] Un segundo usuario no ve las búsquedas del primero.
- [ ] **Una fila por campo**: una palabra que aparece varias veces en el mismo campo de una entrada sale en **una sola fila** con la etiqueta "N matches". "Show all N matches" despliega cada aparición con la palabra resaltada, y la vista previa se ve completa (la palabra resaltada no queda cortada). El encabezado muestra el número de filas y, al lado, el total de coincidencias.
- [ ] **View** carga el texto completo del campo al abrirse (breve "Loading…") y resalta todas las apariciones. **Preview Changes** muestra el antes y el después del campo completo.
- [ ] Si editas la entrada en otra pestaña después de buscar, **View** y **Preview Changes** avisan "Its content changed after the search…" y View no resalta posiciones viejas.
- [ ] Abrir una búsqueda de **Recent searches** y usar View o Replace funciona igual que en una búsqueda recién hecha.
- [ ] **Tope por reemplazo**: seleccionar más filas de las que permite la edición (200 en Standard, 5,000 en Pro) deshabilita **Replace Selected** y muestra "Select at most N rows to replace at once." Con ese número o menos funciona normalmente. Una llamada directa a `editrix/api/replace` con más filas responde con ese mismo error y no cambia nada.
- [ ] Al seleccionar esa fila y reemplazar, cambian **todas** las apariciones del campo; el mensaje dice cuántas apariciones se cambiaron. En **View**, todas las apariciones salen resaltadas.
- [ ] Export CSV genera una línea por aparición.
- [ ] Con una búsqueda muy amplia en un sitio grande (ej. una vocal en un sitio de miles de entradas) el resultado se corta en el límite de la edición (**3,000** en Standard, **25,000** en Pro) y aparece el aviso amarillo "Showing the first N results…". En Standard el aviso añade "Your edition shows up to 3,000 results; Pro shows up to 25,000" con la insignia Pro; en Pro no. Con una búsqueda estrecha, ese aviso no aparece.
- [ ] **Cola apagada**: pon `runQueueAutomatically => false` en `config/general.php`, sin worker, y busca. A los ~15 segundos aparece el aviso de que la cola de Craft quizá no está corriendo. Cancelar funciona.
- [ ] Con la cola apagada, arranca el worker (`php craft queue/run`): la búsqueda empieza sola y el aviso desaparece.
- [ ] **Worker caído**: lanza una búsqueda grande y mata el proceso del worker a mitad. A los ~2 minutos sin avance aparece el aviso de que la búsqueda dejó de reportar progreso. Cancelar la libera.
- [ ] Dos usuarios buscando a la vez no se mezclan los resultados.
- [ ] Una entrada que falla al cargarse (error en un template de otro plugin) no detiene la búsqueda: se omite y el resto aparece. Revisa `storage/logs` por líneas "Editrix: skipped entry".

## 5. Resultados

- [ ] La tabla muestra el total y agrupa por sitio. "Show {n} more entries" amplía la lista.
- [ ] Seleccionar una fila, "Select All" y "Deselect All" cuentan bien.
- [ ] **View** abre "Match Details" con elemento, sección, campo, sitio y el texto con la coincidencia resaltada.
- [ ] "Open in Craft" abre la edición de la entrada correcta.
- [ ] Coincidencias dentro de HTML que cruzan una etiqueta (ej. negritas a mitad de la frase) aparecen como solo lectura con la explicación. No se pueden seleccionar para reemplazar.
- [ ] Coincidencias de Tags aparecen como solo lectura ("Search only").
- [ ] Bloques anidados (Matrix o CKEditor con entradas) aparecen una sola vez, con su entrada padre.
- [ ] **Neo** (si el sitio lo tiene): una búsqueda amplia termina sin error, y los bloques Neo salen con su etiqueta "Neo" y la ruta de su entrada padre. En Neo 5 con PostgreSQL esto fallaba con "column ownerId does not exist".
- [ ] **Export CSV** (con permiso y Pro) descarga un archivo con las filas seleccionadas. Sin permiso o en Standard no aparece.

## 6. Reemplazar

- [ ] **Preview Changes** muestra la vista de diff. En Standard es texto, en Pro es lado a lado (ORIGINAL / PROPOSED).
- [ ] "Apply to this element" y "Skip" por elemento funcionan.
- [ ] **Dry Run Mode**: con el interruptor activo el botón dice "Run dry run"; al pulsarlo informa cuántas coincidencias cambiarían y qué filas omitiría, no cambia ninguna entrada, no crea un log en Logs & History, no pide el código REPLACE y las filas siguen sin marcar como reemplazadas.
- [ ] Reemplazo real: aparece "Successfully replaced N occurrence(s)." y la entrada, abierta en otra pestaña, tiene el texto nuevo.
- [ ] **CKEditor con entradas anidadas** (en un sitio con artículos largos que embeben entradas): reemplaza una palabra del texto, abre la entrada en el editor y comprueba que los bloques anidados siguen ahí. Revierte desde Logs & History y vuelve igual.
- [ ] **Después de reemplazar no se vuelve a buscar**: las filas reemplazadas quedan visibles con la marca verde "Replaced", sin casilla, y arriba aparece "Replaced N matches." con el enlace a Logs & History y el botón **Search again**. No aparece la barra de progreso.
- [ ] Reemplaza, y luego sal del plugin y reabre esa búsqueda desde **Recent searches**: las filas ya reemplazadas siguen marcadas.
- [ ] **Edición posterior**: busca una palabra, edita a mano en otra pestaña una de las entradas del resultado y vuelve a reemplazar. Esa fila queda como "Changed since search", no se sobrescribe (comprueba que tu edición sigue) y el resumen avisa cuántas se omitieron.
- [ ] En un sitio multi-sitio, reemplazar el mismo campo en dos sitios cambia los dos.
- [ ] El reemplazo funciona en título, campo de texto, texto largo, CKEditor/Redactor, global, bloque Matrix y categoría.
- [ ] Reemplazar por texto con `$1`, `$100`, `\` o `${1}` lo guarda **tal cual**, sin corromper el contenido.
- [ ] Case insensitive y Whole words reemplazan solo lo que se ve en la vista previa.
- [ ] Regex con grupos (`$2, $1`) funciona en modo Regex.
- [ ] En CKEditor/Redactor, el formato fuera del texto cambiado (cursivas, enlaces) queda intacto. Los atributos de etiquetas (`href`) no se tocan.
- [ ] Más resultados que "bulk confirmation threshold" (100 por defecto): pide escribir **REPLACE**. Con otro texto no deja continuar.
- [ ] Con "Production safe mode" activo y el entorno en producción, pide escribir REPLACE aunque sean pocos.
- [ ] Una entrada deshabilitada y una categoría deshabilitada se reemplazan igual que una activa.
- [ ] Un usuario sin permiso "Search and replace content" no ve Search & Replace, y una llamada directa a `editrix/api/replace` responde "Permission denied".


### 6.1 Reemplazos grandes, cola y seguridad

- [ ] **Más de 25 filas**: seleccionar 30 o más filas y reemplazar. Aparece el panel "Replacing X of Y rows…" con barra, "N changed so far" y **Cancel replace**; la ventana de confirmación se cierra. Al terminar, las filas quedan marcadas como Replaced y el resumen avisa de las omitidas o no guardadas.
- [ ] **Recargar a mitad de un reemplazo en cola**: el panel "Replacing X of Y rows…" vuelve a aparecer solo, con el avance real y **Cancel replace** funcionando; al terminar sale el resumen (sin botón "Search again" si no hay búsqueda cargada). Salir a otra sección del CP y volver hace lo mismo.
- [ ] **Cancel replace** detiene el trabajo tras la entrada en curso. En Logs & History, esa operación aparece como `cancelled` con lo que alcanzó a cambiar, y se puede revertir.
- [ ] **Matar el worker** a mitad de un reemplazo en cola (o reiniciar ddev): el registro en Logs & History queda en `running` y tiene los valores ya cambiados; revertirlo restaura lo que se alcanzó a guardar y deja intacto lo demás.
- [ ] **Límite por edición**: en Standard, más de 200 filas no se pueden reemplazar (el botón se deshabilita y el servidor lo rechaza); en Pro, más de 5,000.
- [ ] **Entradas que no se pudieron guardar** aparecen en una lista "Show why" con el motivo, y sus filas salen con "Not saved".
- [ ] Un reemplazo que dejaría **vacío un campo obligatorio** (por ejemplo, el título completo por nada) se rechaza para esa entrada y el título no cambia.
- [ ] Una búsqueda vacía, o con el reemplazo igual a lo buscado, se rechaza con un mensaje claro antes de leer nada.
- [ ] **Preserve case** (Pro, solo en modo reemplazo y sin regex): "widget"→"gadget" convierte "Widget" en "Gadget" y "WIDGET" en "GADGET". Activarlo marca Case Insensitive. En Standard la casilla sale deshabilitada con la insignia Pro.
- [ ] **Campos y secciones protegidos** (`protectedFields`, `protectedSections` en `config/editrix.php`): sus coincidencias salen en la búsqueda pero sin casilla (solo lectura, "protected"), y no aparecen en modo reemplazo.
- [ ] **Revert** de un reemplazo donde editaste una de las entradas después: se restauran las demás, esa se deja como está y se informa; luego se ofrece "Overwrite later edits?" como paso aparte. Revertir dos veces no hace daño.
- [ ] **Revert grande** (más de 25 valores) corre en la cola y avisa al terminar.
- [ ] Cada reemplazo y revert escribe líneas en `storage/logs/editrix-*.log`.
- [ ] **Campos con handle propio en el layout**: en Craft 5 un mismo campo puede llamarse distinto en cada layout (en los sitios de prueba hay campos que cambian de handle según el tipo de entrada). Busca una palabra que esté en uno de esos campos, selecciona su fila y reemplaza: debe guardarse, sin "this field cannot be replaced". Revierte desde Logs & History y la entrada vuelve igual. Ninguna fila encontrada debe rechazarse por el tipo de campo (solo por estar protegida o ser de solo lectura).

### 6.2 Línea de comandos (Pro)

- [ ] `php craft editrix/run/search --find=... --sections=...` lista coincidencias y el total.
- [ ] `php craft editrix/run/replace ...` sin `--dry-run=0` no cambia nada y lo dice.
- [ ] Con `--dry-run=0` cambia, imprime el id del log, y `php craft editrix/run/revert <id>` lo deshace.
- [ ] En Standard, los comandos responden "The command line needs Editrix Pro."

## 7. Logs & History

- [ ] Cada reemplazo y cada búsqueda aparece en la lista con fecha, usuario, texto y número de ocurrencias. Una búsqueda sale como tipo `search` y su número son las coincidencias encontradas, no cambios: no se puede revertir y no modifica nada.
- [ ] Los filtros y la paginación de la lista funcionan.
- [ ] Una búsqueda registrada se puede re-ejecutar con su alcance.
- [ ] **Revert** de un reemplazo sin cambios posteriores restaura el contenido original y marca el log como revertido.
- [ ] Si alguien editó esa entrada después del reemplazo, el revert avisa que fue modificada antes de sobrescribir.
- [ ] Si la entrada ya no existe, el revert lo indica sin romper el resto.
- [ ] **Delete** elimina el log (con permiso "Delete logs"). Sin permiso no aparece.
- [ ] **Export** CSV/JSON del historial (Pro y permiso "Export logs").
- [ ] Retención: Standard conserva 30 días, Pro 90. Los logs más viejos se limpian.

## 8. Categorías y Tags (Pro)

- [ ] Buscar el nombre de una categoría lista las entradas a las que está asignada.
- [ ] Lo mismo con un tag.
- [ ] "Limit to sections" acota la búsqueda. Solo lista secciones que tienen un campo de categorías (o tags).
- [ ] Sin categorías o tags creados, aparece el mensaje con el enlace "Go to Categories settings" / "Go to Tags settings".
- [ ] Cambiar entre Categories y Tags limpia los resultados anteriores.

## 9. Settings

- [ ] La página abre y muestra los valores actuales.
- [ ] Guardar cambia el valor y persiste tras recargar.
- [ ] "Log retention days" rechaza 0 y más de 365. "Bulk confirmation threshold" rechaza 0.
- [ ] "Show environment indicator" muestra u oculta la insignia de entorno en el título.
- [ ] Los tipos de campo buscables controlan en qué campos se busca y se reemplaza. Un campo de otro tipo no se reemplaza.
- [ ] Campos protegidos (Pro): no se pueden reemplazar.

## 10. Permisos

| Usuario | Debe ver | No debe poder |
| --- | --- | --- |
| Sin permisos | nada de Editrix en el menú | abrir `editrix/search` por URL |
| Solo búsqueda | New Operation, buscar | Search & Replace, revertir, borrar logs |
| Buscar y reemplazar | todo lo anterior y reemplazar | revertir/borrar/exportar sin esos permisos |
| Admin | todo | |

- [ ] Cada fila de la tabla se cumple, también llamando a las URLs de API a mano.

## 11. Robustez

- [ ] Sesión del panel vencida a mitad de una búsqueda: la pantalla muestra un error, no se queda cargando para siempre.
- [ ] Botón Atrás del navegador a mitad de una búsqueda o de un reemplazo no deja datos a medias.
- [ ] Una entrada con un campo muy largo (varios MB) no tumba la búsqueda.
- [ ] La consola del navegador no muestra errores durante un recorrido completo.

## 12. Teclado y pantallas

- [ ] Se puede recorrer la búsqueda con Tab y activar con Enter o Espacio. El foco se ve.
- [ ] Los modales (Match Details, vista previa, confirmación) se cierran con Escape.
- [ ] A 1280 px, 1024 px y 768 px no hay desbordes horizontales ni texto cortado.

## 13. Pendientes y diferencias conocidas

- **Tope de resultados por edición** (Standard 3,000, Pro 25,000, techo absoluto 50,000): sale de `LicenseService::$limits['searchResultLimit']`; `maxSearchResults` solo puede bajarlo.
- **Tope de filas por reemplazo** (Standard 200, Pro 5,000, `replaceRowLimit` en `LicenseService`); `maxRowsPerReplace` solo puede bajarlo. Por encima de 25 filas (`replaceQueueThreshold`) el reemplazo corre en la cola.
- **Cola en producción**: confirmar con quien administra el servidor de cada sitio que corre un worker o cron (o Craft Cloud).
- **No cubierto por pruebas automáticas**: los endpoints del controlador y toda la interfaz. Por eso existe esta lista.
- **Palabra partida por formato**: el prefiltro no encuentra una palabra cortada por una etiqueta (`Fea<b>tured</b>`). El ajuste `sqlPrefilter` en `false` lo apaga.
- **Con el tope de filas**, el prefiltro y el escaneo completo pueden quedarse con filas ligeramente distintas entre las primeras. En una búsqueda completa, sin tope, son idénticos.
- **No existe todavía**: Presets y API/Webhooks (el README los menciona), reglas guardadas o programadas, pantalla para campos protegidos e ids de elementos (solo por configuración y línea de comandos).
- **Versiones**: esta rama es solo Craft 5 y PHP 8.2 o superior; Craft 4 no se ha portado. Redactor no se ha probado con datos reales (CKEditor, Matrix y Neo sí).
- **Aviso de `craft up`** con el `project.yaml` desfasado: es la comprobación normal de Craft (ver la sección 1).
- **Revertir** solo funciona mientras exista el registro: 30 días en Standard y 90 en Pro.
- **Entornos**: no hay un sitio multisitio ni uno en Standard con el que se haya probado; la sección 0 lo marca.

## 14. Guion de demo (unos 10 minutos)

Prepara antes: una palabra que aparezca en 20 a 50 entradas, una entrada con negritas dentro de la frase, un campo Matrix y una categoría.

1. Inicio: muestra las tarjetas y la actividad reciente.
2. General Search con la palabra preparada, con **Case insensitive**. Abre un resultado con **View**.
3. Segmented: elige una sección y un campo, y compara el número de resultados.
4. Búsqueda amplia (una vocal) en un sitio grande: barra de progreso, **Cancel search**, luego dejarla llegar al aviso del tope.
5. Search & Replace: reemplaza la palabra por otra en 2 o 3 entradas con **Preview Changes** y **Dry Run** primero.
6. Abre la entrada en Craft y enséñala cambiada.
7. Logs & History: abre el reemplazo y haz **Revert**. Vuelve a la entrada.
8. Cierra con Categories o Tags y mostrar que Standard queda bloqueado frente a Pro.

## 15. Cómo reportar un problema

Una línea por punto:

- **Versión y commit** del plugin.
- **Entorno**: Craft, PHP, MySQL o PostgreSQL, navegador, ddev o servidor.
- **Edición y usuario**: Standard o Pro, y si eras admin, solo búsqueda o buscar y reemplazar.
- **Dónde** de esta lista: número de sección y la línea que probabas.
- **Qué hiciste**: texto buscado, opciones, alcance, y a cuántas filas afectó.
- **Qué esperabas y qué pasó**, con captura.
- **Consola del navegador** y las últimas líneas de `storage/logs/editrix-*.log` y del log web de Craft.
- **Si se repite**, y si pasa con otra palabra o con otra entrada.

## 16. Cambios posteriores a beta.5

Probar con la rama o la versión que los incluya. Los puntos de permisos necesitan un usuario que **no** sea admin.

### 16.1 Permisos de Craft sobre secciones

Crear un grupo con permisos de Editrix (buscar y reemplazar, revertir) y, en Craft, permiso para ver y guardar entradas **solo de la sección A**. Dos entradas con una palabra en común: una en A y otra en B.

- [ ] Con ese usuario, buscar la palabra: salen solo las filas de A.
- [ ] Un admin ve las dos secciones.
- [ ] Con ese usuario, Reemplazar sobre una fila de A funciona. El log queda a su nombre.
- [ ] Llamando a la API a mano con un `elementId` de la sección B (`editrix/api/search/value`, `editrix/api/preview`) no devuelve el texto.
- [ ] Llamando a `editrix/api/replace` a mano con una fila de B: la entrada no cambia y el resumen dice "you can't edit this entry in Craft".
- [ ] Revertir con ese usuario un log hecho por un admin sobre entradas de B: esos valores no se restauran y salen como fallidos.
- [ ] Un bloque Matrix o Neo de una entrada de B tampoco aparece ni se puede cambiar.
- [ ] Un global y una categoría a los que no tiene acceso no aparecen (con Categories activado).
- [ ] La búsqueda por categoría o tag (Pro) no lista entradas de B.
- [ ] Por línea de comandos con `--user=<ese usuario>` pasa lo mismo.

### 16.2 Expresiones regulares

- [ ] `https?://` es una expresión válida y encuentra direcciones.
- [ ] Reemplazar `http://` por `https://` con Regex funciona.
- [ ] `(a+)+$` sobre un texto largo de aes seguidas de otra letra termina con el mensaje "too complex", no se queda colgada ni dice "sin resultados".
- [ ] `(abc` sigue diciendo "Invalid regular expression".

### 16.3 Dejar fuera coincidencias

- [ ] En un campo con 3 coincidencias, abrir "Show all 3 matches" y apagar la segunda. La fila dice "2 of 3 will change".
- [ ] La vista previa (View) muestra el texto sin cambiar esa coincidencia.
- [ ] Tras reemplazar, la coincidencia apagada sigue intacta en Craft y las otras dos cambiaron.
- [ ] El resumen y el log cuentan 2 coincidencias, no 3.
- [ ] Con Regex y `$1` en el reemplazo, las coincidencias que sí cambian usan sus grupos.
- [ ] En un campo de texto enriquecido pasa lo mismo.
- [ ] Revertir ese reemplazo devuelve el valor original.

### 16.4 Direcciones de enlaces

Un campo CKEditor con un enlace `<a href="http://dominio-viejo.test/x">dominio-viejo.test</a>` y una imagen con `src` del mismo dominio.

- [ ] Sin "Include link addresses", buscar `dominio-viejo.test` encuentra solo el texto visible.
- [ ] Con la casilla, encuentra también el `href` y el `src`, y la fila cuenta todas las coincidencias.
- [ ] Reemplazar con la casilla cambia el texto, el `href` y el `src`, y no toca el resto de la etiqueta (clases, `target`, otros atributos).
- [ ] Se puede apagar solo la coincidencia del `href` y dejar las demás.
- [ ] Un enlace entre comillas simples también se encuentra.
- [ ] `php craft editrix/run/replace --find=... --replace=... --links` hace lo mismo, y sin `--links` no toca direcciones.

### 16.5 Notas de revisión

- [ ] Tras un reemplazo, en la entrada, la pestaña de revisiones muestra la nota "Editrix replace #N" y el usuario que lo hizo.
- [ ] Tras revertir, la nota dice "Editrix revert of #N".
- [ ] Con `createRevisionsOnReplace` en `false` no se crea revisión.
