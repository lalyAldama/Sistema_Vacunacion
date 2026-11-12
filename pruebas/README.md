# Pruebas del paloteo general

Desde la raíz del proyecto, ejecutar en PowerShell:

```powershell
& C:\xampp\php\php.exe pruebas\paloteo_general.php
```

La prueba requiere PHP CLI con `mbstring` y `proc_open` habilitados. Cada caso
ejecuta el paloteo de producción en un proceso PHP separado, con `-n` para
desactivar la configuración y extensiones predeterminadas; habilita únicamente
`mbstring` y sustituye `mysqli` por una implementación en memoria. Si `mysqli`
está disponible en el proceso aislado, la prueba se detiene antes de cargar el
paloteo. Las entradas son ficticias: no se conecta a MySQL ni modifica datos.

La salida muestra cada discrepancia, el número de casos y los renglones con y
sin caso aprobado. El código de salida es `0` si todos los casos y renglones
pasan, `1` si hay discrepancias o faltan casos, y `2` si falla el entorno. Las
pruebas pueden fallar mientras existan discrepancias en el código de producción.

Las expectativas toman como referencia los nombres y rangos del formato
actual, no certifican criterios médicos. Se prueban las categorías del
paloteo general, incluidas influenza, COVID y VPH confidencial; no los reportes
separados de influenza o COVID. Se incluyen límites seleccionados, reemplazos,
migración, población indígena/afromexicana y conteos mensual y anual.

La simulación no comprueba consultas SQL, catálogo real de la base de datos,
filtros por unidad o periodo, ni ajustes guardados. Las etiquetas neumocócicas
con espacios se tomaron de los alias del módulo de movimientos, no se verificó
que existan en una base específica. Algunas categorías dependen de datos no
disponibles en el reporte, como la fecha de la dosis previa de neumococo 23:
su intervalo no puede verificarse con estas pruebas.

Para neumococo se comprueba la separación por valencia, las dosis de rescate
y sus límites de edad, así como ejemplos de conteo anual. Cuando el nombre
indica vacuna polisacárida sin especificar valencia, el paloteo usa el renglón
de 23 valencias del formato actual.

## Registro de unidades centrales

```powershell
& C:\xampp\php\php.exe pruebas\unidades.php
```

La prueba usa un simulador de MySQL en un proceso aislado, sin conectar con
ninguna base de datos. Comprueba permisos, validación, duplicados, errores y
alta simulada; no sustituye una prueba de integración con MySQL.

## Informe mensual general

```powershell
& C:\xampp\php\php.exe pruebas\informe_mensual.php
```

La prueba ejecuta el informe con la plantilla real y datos ficticios. Comprueba
cada correspondencia con valores distintos para los 12 meses, tanto en la
vista como en las celdas que se escriben en Excel. También prueba aplicaciones
de hepatitis B y una categoría sin correspondencia. Requiere `mbstring`, `zip`,
DOM y `proc_open`; no se conecta a MySQL.

El informe utiliza claves explícitas de la plantilla. Los dos renglones del
paloteo de neumococo 13 para personas de 60 años y más se suman en el único
renglón correspondiente de la plantilla. Los renglones de reemplazo que no
tienen correspondencia directa quedan pendientes y bloquean la descarga si
contienen aplicaciones. Influenza y neumococo 23 siguen fuera del informe
mensual general, como antes.
