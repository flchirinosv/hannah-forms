# Fuentes que se sirven desde aquí

Cuatro familias **variables** (una sola fuente por familia cubre los pesos
400–700), subconjunto **latin**. Las cuatro están bajo la SIL Open Font License
1.1, cuyo texto íntegro está en `OFL-1.1.txt` junto a este archivo.

| Archivo | Familia | Copyright |
|---|---|---|
| `inter-latin-var.woff2` | Inter | Copyright 2020 The Inter Project Authors (https://github.com/rsms/inter) |
| `source-sans-3-latin-var.woff2` | Source Sans 3 | Copyright 2010-2020 Adobe (http://www.adobe.com/), with Reserved Font Name 'Source'. All Rights Reserved. Source is a trademark of Adobe in the United States and/or other countries. |
| `source-serif-4-latin-var.woff2` | Source Serif 4 | Copyright 2014 The Source Serif 4 Project Authors (https://github.com/adobe-fonts/source-serif) |
| `lora-latin-var.woff2` | Lora | Copyright 2011 The Lora Project Authors (https://github.com/cyrealtype/Lora-Cyrillic), with Reserved Font Name "Lora". |

## Por qué están aquí y no en un CDN

Porque una fuente que se pide a un tercero se lleva la dirección IP y la
página de quien responde con ella, y el aviso de privacidad de la instalación
no la menciona. Auto-hospedada, sale del mismo origen que el formulario.

Antes de esto, `settings.theme.font` era un **nombre de familia del sistema**:
el nombreaba una fuente que el visitante tuviera instalada y, si no, se caía a
la que tuviera. El propio texto de ayuda lo decía («esto no descarga la
fuente»). El catálogo es ahora una lista cerrada, así que lo que se elige se ve
igual en todas partes.

## Cómo se actualizan

Se han subido **enteras**, con un recorte de subconjunto, no troceadas por
script/language porque son de un solo bloque: un formulario en español no
descarga cirílico.

El subconjunto **latin** es el único que se descarga. Si alguna vez hace falta otro
idioma, hay que volver a subir el archivo correspondiente y añadir su fila en
`FormSettings::fonts()`; sin esa fila, la familia no se puede elegir y por
tanto no se emite su `@font-face` (`ThemeFontCssInjectionTest` lo comprueba
en las dos direcciones: familia del catálogo sin archivo, y archivo que no
está en el catálogo).

## Lo que NO va aquí

`fa-solid-900.*` y `fa-regular-400.*` son de FontAwesome y siguen aquí solo
porque `all.min.css` los pide; no tienen nada que ver con `FormSettings`.