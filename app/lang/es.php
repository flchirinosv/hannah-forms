<?php

declare(strict_types=1);

/**
 * Catálogo español (P9, i18n reducido).
 * Ámbito: fill público, gracias, estados cerrado/lleno/borrador y correos.
 * El dashboard/builder/admin NO pasan por aquí (herramienta interna en español).
 * Claves planas; markdown/HTML permitido en los mensajes finales ya escapables.
 */

return [
    // UI general del formulario
    'ui.avance'        => 'Avance',
    'ui.paso'          => 'Paso',
    'ui.de'            => 'de',
    'ui.anterior'      => 'Anterior',
    'ui.siguiente'     => 'Siguiente',
    'ui.enviar'        => 'Enviar',
    'ui.enviando'      => 'Enviando…',
    'ui.corrige_errores' => 'Corrige los siguientes errores:',
    'ui.select_option'   => '-- Selecciona una opción --',
    'ui.ranking_hint'    => 'Ordena las opciones de 1 (más importante) a {max}.',
    'ui.file_hint'         => 'Adjunta hasta {count} archivo(s), máximo {size} MB cada uno. Formatos: {types}.',
    'ui.file_types_all'    => 'los permitidos por el servidor',
    'ui.nada_probable'   => 'Nada probable',
    'ui.muy_probable'    => 'Muy probable',
    'ui.shown_if'        => 'Se muestra si: ',
    'ui.unknown_field'   => 'campo desconocido',
    'ui.and_join'        => 'y',
    'ui.or_join'         => 'o',
    'ui.page_title'      => 'Paso {n}',
    'ui.leave_empty'     => 'Deja este campo vacío',
    'ui.form'            => 'El formulario',

    // Etiquetas de operadores (régimen) en el chip de condición
    'op.contains'     => 'contiene',
    'op.not_contains' => 'no contiene',
    'op.filled'       => 'tiene valor',
    'op.empty'        => 'está vacío',
    'op.matches'      => 'coincide con',

    // Validación (server y cliente del fill — mismo texto)
    'val.required'          => 'Este campo es obligatorio.',
    'val.email'             => 'Debe ser un email válido.',
    'val.url'               => 'Debe ser una URL válida.',
    'val.tel'               => 'Debe ser un teléfono válido.',
    'val.number'            => 'Debe ser un número.',
    'val.min_gte'           => 'El valor debe ser mayor o igual a {min}.',
    'val.max_lte'           => 'El valor debe ser menor o igual a {max}.',
    'val.min_len'           => 'Debe tener al menos {n} caracteres.',
    'val.max_len'           => 'Debe tener como máximo {n} caracteres.',
    'val.too_long'          => 'La respuesta es demasiado larga.',
    'val.invalid_selection' => 'Selección inválida.',
    'val.matrix_all'        => 'Debe responder todas las filas de la matriz.',
    'val.matrix_row'        => 'Selección inválida en la fila "{row}".',
    'val.ranking_all'       => 'Debe asignar un orden a todas las opciones.',
    'val.ranking_numbers'   => 'Los órdenes deben ser números.',
    'val.ranking_range'     => 'Los órdenes deben estar entre 1 y {max}.',
    'val.ranking_unique'    => 'Los órdenes no pueden repetirse.',
    'val.nps_range'         => 'Debe elegir un valor entre 0 y 10.',
    'val.scale_range'       => 'Debe elegir un valor entre 1 y {max}.',
    'val.star_min'          => 'Debe elegir al menos 1 estrella.',
    'val.star_range'        => 'Debe elegir entre 1 y {max} estrellas.',
    'val.star_max'          => 'Debe elegir como máximo {max} estrellas.',
    'val.file_too_big'     => 'El archivo supera el tamaño máximo permitido ({max} MB).',
    'val.file_count'       => 'Puedes adjuntar como máximo {max} archivo(s).',
    'val.file_type'        => 'Ese tipo de archivo no está permitido.',
    'val.blocked_extension' => 'Ese tipo de archivo está bloqueado por seguridad.',
    'val.no_file'          => 'No se recibió ningún archivo.',
    'val.upload_partial'   => 'La subida se interrumpió; intenta de nuevo.',
    'val.upload_too_big'   => 'El archivo supera el tamaño máximo permitido por el servidor.',
    'val.server_error'     => 'No se pudo guardar el archivo. Intenta de nuevo.',

    // Mensajes del borrador (solo JS)
    'js.saved'    => 'Borrador guardado',
    'js.restored' => 'Borrador recuperado automáticamente',

    // Estados de disponibilidad
    'status.closed_title'   => 'Formulario cerrado',
    'status.not_open_title' => 'Aún no está abierto',
    'status.closed_note'    => 'ya no acepta respuestas.',
    'status.not_open_note'  => 'aún no acepta respuestas. Vuelve más tarde.',
    'status.full_title'     => 'Cupo agotado',
    'status.full_note'      => 'alcanzó el límite de respuestas permitidas.',
    'status.draft_title'    => 'En preparación',
    'status.draft_note'     => 'Este formulario aún no ha sido publicado por su propietario.',

    // Gracias + quiz
    'thanks.custom_title'   => '¡Gracias por tu respuesta!',
    'thanks.title'          => '¡Respuesta enviada!',
    'thanks.body'           => 'Gracias por completar <strong>{title}</strong>.',
    'thanks.again'          => 'Enviar otra respuesta',
    'thanks.redirect_blocked' => 'Este formulario configura una redirección a <strong>{host}</strong>, que no está en la lista de dominios permitidos de esta instalación. Tu respuesta se registró igualmente.',
    'quiz.question'         => 'Pregunta #{n}',
    'quiz.result'           => '{points} de {max} correctas',
    'quiz.perfect'          => '¡Perfecto! Dominas el tema.',
    'quiz.review'           => 'Revisa las preguntas en rojo.',
    'quiz.correct'          => 'Correcta',
    'quiz.your_answer'      => 'Tu respuesta:',
    'quiz.correct_answer'   => 'Correcta:',

    // Etiquetas de tipo para el detalle del quiz
    'type.text'         => 'Texto',
    'type.textarea'     => 'Texto',
    'type.email'        => 'Dato',
    'type.url'          => 'Dato',
    'type.tel'          => 'Dato',
    'type.number'       => 'Número',
    'type.nps'          => 'NPS',
    'type.star'         => 'Estrellas',
    'type.scale'        => 'Escala',
    'type.range'        => 'Rango',
    'type.select'       => 'Selección',
    'type.radio'        => 'Opción única',
    'type.checkbox'     => 'Opciones múltiples',
    'type.matrix'       => 'Matriz',
    'type.ranking'      => 'Ranking',
    'type.section'      => 'Sección',
    'type.page'         => 'Página',

    // Correos (Notifier + templates mail/*)
    'mail.notif_subject' => 'Nueva respuesta',
    'mail.notif_title'   => 'Nueva respuesta recibida',
    'mail.notif_intro'   => 'Se recibió una nueva respuesta en el formulario <strong>{title}</strong>.',
    'mail.response_id'   => 'ID de respuesta',
    'mail.date'          => 'Fecha',
    'mail.alt_line1'     => 'Se recibió una nueva respuesta',
    'mail.alt_in'        => ' en «{t}».',
    'mail.alt_dot'       => '.',
    'mail.response_id_line' => 'ID de respuesta: {id}',
    'mail.date_line'        => 'Fecha: {date}',
    'mail.conf_subject'     => 'Gracias por tu respuesta',
    'mail.conf_title'       => 'Gracias por tu respuesta',
    'mail.conf_body'        => 'Recibimos correctamente tu respuesta en el formulario «{title}».',
    'mail.conf_alt_base'    => 'Gracias por tu respuesta',
    'mail.conf_alt_in'      => ' en el formulario «{t}».',
    'mail.conf_dot'         => '.',

    // Errores de envío (FillController submit)
    'err.too_many'     => 'Demasiados envíos. Intenta de nuevo en unos minutos.',
    'err.closed'       => 'Este formulario ya no acepta respuestas.',
    'err.not_open'     => 'Este formulario aún no está abierto.',
    'err.quota'        => 'El cupo de respuestas de este formulario está agotado.',
    'err.failed_save'  => 'Ocurrió un error al guardar tu respuesta. Intenta de nuevo.',
    'err.not_accepting' => 'Este formulario no acepta respuestas en este momento.',
    'err.upload_too_big_global' => 'El envío supera el tamaño máximo permitido por el servidor ({max} MB). Intenta con archivos más pequeños.',
    'err.not_embeddable' => 'Este formulario no permite ser embebido.',
];