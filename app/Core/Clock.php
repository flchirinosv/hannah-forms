<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Clock — la frontera entre el UTC en el que se guardan las fechas y la zona en
 * la que se leen (fase 3, zona horaria canónica).
 *
 * El contrato, en tres frases, porque si se rompe una de las tres el error es
 * silencioso:
 *
 *  1. La BD habla UTC. La sesión hace `SET time_zone = '+00:00'` al conectar
 *     (`Database::__construct`) y las columnas son TIMESTAMP, que MariaDB guarda
 *     como instante UTC. Lo que sale de una consulta es un string UTC.
 *  2. PHP habla UTC. `config/bootstrap.php` hace
 *     `date_default_timezone_set('UTC')`, de modo que un `strtotime()` o un
 *     `new DateTime()` de ese string lo interpretan como el instante que es.
 *     Antes PHP corría en America/Mexico_City: el string se imprimía igual (por
 *     eso el error no se veía) pero cualquier aritmética iba 6 h desviada.
 *  3. La conversión a la zona del lector ocurre AQUÍ, en el último momento, y
 *     solo para pintar.
 *
 * La zona de presentación es `app.timezone` (por defecto `America/Caracas`),
 * no la del servidor: una instancia en Caracas tiene que ver sus horas en
 * Caracas aunque el host esté en UTC, que es lo normal en un contenedor.
 *
 * Lo que este servicio NO hace, a propósito:
 *
 *  - No formatea para el usuario. `date()` con un string de la BD ya es correcto
 *    en cuanto a qué instante es, pero se mostraría en UTC. Para pintar hay que
 *    pasar por aquí: `View::date()` / `View::datetime()`.
 *  - No reescribe lo que hay en la BD. Los datos que ya estaban escritos son
 *    instantes UTC válidos y no hay que migrarlos (el plan de remediación pedía
 *    migrarlos; hacerlo habría sido CORROMPERLOS, está verificado con
 *    `strtotime($v . ' UTC') === UNIX_TIMESTAMP($v)` en las 8 submissions).
 */
final class Clock
{
    private static ?DateTimeZone $displayZone = null;

    /**
     * Zona en la que se presenta al usuario (`app.timezone`).
     *
     * Se cachea por petición. Si el identificador no fuera válido, se cae a UTC
     * en vez de reventar: una variable de entorno mal escrita no puede dejar la
     * aplicación sin pintar fechas.
     */
    public static function displayZone(): DateTimeZone
    {
        if (self::$displayZone === null) {
            $name = (string) Config::get('app.timezone', 'America/Caracas');
            self::$displayZone = new DateTimeZone(self::isValidZone($name) ? $name : 'UTC');
        }

        return self::$displayZone;
    }

    public static function isValidZone(string $name): bool
    {
        return in_array($name, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Convierte un string de fecha de la BD (UTC) a la zona de presentación.
     *
     * Acepta lo que devuelve PDO ('Y-m-d H:i:s') y también los formatos con
     * fecha ISO; si el string no es una fecha válida devuelve null en vez de
     * inventar una, para que la vista pueda decidir qué pintar en su lugar.
     */
    public static function toDisplay(?string $utc): ?DateTimeImmutable
    {
        if ($utc === null || trim($utc) === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }

        return $dt->setTimezone(self::displayZone());
    }

    /**
     * Formatea un instante de la BD para pintarlo en la zona de presentación.
     *
     * @param string $format formato de `DateTimeImmutable::format()`
     */
    public static function format(?string $utc, string $format): string
    {
        $dt = self::toDisplay($utc);

        return $dt === null ? '' : $dt->format($format);
    }

    /**
     * Inicio del día LOCAL en UTC, listo para comparar contra una columna
     * TIMESTAMP.
     *
     * Es lo que necesitan los filtros `from`/`to`: comparar el texto `Y-m-d`
     * que eligió el usuario contra la columna directamente compara contra
     * `Y-m-d 00:00:00` **en UTC**, o sea el día del usuario reckoning desde
     * el día en UTC. Para Caracas (UTC-4) el día empieza a las 04:00 UTC, así que
     * todo lo enviado entre las 00:00 y las 04:00 UTC —que para quien está en
     * Caracas es la NOCHE del día anterior— caía en el día que no es.
     *
     * @param string $day `Y-m-d` en la zona de presentación
     */
    public static function dayStartUtc(string $day): string
    {
        return self::localDayBoundary($day, false);
    }

    /**
     * Final EXCLUSIVO del día local, en UTC: el comienzo del día siguiente.
     *
     * Excluido a propósito, para poder escribir `>= desde AND < hasta`. Cerrar
     * con `23:59:59` deja fuera la última fracción de segundo y obliga a que
     * dos sitios distintos (el filtro y el agrupamiento) coincidan en un segundo
     * exacto; con dos timestamps la condición no tiene bordes que dominar.
     *
     * @param string $day `Y-m-d` en la zona de presentación
     */
    public static function dayEndUtc(string $day): string
    {
        return self::localDayBoundary($day, true);
    }

    /**
     * Desplaza una fecha `Y-m-d` un número de días, en la zona de presentación.
     */
    public static function addDays(string $day, int $days): string
    {
        $dt = new DateTimeImmutable($day . ' 00:00:00', self::displayZone());

        return $dt->modify(($days >= 0 ? '+' : '-') . abs($days) . ' days')->format('Y-m-d');
    }

    /**
     * El string `day . ' HH:MM:SS'` que se le pasa a MariaDB para comparar (o
     * agrupar por) en la zona de presentación, y el desplazamiento fijo que
     * necesita `CONVERT_TZ`.
     *
     * El desplazamiento se calcula una vez, para el instante de referencia de la
     * consulta, y se devuelve como string tipo `-04:00`. Se hace así porque
     *
     *  - `CONVERT_TZ` con un identificador de zona ('America/Caracas') exige
     *    que el servidor tenga cargadas las tablas de zonas horarias, que en una
     *    instalación normal de MariaDB no están: sin ellas devuelve NULL y el
     *    agrupamiento se queda vacío sin decir nada.
     *  - Y el identificador no se puede pasar como parámetro en un `SET time_zone`
     *    desde una consulta preparada de forma fiable, así que el número va en
     *    la cadena (está generado por `DateTimeZone::getOffset()`, no viene del
     *    cliente).
     *
     * LIMITACIÓN CONOCIDA: un desplazamiento constante vale para una zona sin
     * horario de verano en el rango consultado, que es el caso de la zona
     * canónica (Caracas no aplica DST desde 2016). Con una zona que sí tenga
     * DST, un rango que cruce el cambio quedaría desfasado una hora en los
     * buckets diarios; para arreglarlo habría que agrupar en PHP por fila, que
     * es lo que se hizo aquí precisamente para no arrastrar esa limitación al
     * código de la app. Está anotado en el plan por si algún día hace falta.
     */
    public static function localSqlOffsetFor(string $referenceUtc = 'now'): string
    {
        $ref = new DateTimeImmutable($referenceUtc, new DateTimeZone('UTC'));
        $offset = self::displayZone()->getOffset($ref);

        $sign = $offset < 0 ? '-' : '+';
        $abs = abs($offset);

        return $sign . sprintf('%02d:%02d', intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }

    private static function localDayBoundary(string $day, bool $nextDay): string
    {
        $zone = self::displayZone();
        $dt = new DateTimeImmutable($day . ' 00:00:00', $zone);
        if ($nextDay) {
            $dt = $dt->modify('+1 day');
        }

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
