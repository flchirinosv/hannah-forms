<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AccessController;
use App\Controllers\AdminController;
use App\Controllers\FormController;
use App\Controllers\ResponsesController;
use App\Controllers\ReviewController;
use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormPanel;

/**
 * Acciones del panel por fetch: la misma ruta responde JSON o recarga.
 *
 * `Response::wantsJson()` decide por la cabecera `X-Requested-With`, que es lo
 * único que distingue el camino nuevo del de siempre. Eso obliga a que los dos
 * caminos coexistence, y lo que se fija aquí es que el NUEVO no se coma el
 * VIEJO: sin JS —o con una respuesta que no es JSON, que es lo que hace
 * `app.js` volver al camino nativo— el POST tiene que seguir redirigiendo con
 * su flash.
 *
 * La otra mitad, y la que de verdad se rompe sola, es la DEPDENCIA INVISIBLE de
 * los huecos: los cuatro bloques que se reponen están pintados según
 * `forms.status`, y hay acciones cuyo efecto sobre el estado NO es el que
 * dice su nombre. Guardar unos ajustes en un formulario publicado con revisión
 * activa lo devuelve a la cola (`Form::submitForReview()` dentro de la
 * transacción del builder), así que un guardado de ajustes que devolviera solo
 * «Ajustes guardados» dejaría la insignia diciendo «Publicado» mientras el
 * formulario ya no lo está. Por eso el test del guardado mira la insignia que
 * vuelve, no el mensaje.
 */
class AsyncFormActionsTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $ownerId = null;
    private static ?string $ownerEmail = null;
    private static ?int $formId = null;
    private static ?string $slug = null;
    private static ?int $approverId = null;

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();

        unset($_SERVER['HTTP_X_REQUESTED_WITH']);

        // Política de la instancia apagada y aislada de la BD: los tests que la
        // necesiten encendida la activan con el seam.
        AppSettings::setForTesting([]);

        self::$ownerEmail = 'async-' . bin2hex(random_bytes(4)) . '@test.local';
        self::$ownerId = User::create('Async Owner', self::$ownerEmail, 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Formulario de las acciones');
        self::$slug = (string) Form::find((int) self::$formId, false)['slug'];

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Response::setRecordHalt(false);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        AppSettings::forgetForTesting();
        Auth::forgetCache();
        Session::destroy();
        self::cleanupResiduals();
    }

    /* ---------- Los huecos de la ficha ---------- */

    public function testLosHuecosDeLaFichaSonCuatroYDependenDelEstado(): void
    {
        // `slots()` recibe el ID y RELEE el formulario, no el `$form` del POST:
        // quien lo llama puede tener el estado anterior en la memoria, y el
        // HTML tiene que ser el del estado real.
        $slots = FormPanel::slots((int) self::$formId);
        assertSame(
            ['vfSlotStatusBadge', 'vfSlotReviewTrail', 'vfSlotActions', 'vfSlotShare'],
            array_keys($slots),
            'son cuatro huecos y los cuatro se reponen: si uno se queda fuera, esa '
                . 'parte de la ficha se queda con lo que tenía antes de la acción'
        );

        foreach (['draft', 'in_review', 'published', 'closed'] as $estado) {
            $this->setStatus($estado);
            $html = FormPanel::slots((int) self::$formId);

            assertTrue(
                str_contains($html['vfSlotStatusBadge'], 'app-status-' . $estado),
                'la insignia de un formulario «' . $estado . '» tiene que llevar su clase'
            );
            assertTrue($html['vfSlotActions'] !== '', 'el hueco de acciones se pinta en los cuatro estados');
            assertTrue($html['vfSlotShare'] !== '', 'el de compartir también, aunque cambie de forma');
        }

        $this->setStatus('draft');
    }

    public function testCerrarPorFetchReponeLaInsigniaLaAccionYLaTarjeta(): void
    {
        $this->publish();

        $r = $this->post('close', new FormController(), (int) self::$formId);

        assertTrue($r['json']['ok'] ?? false, 'cerrar por fetch responde ok');
        $html = $r['json']['html'] ?? [];
        assertSame(
            ['vfSlotStatusBadge', 'vfSlotReviewTrail', 'vfSlotActions', 'vfSlotShare'],
            array_keys((array) $html),
            'trae los cuatro huecos, no solo la insignia'
        );

        assertTrue(
            str_contains((string) $html['vfSlotStatusBadge'], 'app-status-closed'),
            'la insignia vuelve diciendo «Cerrado»'
        );
        assertTrue(
            str_contains((string) $html['vfSlotActions'], 'id="formReopenActions"'),
            'las acciones ofrecen reabrir'
        );
        assertTrue(
            !str_contains((string) $html['vfSlotActions'], 'id="formClose"'),
            'y ya no ofrecen cerrar: sin esto, el botón de cerrar se queda en '
                . 'pantalla apuntando a un formulario que ya está cerrado'
        );
        assertTrue(
            !str_contains((string) $html['vfSlotShare'], 'id="shareUrl"'),
            'la tarjeta de compartir pierde el enlace público al cerrar: un formulario '
                . 'cerrado no se envía y el enlace lleva a una página informativa'
        );
        assertSame('closed', $this->status(), 'y en la base de datos está cerrado');
    }

    public function testPublicarPorFetchReponeLaTarjetaDeCompartir(): void
    {
        $r = $this->post('publish', new FormController(), (int) self::$formId);

        assertTrue($r['json']['ok'] ?? false, 'publicar por fetch responde ok');
        $html = (array) ($r['json']['html'] ?? []);

        assertTrue(
            str_contains((string) $html['vfSlotShare'], 'id="shareUrl"'),
            'publicar repone el enlace público en la tarjeta de compartir'
        );
        assertTrue(
            str_contains((string) $html['vfSlotActions'], 'id="formClose"'),
            'y las acciones pasan a ofrecer cerrar'
        );
        assertTrue(
            str_contains((string) $html['vfSlotStatusBadge'], 'app-status-published'),
            'la insignia vuelve diciendo «Publicado»'
        );
    }

    /**
     * Guardar ajustes repone TAMBIÉN la tarjeta de Acceso, que es lo único que
     * depende del modo.
     *
     * El hueco va aparte de los cuatro del estado (`FormPanel::accessSlot()`) y
     * por eso lo pedía solo el guardado de ajustes: `slots()` se devuelve también
     * al publicar y al cerrar, que es cuando el modo no ha cambiado, y mandar
     * esa tarjeta ahí tiraría el enlace de acceso recién emitido y lo que
     * alguien tuviera escrito en el campo del alta.
     *
     * Lo que se comprueba es el modo NUEVO en el HTML: es un ajuste que se
     * guarda bien y no se ve, y eso no da error ni en el navegador ni en el log.
     */
    public function testGuardarAjustesReponeLaTarjetaDeAcceso(): void
    {
        $this->publish();
        FormAccess::addEmail((int) self::$formId, 'antes@ejemplo.com');

        // A «solo la lista»: tiene que volver la lista CON el alta, que es la
        // parte que no existía en la pantalla anterior.
        $r = $this->post('updateSettings', new FormController(), (int) self::$formId, [
            'access_mode' => 'allowlist',
        ]);

        assertTrue($r['json']['ok'] ?? false, 'guardar ajustes por fetch responde ok');
        $html = (array) ($r['json']['html'] ?? []);

        assertTrue(
            isset($html['vfSlotAccess']),
            'el hueco de la tarjeta de Acceso viene en el mismo POST que la insignia: '
                . 'sin él, cambiar «quién puede responder» se guardaba y la tarjeta '
                . 'seguía diciendo el modo anterior'
        );
        $tarjeta = (string) $html['vfSlotAccess'];
        assertTrue(
            str_contains($tarjeta, 'id="accessAddForm"') && str_contains($tarjeta, 'antes@ejemplo.com'),
            'y vuelve pintada con la lista, que es lo que el modo nuevo habilita'
        );
        assertTrue(
            str_contains($tarjeta, 'Solo la lista'),
            'la insignia de la tarjeta dice el modo que queda'
        );

        // Y a «público»: la lista desaparece de la tarjeta (dejarla puesta sería
        // decir que el formulario está cerrado para el mundo cuando no lo está).
        $r2 = $this->post('updateSettings', new FormController(), (int) self::$formId, [
            'access_mode' => 'public',
        ]);
        $tarjeta2 = (string) ($r2['json']['html']['vfSlotAccess'] ?? '');

        assertTrue(
            str_contains($tarjeta2, 'Público'),
            'al volver a público, la insignia lo dice'
        );
        assertTrue(
            !str_contains($tarjeta2, 'id="accessAddForm"'),
            'y el alta de la lista ya no se pinta: es la otra variante de la tarjeta'
        );

        // Los cuatro huecos del estado siguen ahí: `accessSlot()` se SUMA, no
        // reemplaza. Sin esto, añadir el quinto hueco habría roto la insignia.
        assertTrue(
            isset($r2['json']['html']['vfSlotStatusBadge'], $r2['json']['html']['vfSlotShare']),
            'y los cuatro huecos del estado vienen igualmente: son cinco, no uno'
        );
    }

    /**
     * El caso invisible: guardar ajustes NO es «no tocar el estado». Con
     * revisión activa, guardar sobre un formulario aprobado lo devuelve a la
     * cola y borra la aprobación anterior, así que el hueco de la insignia
     * tiene que decir «En revisión» aunque la operación se llamara «guardar
     * ajustes».
     */
    public function testGuardarAjustesTraeLaInsigniaDelEstadoQueQueda(): void
    {
        $this->post('publish', new FormController(), (int) self::$formId);
        // La revisión es de la instancia y el dueño (rol `user`) no está exento.
        AppSettings::setForTesting([
            AppSettings::REVIEW_REQUIRED     => true,
            AppSettings::REVIEW_NOTIFY_EMAIL => false,
        ]);

        // La agenda es de las cosas que «importan» al publicar (`publishedChanges-
        // Matter`): hay que CAMBIARLA, porque mandar otra vez la misma no la
        // mueve y entonces no hay nada que comprobar.
        // Con nombre PLANO, no `schedule[close_at]`: `fromDashboardPost()` lee
        // `$post['close_at']` (el `input name="close_at"` de la vista). Mandar
        // la clave anidada no es «el mismo dato con otro nombre», es una clave
        // que el servidor no lee, y el test pasaba sin comprobar el guardado.
        $r = $this->post('updateSettings', new FormController(), (int) self::$formId, [
            'close_at' => '2030-01-31T23:59',
        ]);

        assertTrue($r['json']['ok'] ?? false, 'guardar ajustes por fetch responde ok');
        $html = (array) ($r['json']['html'] ?? []);

        assertSame('in_review', $this->status(), 'el guardado lo devuelve a la cola de revisión');
        assertTrue(
            // La clase CSS lleva el guion bajo tal cual (`app-status-in_review`,
            // es la que le pone el color) y el TEXTO sale de
            // `Form::statusLabel()`: dos cosas distintas, y por eso se comprueban
            // las dos. Con `ucfirst` el texto era «In_review».
            str_contains((string) $html['vfSlotStatusBadge'], 'app-status-in_review')
                && str_contains((string) $html['vfSlotStatusBadge'], 'En revisión'),
            'la insignia repuesta dice «En revisión»: sin esto el panel anuncia '
                . '«Publicado» de un formulario que ya está en la cola'
        );
        // Que sean las acciones DE LA COLA. En `in_review`, «Aprobar» y
        // «Devolver» son del permiso `forms.review` (este usuario es un `user`),
        // así que lo que se puede comprobar sin ese permiso es que ya NO
        // aparezca el botón de publicar: con revisión activa, publicar sería
        // aprobarse a uno mismo.
        assertTrue(
            !str_contains((string) $html['vfSlotActions'], 'id="formPublishActions"'),
            'y las acciones ya no ofrecen publicar: un formulario en revisión se '
                . 'publica aprobándolo, no con el botón del dueño'
        );
        assertTrue(
            !str_contains((string) $html['vfSlotActions'], 'id="formClose"'),
            'ni cerrar uno que ya no está publicado'
        );
    }

    public function testAprobarPorFetchTraeLaTrazaDeRevision(): void
    {
        // Aprobar exige el PERMISO `forms.review` y no «ser el dueño» (es la
        // Lesson de F3: un `canEdit()` leído como autorización para aprobar es
        // justo el error). Con el rol `user` del resto de este fichero, esta
        // llamada no llegaría ni a mirar el formulario.
        self::$approverId = $this->approver();
        Auth::assumeLoggedIn((int) self::$approverId);
        Auth::forgetCache();

        // Desde BORRADOR, que es el único estado del que se puede mandar a
        // revisión: `sendReview()` rechaza un formulario publicado («ciérralo
        // antes»), así que publicar primero haría que «aprobar» no tuviera nada
        // que aprobar y el test probaría la rama del rechazo.
        AppSettings::setForTesting([
            AppSettings::REVIEW_REQUIRED     => true,
            AppSettings::REVIEW_NOTIFY_EMAIL => false,
        ]);
        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
        $this->post('sendReview', new ReviewController(), (int) self::$formId);
        assertSame('in_review', $this->status(), 'el formulario está en la cola antes de aprobar');

        Auth::assumeLoggedIn((int) self::$approverId);
        Auth::forgetCache();

        $r = $this->post('approve', new ReviewController(), (int) self::$formId);

        assertTrue($r['json']['ok'] ?? false, 'aprobar por fetch responde ok');
        $html = (array) ($r['json']['html'] ?? []);

        assertSame('published', $this->status(), 'el formulario queda publicado');
        assertTrue(
            str_contains((string) $html['vfSlotStatusBadge'], 'app-status-published'),
            'la insignia dice «Publicado»'
        );
        // «Aprobado» con mayúscula, como lo pinta el partial, y NOMBRANDO al
        // revisor: la traza existe para poder preguntar «¿quién publicó esto?»,
        // y un texto sin nombre no contesta eso.
        assertTrue(
            str_contains((string) $html['vfSlotReviewTrail'], 'Aprobado')
                && str_contains((string) $html['vfSlotReviewTrail'], 'Aprobador'),
            'y la traza de revisión se repone con la aprobación y su autor: sin '
                . 'ello, el historial se queda en «mandado a revisión» tras aprobar'
        );
        assertTrue(
            str_contains((string) $html['vfSlotShare'], 'id="shareUrl"'),
            'la tarjeta de compartir aparece al publicar'
        );
    }

    /* ---------- El camino nativo no se toca ---------- */

    public function testSinLaCabeceraLaAccionSigueSiendoEl302DeSiempre(): void
    {
        // Esto es lo que ejecuta el navegador cuando no hay JS, o cuando
        // `app.js` decide que la respuesta no era JSON y devuelve el envío al
        // camino nativo. Si este test falla, se ha roto el formulario para todo
        // el mundo por arreglarlo para los que tienen fetch.
        $this->publish();
        Session::set('_csrf_token', Csrf::token());
        $_POST = ['_token' => Session::get('_csrf_token')];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);

        $r = $this->invoke('close', new FormController(), [(int) self::$formId]);

        assertSame('redirect', (string) ($r['halt']['type'] ?? ''), 'sin la cabecera redirige, no responde JSON');
        // Una sola llamada: `getFlash()` consume el valor, así que leerlo dos
        // veces (para el `is_string` y para el largo) lo vacía y el test falla
        // por su propia forma de preguntar.
        $flash = Session::getFlash('success');
        assertTrue(
            is_string($flash) && $flash !== '',
            'y deja su flash de éxito (que es lo que se pinta arriba tras la recarga)'
        );
        assertSame('closed', $this->status(), 'el efecto en la base de datos es el mismo');
    }

    public function testElFormularioBorradoPorFetchRedirigeEnLugarDeRepintar(): void
    {
        $r = $this->post('delete', new FormController(), (int) self::$formId);

        assertTrue($r['json']['ok'] ?? false, 'borrar por fetch responde ok');
        assertTrue(
            ($r['json']['redirect'] ?? '') !== '',
            'y trae a dónde ir: la pantalla del formulario ya no existe, así que no '
                . 'hay nada que reponer y quedarse quieto sería un callejón sin salida'
        );
        assertFalse(isset($r['json']['html']), 'no trae huecos: no hay ficha que reponer');
    }

    /* ---------- Allowlist de acceso (F4) ---------- */

    /**
     * El ALTA también va por fetch, y devuelve la lista **por su id**.
     *
     * Esto no lo subjectaba ningún test y costaba un viaje por cada correo
     * escrito: `addEmail()` contestaba `html` como una CADENA (no como el mapa
     * `id → markup` que recorre `vfApplySlots()`), así que el JS no tenía hueco
     * que reponer y el formulario se iba por su POST nativo —recargando— aunque
     * el alta y el borrado son la misma operación con el mismo partial.
     */
    public function testAnadirUnEmailPorFetchReponeLaListaYNoRecarga(): void
    {
        Form::saveSettings((int) self::$formId, ['access' => ['mode' => 'allowlist']]);

        $correo = 'nuevo-' . bin2hex(random_bytes(3)) . '@test.local';
        $r = $this->post('addEmail', new AccessController(), (int) self::$formId, ['email' => $correo]);

        assertTrue($r['json']['ok'] ?? false, 'añadir por fetch responde ok');
        assertTrue(
            isset($r['json']['html']['accessList']),
            'el alta devuelve la lista en el hueco `accessList`, que es el id del '
                . 'contenedor de la vista: como cadena suelta no hay a qué reponer '
                . 'y el alta se queda en el POST nativo (y recarga la página)'
        );
        assertTrue(
            is_string($r['json']['html']['accessList'])
                && str_contains($r['json']['html']['accessList'], $correo),
            'y la lista ya trae el correo nuevo, sin recargar'
        );
        assertTrue(
            (int) ($r['json']['count'] ?? 0) === 1,
            'el total que queda también viene, para que la vista no lo calcule'
        );
    }

    /**
     * Un correo repetido se pinta junto a su campo y no sale también flotante.
     *
     * El `ok:false` no es un fallo de transporte: es el servidor diciendo que ese
     * correo ya está, y `#accessAddError` (`role="alert"`) es donde lo anuncia el
     * lector de pantalla. Que además salga el aviso flotante sería el mismo texto
     * dos veces, y el arnés de `app.js` lo comprueba.
     */
    public function testUnCorrepoRepetidoVuelveComoErrorYNoComoExito(): void
    {
        Form::saveSettings((int) self::$formId, ['access' => ['mode' => 'allowlist']]);
        $correo = 'repe-' . bin2hex(random_bytes(3)) . '@test.local';
        $this->post('addEmail', new AccessController(), (int) self::$formId, ['email' => $correo]);

        $r = $this->post('addEmail', new AccessController(), (int) self::$formId, ['email' => $correo]);

        assertFalse($r['json']['ok'] ?? true, 'el segundo alta no puede salir como ok');
        assertTrue(
            !isset($r['json']['html']),
            'y no trae lista que reponer: no ha cambiado nada'
        );
        assertTrue(
            str_contains((string) ($r['json']['error'] ?? ''), $correo),
            'el motivo dice qué correo es el que ya está, que es lo que lo hace útil'
        );
    }

    public function testQuitarUnEmailDevuelveLaListaYNoUnFlash(): void
    {
        $this->post('publish', new FormController(), (int) self::$formId);
        Form::saveSettings((int) self::$formId, ['access' => ['mode' => 'allowlist']]);

        $correo = 'invitado-' . bin2hex(random_bytes(3)) . '@test.local';
        $this->post('addEmail', new AccessController(), (int) self::$formId, ['email' => $correo]);

        $antes = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS n FROM form_access_emails WHERE form_id = :f',
            ['f' => (int) self::$formId]
        );
        assertSame(1, (int) ($antes['n'] ?? 0), 'el email está en la lista');

        $r = $this->post('removeEmail', new AccessController(), (int) self::$formId, ['email' => $correo]);

        assertTrue($r['json']['ok'] ?? false, 'quitar por fetch responde ok');
        assertTrue(
            isset($r['json']['html']['accessList']),
            'devuelve la lista reescrita: el email quitado de la BD tiene que '
                . 'desaparecer de la pantalla sin recargarla'
        );
        assertTrue(
            !str_contains((string) $r['json']['html']['accessList'], $correo),
            'y la lista ya no lo contiene'
        );
        assertNull($r['json']['flash'] ?? null, 'sin flash: el aviso va en el mensaje, no en algo que solo se ve tras recargar');

        $despues = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS n FROM form_access_emails WHERE form_id = :f',
            ['f' => (int) self::$formId]
        );
        assertSame(0, (int) ($despues['n'] ?? 0), 'y la fila está borrada');
    }

    /* ---------- Usuarios de /admin ---------- */

    public function testElToggleDeUsuarioEsIdempotenteYDevuelveElEstadoPedido(): void
    {
        $email = 'toggle-' . bin2hex(random_bytes(4)) . '@test.local';
        $id = (int) User::create('Toggle', $email, 'clave-larga-123', 2);

        // El que actúa necesita `admin.users` (el mismo permiso que el alta por
        // panel), así que aquí va un admin y no el `user` del resto del fichero.
        $adminId = (int) User::create('Admin del test', 'admin-toggle-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 1);
        Auth::assumeLoggedIn($adminId);
        Auth::forgetCache();

        // `toggleActive()` no recibe el usuario como argumento: lo lee del POST
        // (`user_id`), que es lo que manda el formulario.
        $r = $this->postArgs('toggleActive', new AdminController(), [], ['user_id' => $id, 'active' => '0']);

        assertTrue($r['json']['ok'] ?? false, 'desactivar por fetch responde ok');
        assertSame(
            0,
            (int) $this->isActiveOf($id),
            'la cuenta queda desactivada'
        );
        assertTrue(
            str_contains((string) ($r['json']['html']['vfUserStatus' . $id] ?? ''), 'Inactivo'),
            'la insignia devuelta dice «Inactivo»: tiene que reflejar el estado '
                . 'PEDIDO, que es lo que la deja fija un reintento'
        );
        assertTrue(
            str_contains((string) ($r['json']['html']['vfUserToggle' . $id] ?? ''), 'value="1"'),
            'y el botón devuelto ofrece activar (no desactivar): con un POST de «al '
                . 'revés», dos clics dejaban la cuenta en el estado contrario'
        );

        // El mismo POST otra vez: no cambia nada y lo dice.
        $r2 = $this->postArgs('toggleActive', new AdminController(), [], ['user_id' => $id, 'active' => '0']);
        assertSame(
            0,
            (int) $this->isActiveOf($id),
            'repetir el mismo POST deja la cuenta igual (idempotente)'
        );
        assertTrue(
            str_contains((string) $r2['json']['message'], 'ya estaba'),
            'y lo dice en el mensaje, en vez de fingir que acaba de cambiar algo'
        );
        assertTrue(
            str_contains((string) ($r2['json']['html']['vfUserStatus' . $id] ?? ''), 'Inactivo'),
            'con la insignia igual: el contador de cambios no miente'
        );
    }

    /**
     * El rol y los grupos de una fila se guardan por fetch.
     *
     * El rol va sin ningún hueco que reponer, y es a propósito: cambiar el rol no
     * cambia nada de lo que se ve en la fila (el `select` ya está en el valor
     * nuevo), así que lo único que hay que pintar es el aviso. Repintar la fila
     * entera tiraría por la ventana el desplegable de grupos que se estuviera
     * editando al lado.
     *
     * Los grupos sí tienen hueco: las insignias del botón son lo único de esa
     * celda que depende de la pertenencia.
     */
    public function testCambiarElRolYLosGruposDeUnaFilaRespondeJson(): void
    {
        $adminId = (int) User::create('Admin del test', 'admin-fila-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 1);
        $id = (int) User::create('Fila', 'fila-' . bin2hex(random_bytes(4)) . '@test.local', 'clave-larga-123', 2);
        // `role_groups.name` es ÚNICO: un nombre fijo deja la fila del intento
        // anterior (o de otra clase) y el segundo POST revienta con un 1062 que
        // no tiene nada que ver con lo que se está probando.
        $grupoNombre = 'Grupo ' . bin2hex(random_bytes(4));
        $grupoId = (int) RoleGroup::create($grupoNombre, 'para el test');
        Auth::assumeLoggedIn($adminId);
        Auth::forgetCache();

        $r = $this->postArgs('updateRole', new AdminController(), [], [
            'user_id' => $id,
            'role_id' => 3,
        ]);

        assertTrue($r['json']['ok'] ?? false, 'cambiar el rol por fetch responde ok');
        assertTrue(
            !isset($r['json']['html']),
            'y NO devuelve HTML: nada de la fila depende del rol, y mandar markup '
                . 'para nada es una forma de reescribir algo que ya está bien'
        );
        assertSame(
            3,
            (int) ((User::find($id)['role_id'] ?? 0)),
            'el rol queda cambiado en la BD'
        );

        // Un rol inventado se pinta como error y NO se aplica. El 302 de siempre
        // no sirve aquí: un fetch que lo sigue se descarga la página entera de
        // usuarios dentro del fetch y no sabría que el guardado falló.
        $r2 = $this->postArgs('updateRole', new AdminController(), [], [
            'user_id' => $id,
            'role_id' => 9999,
        ]);
        assertFalse($r2['json']['ok'] ?? true, 'un rol que no existe no sale como ok');
        assertSame(
            3,
            (int) ((User::find($id)['role_id'] ?? 0)),
            'y la cuenta se queda con el rol de antes'
        );

        // Grupos: vuelve el hueco con las insignias y el NOMBRE lo pone el
        // servidor (el `id` del hueco lo compone el POST, con el id de la fila).
        $r3 = $this->postArgs('updateUserGroups', new AdminController(), [], [
            'user_id' => $id,
            'groups' => [$grupoId],
        ]);

        assertTrue($r3['json']['ok'] ?? false, 'guardar grupos por fetch responde ok');
        assertTrue(
            isset($r3['json']['html']['vfUserGroups' . $id]),
            'el hueco de las insignias viene con el id de la fila, que es como la '
                . 'vista lo nombra'
        );
        assertTrue(
            str_contains((string) $r3['json']['html']['vfUserGroups' . $id], $grupoNombre),
            'y trae la insignia con el nombre del grupo'
        );
    }

    /* ---------- Borrado de respuestas ---------- */

    public function testBorrarUnaRespuestaDevuelveElTotalQueQueda(): void
    {
        $this->publish();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (int) Submission::create((int) self::$formId, '127.0.0.1', 'TestAgent');
        }

        $r = $this->postArgs('delete', new ResponsesController(), [(int) self::$formId, $ids[0]]);

        assertTrue($r['json']['ok'] ?? false, 'borrar una respuesta por fetch responde ok');
        assertSame(
            2,
            (int) ($r['json']['count'] ?? -1),
            'devuelve el total que QUEDA: el contador del encabezado («Respuestas '
                . '(3)») es del servidor y sin este número la lista acertaba y el número no'
        );
    }

    /* ---------- ayudas ---------- */

    /**
     * Un revisor con el PERMISO `forms.review` y sin nada más. Se crea con un
     * rol propio para no Heredar `admin.access` (que sí puede borrar
     * formularios ajenos): lo que se quiere probar es que aprobar depende del
     * permiso de revisar.
     */
    private function approver(): int
    {
        $email = 'approver-' . bin2hex(random_bytes(4)) . '@test.local';
        $roleId = Role::create('revisor-' . bin2hex(random_bytes(3)), 'Revisor de test');
        assertTrue($roleId !== null, 'se pudo crear el rol de revisor');
        // `forms.manage_all` además de `forms.review`: `ReviewController::reviewable()`
        // pasa por `Access::canEdit()`, y un revisor que no puede tocar el
        // formulario de otro no tiene nada que aprobar —que es el caso normal,
        // porque el dueño no es quien aprueba.
        Role::setPermissions((int) $roleId, ['forms.review', 'dashboard.view', 'forms.manage_all']);

        return (int) User::create('Aprobador', $email, 'clave-larga-123', (int) $roleId);
    }

    private function publish(): void
    {
        $r = $this->post('publish', new FormController(), (int) self::$formId);
        assertTrue($r['json']['ok'] ?? false, 'el publish de preparación del test falló: ' . json_encode($r['json'] ?? []));
    }

    private function setStatus(string $estado): void
    {
        Database::getInstance(Config::db())->execute(
            'UPDATE forms SET status = :s WHERE id = :id',
            ['s' => $estado, 'id' => (int) self::$formId]
        );
    }

    private function status(): string
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT status FROM forms WHERE id = :id',
            ['id' => (int) self::$formId]
        );
        return (string) ($row['status'] ?? '');
    }

    private function isActiveOf(int $id): int
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT is_active FROM users WHERE id = :id',
            ['id' => $id]
        );
        return (int) ($row['is_active'] ?? 0);
    }

    /**
     * POST como lo hace el fetch: con `X-Requested-With` y devolviendo el JSON
     * emitido (que en CLI `Response::json()` imprime en vez de cortar).
     */
    private function post(string $action, object $controller, int $id, array $post = []): array
    {
        return $this->postArgs($action, $controller, [$id], $post);
    }

    /**
     * Igual, pero para las acciones con más de un argumento
     * (`ResponsesController::delete(int $formId, int $submissionId)`), donde
     * adivinar el orden desde el nombre es inventarse una firma.
     */
    private function postArgs(string $action, object $controller, array $args, array $post = []): array
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $r = $this->invoke($action, $controller, $args, $post);

        $json = json_decode((string) ($r['out'] ?? ''), true);
        return [
            'json'  => is_array($json) ? $json : ['ok' => false, 'raw' => substr((string) $r['out'], 0, 200)],
            'halt'  => $r['halt'],
            'exito' => Session::getFlash('success'),
            'error' => Session::getFlash('error'),
        ];
    }

    /**
     * Invoca la acción con CSRF y sin más. `X-Requested-With` lo pone quien
     * llama: el camino nativo es el mismo POST sin esa cabecera.
     */
    private function invoke(string $action, object $controller, array $args, array $post = []): array
    {
        Session::set('_csrf_token', Csrf::token());
        $_POST = array_merge(['_token' => Session::get('_csrf_token')], $post);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Request::setRequestBodyForTests(null);

        Response::setRecordHalt(true);
        ob_start();
        try {
            $controller->{$action}(...$args);
        } finally {
            $out = (string) ob_get_clean();
        }
        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        return ['out' => $out, 'halt' => $halt];
    }

    private static function ensureCleanupHook(): void
    {
        if (self::$cleanupHookRegistered) {
            return;
        }
        self::$cleanupHookRegistered = true;
        register_shutdown_function([self::class, 'cleanupResiduals']);
    }

    public static function cleanupResiduals(): void
    {
        try {
            $db = Database::getInstance(Config::db());
            $db->execute('DELETE FROM form_access_emails WHERE email LIKE :pat', ['pat' => '%@test.local']);
            $db->execute('DELETE FROM role_groups WHERE name LIKE :pat', ['pat' => 'Grupo %']);
            $db->execute('DELETE FROM users WHERE email LIKE :pat', ['pat' => '%@test.local']);
            $db->execute('DELETE FROM forms WHERE title LIKE :pat', ['pat' => 'Formulario de las acciones']);
        } catch (\Throwable $e) {
            // En el apagado puede no haber conexión; no es un fallo del test.
        }
    }
}
