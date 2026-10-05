# Editrix: checklist de pruebas manuales (QA y demo)

Lo que ya cubren las pruebas automáticas (ver `README.md` de esta carpeta) no se repite aquí: la lógica de reemplazo, el tope de resultados, el ciclo del job, el log, la migración. Esta lista cubre lo que solo se ve en el navegador, con sesión del panel de Craft.

Marca cada línea con `[x]` al probarla. Si algo falla, anota la versión (`2.0.0-beta.4` más commit), el navegador, el usuario y la edición.

## 0. Preparación

- [ ] Sitio de pruebas con Craft 5, Editrix instalado y al menos un sitio con entradas, un global, un campo Matrix o CKEditor con bloques anidados, categorías y tags.
- [ ] Un sitio multi-sitio (2 o más sitios) para la sección 3.4.
- [ ] Cuatro usuarios: **admin**, **solo búsqueda** (permiso "Search content"), **buscar y reemplazar** ("Search and replace content") y **sin permisos de Editrix**.
- [ ] Puedes cambiar la edición (Standard / Pro) desde Configuración, Plugins.
- [ ] Anota cómo corre la cola en este entorno: `runQueueAutomatically` (por defecto activo) o un worker. La sección 4 necesita poder apagarla.
- [ ] Haz una copia de la base de datos antes de reemplazar contenido.

## 1. Instalación y ediciones

- [ ] Plugin instala sin errores y aparece "Editrix" en el menú con tres entradas: New Operation, Logs & History, Settings.
- [ ] Tras actualizar desde una versión anterior, las migraciones corren y existe la tabla `editrix_searches`.
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
- [ ] **Tope por reemplazo**: seleccionar más de 150 filas (Select All) deshabilita **Replace Selected** y muestra "Select at most 150 rows to replace at once." Con 150 o menos funciona normalmente. Una llamada directa a `editrix/api/replace` con más filas responde con ese mismo error y no cambia nada.
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
- [ ] **Export CSV** (con permiso y Pro) descarga un archivo con las filas seleccionadas. Sin permiso o en Standard no aparece.

## 6. Reemplazar

- [ ] **Preview Changes** muestra la vista de diff. En Standard es texto, en Pro es lado a lado (ORIGINAL / PROPOSED).
- [ ] "Apply to this element" y "Skip" por elemento funcionan.
- [ ] **Dry Run Mode**: no cambia nada en las entradas ni crea cambios reales.
- [ ] Reemplazo real: aparece "Successfully replaced N occurrence(s)." y la entrada, abierta en otra pestaña, tiene el texto nuevo.
- [ ] **CKEditor con entradas anidadas** (en Frommers: la entrada 157657, campo `articleBody`): reemplaza una palabra del texto, abre la entrada en el editor y comprueba que los bloques anidados siguen ahí. Revierte desde Logs & History y vuelve igual.
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

## 7. Logs & History

- [ ] Cada reemplazo y cada búsqueda aparece en la lista con fecha, usuario, texto y número de ocurrencias.
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
- **Tope de 150 filas por reemplazo** (`maxRowsPerReplace`): es provisional hasta que exista el reemplazo en cola.
- **Cola en producción**: confirmar con quien administra el servidor de Frommers que corre un worker o cron (o Craft Cloud).
- **Frommers**: el error de Twig en `articleBookstore.twig` (línea 8, `is defined` en vez de comprobar null) es de su template. El plugin ya lo omite, pero conviene que lo corrijan.
- **No cubierto por pruebas automáticas**: los endpoints del controlador y toda la interfaz. Por eso existe esta lista.

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
