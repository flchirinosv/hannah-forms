<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\BuilderController;
use App\Controllers\FillController;
use App\Controllers\FormController;
use App\Controllers\ReviewController;
use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\PublicUrl;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Form;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;
use App\Services\ReviewPolicy;

/**
 * F3 — Revisión y aprobación de formularios.
 *
 * Lo que se fija aquí no es el camino feliz, es lo que se puede ROMPER por una
 * puerta trasera, y por eso los tests van de lo contrario a lo obvio:
 *
 *  - Que la revisión no se pueda saltar por el builder. Un formulario con
 *    revisión se puede abrir, guardar y volver a abrir; si el POST del builder
 *    aceptara `status: published`, la revisión sería un adorno.
 *  - Que aprobar exija el PERMISO y no «ser el dueño»: son dos cosas distintas
 *    y confundirlas es el error clásico de estos sistemas.
 *  - Que editar un formulario ya publicado y aprobado lo devuelva a la cola: si
 *    no, la aprobación pasa a ser «se aprobó una vez» y el contenido que se
 *    publica después no lo miró nadie.
 *  - Que el aviso por correo sea opt-in y que apunte al panel, no al enlace
 *    público (desde `/f/{slug}` el revisor no podría hacer nada).
 *  - Que la política sea de la INSTANCIA y la exención del DUEÑO: con la
 *    política encendida, el permiso `forms.publish_without_review` del dueño
 *    (por su rol o por un grupo) es lo único que le deja publicar directo, y no
 *    el permiso de quien pulsa el botón.
 *
 * Sobre los destinatarios: `User::reviewers()` devuelve usuarios REALES de la
 * base (el admin del seed tiene el permiso), así que las aserciones de correo
 * se filtran por `@test.local`. Afirmar «un solo correo» sin ese filtro haría
 * que el test dependiera de cuántos administradores hay instalados.
 *
 * Sobre la política: los tests la fijan con el seam `AppSettings::setForTesting()`
 * (que en `setUp` se deja VACÍO = todo apagado, para no depender de la fila real
 * que la migración 0016 haya podido escribir en la BD de test).
 */
class FormReviewTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $ownerId = null;
    private static ?string $ownerEmail = null;
    private static ?int $reviewerId = null;
    private static ?string $reviewerEmail = null;
    private static ?int $reviewRoleId = null;
    private static ?int $formId = null;
    private static ?string $slug = null;

    private $sent = [];

    private const MAIL_CFG = [
        'host'         => 'smtp.example.com',
        'port'         => 587,
        'encryption'   => 'tls',
        'user'         => '',
        'password'     => '',
        'from_address' => 'no-reply@example.com',
        'from_name'    => 'VanillaForms',
        'timeout'      => 15,
    ];

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();
        $this->sent = [];

        // Política de la instancia APAGADA y aislada de la BD: el seam deja
        // `app_settings` fuera de juego, así que las filas reales (la de la
        // migración 0016 incluida) no deciden el resultado de este test. Cada
        // test que la necesite encendida llama a `activarRevision()`.
        AppSettings::setForTesting([]);

        Mailer::setTransport(function (array $envelope, array $config): void {
            $this->sent[] = $envelope;
        });
        Mailer::setConfigOverride(self::MAIL_CFG);
        PublicUrl::setBaseForTesting('https://forms.example.com');

        self::$ownerEmail = 'review-' . bin2hex(random_bytes(4)) . '@test.local';
        self::$ownerId = User::create('Review Owner', self::$ownerEmail, 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Formulario con revisión');
        self::$slug = (string) Form::find((int) self::$formId, false)['slug'];

        // Revisor: rol nuevo con `forms.review` + alcance global. El alcance
        // hace falta porque el formulario es de OTRO usuario y, sin él, el
        // revisor no podría ni verlo (`Access::canView`).
        $db = Database::getInstance(Config::db());
        $db->execute(
            'INSERT INTO roles (name, description) VALUES (:n, :d)',
            ['n' => 'revisor-' . bin2hex(random_bytes(3)), 'd' => 'rol de test F3']
        );
        self::$reviewRoleId = (int) $db->lastInsertId();
        Role::setPermissions((int) self::$reviewRoleId, [
            'dashboard.view', 'forms.review', 'forms.manage_all', 'forms.edit_own',
        ]);
        self::$reviewerEmail = 'reviewer-' . bin2hex(random_bytes(4)) . '@test.local';
        self::$reviewerId = User::create('Review Reviewer', self::$reviewerEmail, 'clave-larga-123', (int) self::$reviewRoleId);

        self::authAs((int) self::$ownerId);
    }

    public function tearDown(): void
    {
        Mailer::setTransport(null);
        Mailer::setConfigOverride(null);
        PublicUrl::setBaseForTesting(null);
        AppSettings::forgetForTesting();
        Response::setRecordHalt(false);
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        Auth::forgetCache();
        Session::destroy();
        self::cleanupResiduals();
    }

    // ---- Política de la instancia ----

    public function testLaRevisionDeLaInstanciaEstaApagadaPorDefecto(): void
    {
        // La política es de la INSTANCIA y nace apagada: si fuese el default,
        // TODO formulario ya creado dejaría de poder publicarse sin un revisor,
        // y en una instalación de un solo usuario no habría salida.
        AppSettings::setForTesting([]);
        assertFalse(ReviewPolicy::policyOn(), 'la revisión no se activa sola');
        assertFalse(ReviewPolicy::notifyEmail(), 'el aviso por correo tampoco');

        $form = Form::find((int) self::$formId, false);
        assertFalse(ReviewPolicy::requiredFor($form), 'sin política, ningún formulario exige revisión');
    }

    public function testConLaPoliticaEncendidaElDuenoSinPermisoQuedaSujetoARevision(): void
    {
        $this->activarRevision(false);
        $form = Form::find((int) self::$formId, false);
        assertTrue(ReviewPolicy::policyOn(), 'la política está encendida');
        assertTrue(
            ReviewPolicy::requiredFor($form),
            'un dueño sin forms.publish_without_review queda sujeto a revisión'
        );
    }

    public function testElDuenoConElPermisoDeExencionNoQuedaSujetoARevision(): void
    {
        $this->activarRevision(false);
        // El dueño pasa a un rol que SÍ trae el permiso de exención.
        Role::setPermissions((int) self::$reviewRoleId, [
            'dashboard.view', 'forms.edit_own', 'forms.publish_without_review',
        ]);
        Database::getInstance(Config::db())->execute(
            'UPDATE users SET role_id = :r WHERE id = :u',
            ['r' => (int) self::$reviewRoleId, 'u' => (int) self::$ownerId]
        );

        $form = Form::find((int) self::$formId, false);
        assertFalse(ReviewPolicy::requiredFor($form), 'el dueño exento no pasa por revisión');
    }

    // ---- Transiciones de estado ----

    public function testCicloCompletoDejaTrazaDeQuienAproboYCuando(): void
    {
        Form::submitForReview((int) self::$formId);
        $row = $this->row();
        assertSame('in_review', $row['status'], 'el formulario queda en revisión');
        assertNotNull($row['review_requested_at'], 'se registra CUÁNDO se pidió');
        assertNull($row['reviewed_at'], 'todavía no está aprobado');
        assertNull($row['reviewed_by'], 'ni sabe quién lo aprobará');

        Form::approve((int) self::$formId, (int) self::$reviewerId);
        $row = $this->row();
        assertSame('published', $row['status'], 'aprobar publica');
        assertNotNull($row['reviewed_at'], 'se registra CUÁNDO se aprobó');
        assertSame((int) self::$reviewerId, (int) $row['reviewed_by'], 'y QUIÉN lo aprobó');

        $trail = Form::reviewTrail((int) self::$formId);
        assertNotNull($trail['solicitado']);
        assertNotNull($trail['aprobado']);
        assertSame('Review Reviewer', $trail['aprobador'], 'la traza nombra al revisor');
    }

    public function testDevolverDejaBorradorYSaleDeLaCola(): void
    {
        Form::submitForReview((int) self::$formId);
        Form::returnToDraft((int) self::$formId);

        $row = $this->row();
        assertSame('draft', $row['status'], 'no se publica');
        assertNull($row['review_requested_at'], 'sale de la cola: la fecha del intento se borra');
        assertNull($row['reviewed_at'], 'y no arrastra ninguna aprobación');

        $this->authAsReviewer();
        // Se mira SOLO este formulario en la cola, no que la cola esté vacía:
        // la bandeja es global y una instancia en uso puede tener formularios
        // de verdad esperando revisión. Afirmar «no hay ninguna pendiente»
        // hace que el test solo pase en una base recién sembrada.
        $enCola = array_map('intval', self::pendingIds());
        assertFalse(in_array((int) self::$formId, $enCola, true), 'sale de la cola: este formulario ya no está entre las pendientes');
    }

    public function testReenviarBorraLaAprobacionAnterior(): void
    {
        Form::submitForReview((int) self::$formId);
        Form::approve((int) self::$formId, (int) self::$reviewerId);
        Form::submitForReview((int) self::$formId);

        $row = $this->row();
        assertSame('in_review', $row['status'], 'vuelve a la cola');
        assertNull($row['reviewed_at'], 'la aprobación anterior se borra: era de otro contenido');
        assertNull($row['reviewed_by'], 'y con ella el nombre del aprobador');
    }

    public function testLaColaOrdenaPorLoQueMasTiempoLlevaParado(): void
    {
        $formAntiguo = Form::create((int) self::$ownerId, 'Antiguo');
        $formReciente = Form::create((int) self::$ownerId, 'Reciente');

        $db = Database::getInstance(Config::db());
        // El más antiguo se pidió antes. Se escriben las fechas a mano porque el
        // método usa UTC_TIMESTAMP() y el test necesita un orden que el reloj no
        // garantiza por sí solo.
        $db->execute(
            "UPDATE forms SET status = 'in_review', review_requested_at = '2020-01-01 00:00:00' WHERE id = :id",
            ['id' => $formAntiguo]
        );
        $db->execute(
            "UPDATE forms SET status = 'in_review', review_requested_at = '2030-01-01 00:00:00' WHERE id = :id",
            ['id' => $formReciente]
        );

        // La cola es GLOBAL: si la instancia tiene otros formularios en
        // revisión (uno de verdad, de otro test) sus fechas se cuelan entre
        // estos dos y las posiciones 0 y 1 ya no son las de este test. Se
        // filtra a los dos formularios propios y se comprueba que la cola los
        // ordena por antigüedad, que es la propiedad que importa.
        $ids = array_values(array_filter(
            array_map('intval', self::pendingIds()),
            static fn (int $id): bool => $id === (int) $formAntiguo || $id === (int) $formReciente
        ));
        assertSame(2, count($ids), 'precondición: los dos formularios están en la cola');
        assertSame((int) $formAntiguo, $ids[0], 'el más antiguo va primero');
        assertSame((int) $formReciente, $ids[1], 'el más reciente va después');
    }

    // ---- El fill: página informativa y POST cerrado ----

    public function testEnRevisionElEnlacePublicoExplicaEnLugarDeDar404(): void
    {
        Form::submitForReview((int) self::$formId);

        $html = $this->renderFill();
        assertTrue(str_contains($html, 'en revisi'), 'debe explicar el estado: ' . substr($html, 0, 200));
        assertTrue(str_contains($html, 'horas' ) || str_contains($html, 'esperando'), 'y decir qué esperar');
        assertFalse(str_contains($html, '<form'), 'no sirve el formulario para responder');
        assertFalse(str_contains($html, 'No se encontr'), 'no es una página de 404');

        // Y el POST tampoco: la página informativa no puede ser el pretexto para
        // aceptar respuestas de un formulario que nadie ha aprobado.
        $halt = $this->postFill();
        assertSame(0, self::submissionCount(), 'no se acepta ningún envío en revisión');
        assertSame(403, $halt['code'] ?? 0, 'el POST corta con 403, igual que en un borrador');
    }

    public function testPublicadoSinRevisionSigueSirviendoElFormulario(): void
    {
        // Regresión: el gate de F3 no puede tocar el camino normal.
        Form::setStatus((int) self::$formId, 'published');
        $html = $this->renderFill();
        assertTrue(str_contains($html, '<form'), 'un formulario publicado se sirve como siempre');
    }

    // ---- La revisión no se puede saltar ----

    public function testElPropietarioNoPuedePublicarSaltandoseLaRevision(): void
    {
        $this->activarRevision(false);
        $this->authAs((int) self::$ownerId);

        $r = $this->call('publish', new FormController(), (int) self::$formId);

        assertSame('draft', $this->row()['status'], 'sigue en borrador: publicar es aprobar');
        assertTrue($r['error'] !== '', 'y se lo dice con un mensaje, no con un 403 mudo');
        assertTrue(str_contains($r['error'], 'revisi'), 'el mensaje explica qué hacer: ' . $r['error']);
    }

    public function testSinRevisionActivadaPublicarSigueFuncionando(): void
    {
        // Regresión: sin la bandera, el botón de publicar de siempre va igual.
        $this->authAs((int) self::$ownerId);
        $this->call('publish', new FormController(), (int) self::$formId);
        assertSame('published', $this->row()['status'], 'el comportamiento por defecto no cambia');
    }

    public function testElDuenoConPermisoPublicaDirectamente(): void
    {
        // La exención se prueba por donde entra de verdad: el dueño con el
        // permiso pulsa Publicar y se publica, sin pasar por la cola.
        $this->activarRevision(false);
        Role::setPermissions((int) self::$reviewRoleId, [
            'dashboard.view', 'forms.edit_own', 'forms.publish_without_review',
        ]);
        Database::getInstance(Config::db())->execute(
            'UPDATE users SET role_id = :r WHERE id = :u',
            ['r' => (int) self::$reviewRoleId, 'u' => (int) self::$ownerId]
        );

        $this->authAs((int) self::$ownerId);
        $this->call('publish', new FormController(), (int) self::$formId);
        assertSame('published', $this->row()['status'], 'con el permiso, publica sin revisión');
    }

    public function testLaExencionEsDelDuenoYNoDeQuienPublica(): void
    {
        // El revisor tiene `forms.manage_all` (puede operar el formulario de
        // otro) pero NO la exención, y el dueño tampoco está exento: la regla es
        // «el dueño está exento», no «quien pulsa está exento».
        $this->activarRevision(false);
        $this->authAsReviewer();

        $r = $this->call('publish', new FormController(), (int) self::$formId);

        assertSame('draft', $this->row()['status'], 'publicar el de otro no exime si el dueño no lo está');
        assertTrue($r['error'] !== '', 'y se lo dice con un mensaje');
    }

    public function testElBuilderTampocoEsUnaPuertaDePublicacion(): void
    {
        $this->activarRevision(false);
        $this->authAs((int) self::$ownerId);

        // Lo que el builder hace de verdad: reenviar el formulario entero con
        // `status: published` en el payload.
        $r = $this->saveBuilder('published');

        assertTrue($r['ok'], 'el guardado va bien (no es un error de validación): ' . json_encode($r));
        assertSame('draft', $this->row()['status'], 'pero el estado no cambia: el campo `status` del builder no publica');
    }

    public function testEditarUnFormularioAprobadoLoDevuelveACola(): void
    {
        $this->activarRevision(false);
        Form::submitForReview((int) self::$formId);
        $this->authAsReviewer();
        Form::approve((int) self::$formId, (int) self::$reviewerId);
        assertSame('published', $this->row()['status'], 'precondición: aprobado y publicado');

        // El dueño edita su contenido.
        $this->authAs((int) self::$ownerId);
        $r = $this->saveBuilder('published');
        assertTrue($r['ok'], 'el guardado va bien');

        $row = $this->row();
        assertSame('in_review', $row['status'], 'cambiar el contenido invalida la aprobación');
        assertNull($row['reviewed_at'], 'y la aprobación anterior se borra');
        assertNull($row['reviewed_by'], 'incluido el nombre de quien aprobó');
        assertNotNull($row['review_requested_at'], 'vuelve a la cola con fecha propia');
    }

    public function testRenombrarUnFormularioAprobadoTambienLoDevuelveACola(): void
    {
        // La misma regla que el builder, pero por la otra puerta de edición.
        // Si aquí no se aplicara, bastaría con cambiar el título desde la ficha
        // para publicar contenido que nadie ha revisado.
        $this->activarRevision(false);
        Form::submitForReview((int) self::$formId);
        $this->authAsReviewer();
        Form::approve((int) self::$formId, (int) self::$reviewerId);

        $this->authAs((int) self::$ownerId);
        $r = $this->postUpdate('Título nuevo');

        $row = $this->row();
        assertSame('in_review', $row['status'], 'editar el título también invalida la aprobación');
        assertNull($row['reviewed_at'], 'y borra la aprobación anterior');
        assertTrue(str_contains($r['exito'], 'revisi'), 'el mensaje le explica por qué: ' . $r['exito']);
    }

    public function testRenombrarSinRevisionSeComportaComoSiempre(): void
    {
        // Regresión: sin la bandera, renombrar no mueve nada.
        Form::setStatus((int) self::$formId, 'published');
        $this->authAs((int) self::$ownerId);
        $this->postUpdate('Título nuevo');
        assertSame('published', $this->row()['status'], 'sin revisión, publicar sigue siendo publicar');
    }

    // ---- Permisos ----
    public function testAprobarExigeElPermisoYNoElHechoDeSerDueño(): void
    {
        Form::submitForReview((int) self::$formId);

        // El dueño, sin `forms.review`: no puede aprobar el suyo.
        $this->authAs((int) self::$ownerId);
        $r = $this->call('approve', new ReviewController(), (int) self::$formId);
        assertSame(403, $r['halt']['code'] ?? 0, 'sin el permiso es 403');
        assertSame('in_review', $this->row()['status'], 'y el formulario no se publica');

        // El revisor, sí.
        $this->authAsReviewer();
        $this->call('approve', new ReviewController(), (int) self::$formId);
        assertSame('published', $this->row()['status'], 'con el permiso se aprueba');
    }

    public function testEnviarARevisionLoHaceQuienPuedeEditarYNoUnAjeno(): void
    {
        // Un usuario normal, sin alcance global: el formulario de otro es
        // intocable. (Con `forms.manage_all` sí podría, y es lo coherente: ese
        // permiso ya permite editar cualquier formulario.)
        $dueno = User::create('Dueño ajeno', 'ajeno-' . bin2hex(random_bytes(4)) . '@test.local', 'clave-larga-123', 2);
        $curioso = User::create('Curioso', 'curioso-' . bin2hex(random_bytes(4)) . '@test.local', 'clave-larga-123', 2);
        $otroForm = Form::create((int) $dueno, 'Formulario ajeno');

        self::authAs((int) $curioso);
        $r = $this->call('sendReview', new ReviewController(), (int) $otroForm);
        assertSame(403, $r['halt']['code'] ?? 0, 'un usuario sin alcance no manda a revisión el formulario de otro');
        assertSame('draft', $this->statusOf((int) $otroForm), 'sigue en borrador');

        // El dueño, sí.
        self::authAs((int) $dueno);
        $this->call('sendReview', new ReviewController(), (int) $otroForm);
        assertSame('in_review', $this->statusOf((int) $otroForm), 'el dueño sí puede mandarlo');
    }

    public function testRevisoresPorGrupoYExcluyeCuentasDesactivadas(): void
    {
        $emails = static fn (): array => array_column(User::reviewers(), 'email');

        assertTrue(in_array((string) self::$reviewerEmail, $emails(), true), 'el usuario con el permiso por su ROL es revisor');
        assertFalse(in_array((string) self::$ownerEmail, $emails(), true), 'el dueño sin el permiso NO es revisor');

        // Permiso heredado de un grupo: el rol del usuario no cambia, así que
        // esto es lo único que puede añadirlo a la lista.
        $groupId = RoleGroup::create('Grupo Revisión-' . bin2hex(random_bytes(3)), 'test');
        RoleGroup::setRoles($groupId, [(int) self::$reviewRoleId]);
        RoleGroup::setUsers($groupId, [(int) self::$ownerId]);
        assertTrue(in_array((string) self::$ownerEmail, $emails(), true), 'un grupo con el rol revisor mete al dueño en la lista');

        RoleGroup::delete($groupId);
        assertFalse(in_array((string) self::$ownerEmail, $emails(), true), 'al salir del grupo, sale de la lista');

        // Una cuenta desactivada no se avisa: no entra ni aunque tenga el permiso.
        User::setActive((int) self::$reviewerId, false);
        assertFalse(in_array((string) self::$reviewerEmail, $emails(), true), 'una cuenta desactivada no recibe avisos');
        User::setActive((int) self::$reviewerId, true);
    }

    // ---- El aviso por correo ----

    public function testElAvisoPorCorreoEsOptIn(): void
    {
        $this->activarRevision(false);
        $this->authAs((int) self::$ownerId);
        $this->call('sendReview', new ReviewController(), (int) self::$formId);

        assertSame(0, count($this->testMails()), 'sin la casilla de aviso no sale ningún correo a los revisores');
        assertSame('in_review', $this->row()['status'], 'pero el formulario sí se manda a revisión');
    }

    public function testConAvisoSeEnviaAlRevisorYApuntaAlPanel(): void
    {
        $this->activarRevision(true);
        $this->authAs((int) self::$ownerId);
        $this->call('sendReview', new ReviewController(), (int) self::$formId);

        $mails = $this->testMails();
        assertSame(1, count($mails), 'un aviso al único revisor de test');
        $envelope = $mails[0];
        assertSame([(string) self::$reviewerEmail], $envelope['to'], 'va a quien puede revisar');
        assertFalse(
            in_array((string) self::$ownerEmail, [$envelope['to'][0]], true),
            'y no al dueño, que no puede revisar'
        );
        assertTrue(
            str_contains($envelope['html'], 'https://forms.example.com/forms/' . (int) self::$formId),
            'el enlace va al panel: desde /f/{slug} el revisor no podría aprobar nada'
        );
        assertTrue(str_contains($envelope['html'], 'Formulario con revisi'), 'y nombra el formulario');
        assertTrue(
            str_contains($envelope['text'], 'https://forms.example.com/forms/' . (int) self::$formId),
            'el texto plano lleva el mismo enlace (clientes sin HTML)'
        );
    }

    public function testUnAvisoQueNoSePuedeEntregarNoRompeElEnvio(): void
    {
        // Sin ningún revisor «de test» el aviso no tiene destinatario. Lo que no
        // puede pasar es un 500 que deje al dueño creyendo que no se envió: la
        // transición ya ocurrió y su mensaje de éxito es lo que él ve.
        //
        // El branch exacto de «cero revisores en toda la instancia» no se puede
        // provocar aquí sin desarmar la matriz de permisos REAL del admin
        // instalado, así que no se fuerza: se fija lo que sí es observable.
        Role::setPermissions((int) self::$reviewRoleId, ['dashboard.view', 'forms.manage_all']);
        User::setActive((int) self::$reviewerId, true);

        $this->activarRevision(true);
        $this->authAs((int) self::$ownerId);
        $r = $this->call('sendReview', new ReviewController(), (int) self::$formId);

        assertSame(0, count($this->testMails()), 'nada que enviar a los revisores de test');
        assertSame('in_review', $this->row()['status'], 'el formulario se manda igual');
        assertTrue($r['exito'] !== '', 'y el dueño ve su mensaje de éxito, no un error');
    }

    // ---- Auditoría ----

    public function testLasTresAccionesQuedanEnElLog(): void
    {
        // El orden importa: devolver SOLO tiene sentido desde `in_review`, y
        // aprobar también. Por eso el segundo envío va después de la devolución.
        $this->authAs((int) self::$ownerId);
        $this->call('sendReview', new ReviewController(), (int) self::$formId);
        $this->authAsReviewer();
        $this->call('returnDraft', new ReviewController(), (int) self::$formId);
        $this->authAs((int) self::$ownerId);
        $this->call('sendReview', new ReviewController(), (int) self::$formId);
        $this->authAsReviewer();
        $this->call('approve', new ReviewController(), (int) self::$formId);

        assertSame(2, self::logCount('form.review_request'), 'los dos envíos quedan registrados');
        assertSame(1, self::logCount('form.review_approve'), 'la aprobación queda registrada');
        assertSame(1, self::logCount('form.review_reject'), 'la devolución queda registrada');
    }

    public function testAutoAprobacionSeMarcaComoPropiaEnElLog(): void
    {
        // En una instalación de un solo usuario la revisión la aprueba el mismo
        // que escribió. Se permite (si no, ese usuario no podría publicar
        // nunca) pero el log lo dice, porque es la pregunta que se hace al
        // auditar un formulario publicado.
        $db = Database::getInstance(Config::db());
        $db->execute('UPDATE users SET role_id = :r WHERE id = :u', [
            'r' => (int) self::$reviewRoleId,
            'u' => (int) self::$ownerId,
        ]);

        $this->activarRevision(false);
        $this->authAs((int) self::$ownerId);
        $this->call('sendReview', new ReviewController(), (int) self::$formId);
        $this->call('approve', new ReviewController(), (int) self::$formId);

        assertSame('published', $this->row()['status'], 'el propio puede aprobar el suyo');
        assertTrue(
            str_contains(self::lastLogDetail('form.review_approve'), 'propio'),
            'pero el log lo marca como aprobación propia: ' . self::lastLogDetail('form.review_approve')
        );
    }

    // ---------- Helpers ----------

    /**
     * Enciende la política de revisión de la INSTANCIA (seam de `AppSettings`;
     * no escribe ninguna fila). El dueño sigue sin el permiso de exención, así
     * que sus formularios quedan sujetos a revisión.
     */
    private function activarRevision(bool $notify): void
    {
        AppSettings::setForTesting([
            AppSettings::REVIEW_REQUIRED    => true,
            AppSettings::REVIEW_NOTIFY_EMAIL => $notify,
        ]);
    }

    private function authAsReviewer(): void
    {
        self::authAs((int) self::$reviewerId);
    }

    private static function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    private function row(): array
    {
        return (array) Database::getInstance(Config::db())->fetch(
            'SELECT status, review_requested_at, reviewed_at, reviewed_by FROM forms WHERE id = :id',
            ['id' => (int) self::$formId]
        );
    }

    private function statusOf(int $formId): string
    {
        $row = Database::getInstance(Config::db())->fetch('SELECT status FROM forms WHERE id = :id', ['id' => $formId]);
        return (string) ($row['status'] ?? '');
    }

    private function renderFill(): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/f/' . (string) self::$slug;
        ob_start();
        try {
            (new FillController())->show((string) self::$slug);
        } finally {
            $html = (string) ob_get_clean();
        }
        assertTrue($html !== '', 'el fill debe imprimir algo');
        return $html;
    }

    private function postFill(): ?array
    {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/f/' . (string) self::$slug;
        Response::setRecordHalt(true);
        ob_start();
        try {
            (new FillController())->submit((string) self::$slug);
        } finally {
            ob_get_clean();
        }
        $halt = Response::lastHalt();
        Response::setRecordHalt(false);
        return $halt;
    }

    /**
     * Reproduce lo que hace el navegador al guardar la estructura: POST con
     * JSON y el token en la cabecera `X-CSRF-Token` (middleware `csrf-header`).
     */
    private function saveBuilder(string $status): array
    {
        $token = 'token-builder-test';
        Session::set('_csrf_token', $token);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_POST = [];
        Request::setRequestBodyForTests(json_encode([
            'title'       => 'Formulario con revisión',
            'description' => 'Editado por el test',
            'status'      => $status,
            'fields'      => [],
        ]));
        Request::resetJsonState();

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new BuilderController())->saveStructure((int) self::$formId);
        } finally {
            $out = (string) ob_get_clean();
        }
        Response::setRecordHalt(false);

        $json = json_decode($out, true);
        return is_array($json) ? $json : ['ok' => false, 'raw' => substr($out, 0, 200)];
    }

    /**
     * Reproduce el POST de la ficha del formulario (`/forms/{id}/update`).
     */
    private function postUpdate(string $title): array
    {
        Session::set('_csrf_token', Csrf::token());
        $_POST = [
            '_token'       => Session::get('_csrf_token'),
            'title'        => $title,
            'description'  => 'Editado por el test',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Request::setRequestBodyForTests(null);

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new FormController())->update((int) self::$formId);
        } finally {
            ob_get_clean();
        }
        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        return [
            'halt'  => $halt,
            'exito' => Session::getFlash('success'),
            'error' => Session::getFlash('error'),
        ];
    }

    /**
     * Invoca una acción de controlador como un POST de navegador (con CSRF) y
     * devuelve el `halt` registrado más los flashes.
     */
    private function call(string $action, object $controller, int $formId): array
    {
        Session::set('_csrf_token', Csrf::token());
        $_POST = ['_token' => Session::get('_csrf_token')];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Request::setRequestBodyForTests(null);

        Response::setRecordHalt(true);
        ob_start();
        try {
            $controller->{$action}($formId);
        } finally {
            ob_get_clean();
        }
        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        return [
            'halt'  => $halt,
            'exito' => Session::getFlash('success'),
            'error' => Session::getFlash('error'),
        ];
    }

    /**
     * Correos dirigidos a usuarios `@test.local`: los únicos que este test
     * controla. Los reales (el admin del seed también puede revisar) se ignoran.
     */
    private function testMails(): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn (array $e): bool => str_contains((string) ($e['to'][0] ?? ''), '@test.local')
        ));
    }

    private static function pendingIds(): array
    {
        return array_column(Form::pendingReviews(), 'id');
    }

    private static function submissionCount(): int
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM submissions WHERE form_id = :id',
            ['id' => (int) self::$formId]
        );
        return (int) ($row['c'] ?? 0);
    }

    private static function logCount(string $event): int
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM security_log WHERE event = :e AND detail LIKE :d',
            ['e' => $event, 'd' => '%formulario #' . (int) self::$formId . '%']
        );
        return (int) ($row['c'] ?? 0);
    }

    private static function lastLogDetail(string $event): string
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT detail FROM security_log WHERE event = :e AND detail LIKE :d ORDER BY id DESC LIMIT 1',
            ['e' => $event, 'd' => '%formulario #' . (int) self::$formId . '%']
        );
        return (string) ($row['detail'] ?? '');
    }

    private static function ensureCleanupHook(): void
    {
        if (!self::$cleanupHookRegistered) {
            self::$cleanupHookRegistered = true;
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                    // Sin conexión no hay residuales que borrar.
                }
            });
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());

        // EL ORDEN IMPORTA: `users.role_id` es ON DELETE **RESTRICT**, así que
        // borrar los roles antes que los usuarios revienta con 1451. Y al
        // reventar a medias, el residuo se lleva por delante los tests de
        // otros archivos. Grupos primero (cascada de rgr/rgu), luego usuarios
        // (cascada de forms/fields/submissions/password_resets) y roles al final.
        $db->execute("DELETE FROM role_groups WHERE name LIKE 'Grupo Revisión-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'review-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'reviewer-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'ajeno-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'curioso-%'");
        $db->execute("DELETE FROM roles WHERE name LIKE 'revisor-%'");

        self::$ownerId = null;
        self::$ownerEmail = null;
        self::$reviewerId = null;
        self::$reviewerEmail = null;
        self::$reviewRoleId = null;
        self::$formId = null;
        self::$slug = null;
    }
}
