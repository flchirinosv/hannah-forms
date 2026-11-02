<?php
declare(strict_types=1);
require __DIR__ . '/../../config/bootstrap.php';

use App\Core\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Models\User;

$db = Database::getInstance(Config::db());
$out = ['forms' => [], 'users' => [], 'participants' => [], 'tokens' => []];

foreach ($db->fetchAll("SELECT id FROM forms WHERE title LIKE '[BT]%'") as $r) {
    Form::delete((int) $r['id']);
}
$db->execute("DELETE FROM users WHERE email LIKE '%@bt.local'");

function mkForm(string $title, array $settings = [], string $status = 'published', string $description = ''): int {
    $id = Form::create(1, $title, $description);
    Form::saveSettings($id, FormSettingsMerge($settings));
    Form::setStatus($id, $status);
    return $id;
}
function FormSettingsMerge(array $over): array {
    return array_replace_recursive(\App\Services\FormSettings::defaults(), $over);
}
function f(int $form, string $type, string $label, array $opt = []): int {
    return Field::create(
        $form,
        $type,
        $label,
        (string) ($opt['placeholder'] ?? ''),
        (string) ($opt['help'] ?? ''),
        (bool) ($opt['required'] ?? false),
        $opt['options'] ?? [],
        $opt['validations'] ?? [],
        (int) ($opt['position'] ?? 0),
        $opt['parent'] ?? null,
        $opt['logic'] ?? null,
        (bool) ($opt['pii'] ?? false),
        (bool) ($opt['show_header'] ?? true)
    );
}
function publish(int $id): void { Form::setStatus($id, 'published'); }

// 1. Público simple
$simple = mkForm('[BT] Público simple', ['honeypot' => ['enabled' => false], 'embed' => ['enabled' => true]]);
f($simple, 'text', 'Escribe tu nombre', ['required' => true, 'validations' => ['min_length' => 2, 'max_length' => 80], 'position' => 1]);
f($simple, 'email', 'Tu correo', ['position' => 2]);
f($simple, 'checkbox', 'Acepto los términos', ['options' => [['value' => 'Sí']], 'required' => true, 'position' => 3]);
$out['forms']['simple'] = $simple;

// 2. Todos los tipos
$types = mkForm('[BT] Todos los tipos', ['honeypot' => ['enabled' => false]]);
f($types, 'text', 'Texto', ['required' => true, 'position' => 1]);
f($types, 'textarea', 'Párrafo', ['position' => 2]);
f($types, 'email', 'Email', ['position' => 3]);
f($types, 'number', 'Número', ['validations' => ['min' => 1, 'max' => 10], 'position' => 4]);
f($types, 'select', 'Desplegable', ['options' => [['value' => 'A'], ['value' => 'B'], ['value' => 'C']], 'position' => 5]);
f($types, 'radio', 'Opción única', ['options' => [['value' => 'Uno'], ['value' => 'Dos']], 'position' => 6]);
f($types, 'checkbox', 'Casillas', ['options' => [['value' => 'Uno'], ['value' => 'Dos'], ['value' => 'Tres']], 'position' => 7]);
f($types, 'date', 'Fecha', ['position' => 8]);
f($types, 'scale', 'Escala', ['validations' => ['max' => 5], 'position' => 9]);
f($types, 'url', 'URL', ['position' => 10]);
f($types, 'tel', 'Teléfono', ['position' => 11]);
f($types, 'time', 'Hora', ['position' => 12]);
f($types, 'datetime-local', 'Fecha y hora', ['position' => 13]);
f($types, 'range', 'Rango', ['validations' => ['min' => 0, 'max' => 100], 'position' => 14]);
f($types, 'file', 'Archivo', ['validations' => ['max_size_mb' => 1, 'max_files' => 1, 'accept' => ['pdf', 'txt']], 'position' => 15]);
f($types, 'matrix', 'Matriz', ['options' => [
    ['value' => 'Fila 1', 'group' => 'rows'], ['value' => 'Fila 2', 'group' => 'rows'],
    ['value' => 'Malo', 'group' => 'cols'], ['value' => 'Bueno', 'group' => 'cols'],
], 'position' => 16]);
f($types, 'nps', 'NPS', ['validations' => ['min' => 0, 'max' => 10], 'position' => 17]);
f($types, 'star', 'Estrellas', ['validations' => ['max' => 5], 'position' => 18]);
f($types, 'ranking', 'Ranking', ['options' => [['value' => 'Uno'], ['value' => 'Dos'], ['value' => 'Tres']], 'position' => 19]);
$out['forms']['types'] = $types;

// 3. Multi-paso (copia del existente)
$multi = Form::duplicate(218673);
$db->execute('UPDATE forms SET title = :t WHERE id = :id', ['t' => '[BT] Multi paso', 'id' => $multi]);
publish($multi);
$out['forms']['multi'] = $multi;

// 4. Roster con atributos
$roster = mkForm('[BT] Roster', ['access' => ['mode' => 'roster', 'identifier' => 'cedula'], 'honeypot' => ['enabled' => false]]);
f($roster, 'text', 'Comentario', ['position' => 1]);
$db->execute("INSERT INTO form_participant_fields (form_id,key_name,label,position) VALUES (:f,'sede','Sede',0),(:f2,'turno','Turno',1)", ['f' => $roster, 'f2' => $roster]);
$p1 = FormParticipant::create($roster, 'BT-001', 'Ana Roster', 'ana@bt.local', '', ['sede' => 'Norte', 'turno' => 'Mañana']);
$p2 = FormParticipant::create($roster, 'BT-002', 'Luis Roster', 'luis@bt.local', '', ['sede' => 'Sur', 'turno' => 'Tarde']);
$p3 = FormParticipant::create($roster, 'BT-003', 'Eva Roster', 'eva@bt.local', '', ['sede' => 'Norte', 'turno' => 'Tarde']);
$out['participants']['roster'] = [$p1, $p2, $p3];
$out['forms']['roster'] = $roster;

// 5. Allowlist
$allow = mkForm('[BT] Allowlist', ['access' => ['mode' => 'allowlist', 'identifier' => 'correo']]);
f($allow, 'text', 'Comentario allowlist', ['position' => 1]);
$db->execute("INSERT INTO form_access_emails (form_id,email) VALUES (:f,'permitido@bt.local')", ['f' => $allow]);
$out['forms']['allow'] = $allow;

// 6. Acceso email
$email = mkForm('[BT] Email', ['access' => ['mode' => 'email', 'identifier' => 'correo']]);
f($email, 'text', 'Comentario email', ['position' => 1]);
$out['forms']['email'] = $email;

// 7. Cuota (max 2, ya llena)
$quota = mkForm('[BT] Cuota', ['quota' => ['enabled' => true, 'max' => 2]]);
$q1 = f($quota, 'text', 'Comentario cuota', ['position' => 1]);
for ($i = 0; $i < 2; $i++) { $s = Submission::create($quota); $db->execute('INSERT INTO answers (submission_id,field_id,value) VALUES (:s,:f,:v)', ['s' => $s, 'f' => $q1, 'v' => "r$i"]); }
$out['forms']['quota'] = $quota;

// 8. Horario cerrado
$closedDate = mkForm('[BT] Cerrado por fecha', ['schedule' => ['open_at' => '2020-01-01T00:00', 'close_at' => '2020-01-02T00:00']]);
f($closedDate, 'text', 'Comentario cerrado', ['position' => 1]);
$out['forms']['closed_date'] = $closedDate;

// 9. Honeypot
$hp = mkForm('[BT] Honeypot', ['honeypot' => ['enabled' => true]]);
$hpf = f($hp, 'text', 'Comentario honeypot', ['position' => 1]);
$out['forms']['honeypot'] = $hp;

// 10. Quiz
$quiz = mkForm('[BT] Quiz', ['quiz' => ['enabled' => true]]);
f($quiz, 'radio', '¿Cuánto es 2+2?', ['options' => [['value' => '3'], ['value' => '4']], 'validations' => ['correct' => '4'], 'position' => 1]);
$out['forms']['quiz'] = $quiz;

// 11. Estados
$draft = mkForm('[BT] Borrador', [], 'draft'); f($draft, 'text', 'Campo borrador', ['position' => 1]);
$inrev = mkForm('[BT] En revisión', [], 'in_review'); f($inrev, 'text', 'Campo revisión', ['position' => 1]);
$appr = mkForm('[BT] Aprobado', [], 'approved'); f($appr, 'text', 'Campo aprobado', ['position' => 1]);
$closed = mkForm('[BT] Cerrado', [], 'closed'); f($closed, 'text', 'Campo cerrado', ['position' => 1]);
$out['forms']['draft'] = $draft; $out['forms']['in_review'] = $inrev; $out['forms']['approved'] = $appr; $out['forms']['closed'] = $closed;

// 12. PII + respuestas (para enmascarado/atribución)
$pii = mkForm('[BT] PII', ['honeypot' => ['enabled' => false]]);
$fname = f($pii, 'text', 'Nombre personal', ['pii' => true, 'position' => 1]);
$fmail = f($pii, 'email', 'Correo personal', ['pii' => true, 'position' => 2]);
$fnote = f($pii, 'text', 'Comentario', ['position' => 3]);
for ($i = 0; $i < 3; $i++) {
    $s = Submission::create($pii, '10.0.0.'.($i + 1), 'Mozilla/5.0 BT Test');
    $db->execute('INSERT INTO answers (submission_id,field_id,value) VALUES (:s,:f,:v),(:s2,:f2,:v2),(:s3,:f3,:v3)', [
        's' => $s, 'f' => $fname, 'v' => 'Persona '.$i,
        's2' => $s, 'f2' => $fmail, 'v2' => "persona$i@bt.local",
        's3' => $s, 'f3' => $fnote, 'v3' => 'Nota '.$i,
    ]);
}
$out['forms']['pii'] = $pii;

// 13. Tema personalizado
$theme = mkForm('[BT] Tema', ['theme' => ['primary' => '#0d6efd', 'background' => '#e0f2fe', 'gradient_start' => '#2563eb', 'gradient_end' => '#7c3aed', 'text' => '#ffffff', 'font' => 'inter']]);
f($theme, 'text', 'Comentario tema', ['position' => 1]);
$out['forms']['theme'] = $theme;

// 14. Embed activo
$embed = mkForm('[BT] Embed', ['embed' => ['enabled' => true], 'honeypot' => ['enabled' => false]]);
f($embed, 'text', 'Comentario embed', ['position' => 1]);
$out['forms']['embed'] = $embed;

// 15. Formulario para revision (draft, sin revisión)
$rev = mkForm('[BT] Revisión', [], 'draft');
$revf = f($rev, 'text', 'Campo revisión', ['position' => 1]);
$out['forms']['review'] = $rev;

// 16. 30 formularios extra para paginación del panel
$extra = [];
for ($i = 1; $i <= 30; $i++) {
    $id = mkForm(sprintf('[BT] Lote %02d', $i), [], 'published');
    f($id, 'text', "Pregunta $i", ['position' => 1]);
    $extra[] = $id;
}
$out['forms']['extra'] = $extra;

// Usuarios de prueba
$roles = [];
foreach ($db->fetchAll('SELECT id,name FROM roles') as $r) { $roles[$r['name']] = (int) $r['id']; }
$out['users']['user2'] = User::create('[BT] Usuario Dos', 'user2@bt.local', 'password123', $roles['user']);
$out['users']['editor2'] = User::create('[BT] Editor Dos', 'editor2@bt.local', 'password123', $roles['editor']);
$out['users']['approver2'] = User::create('[BT] Aprobador Dos', 'approver2@bt.local', 'password123', $roles['approver']);
$out['users']['inactive'] = User::create('[BT] Inactivo', 'inactive@bt.local', 'password123', $roles['user']);
User::setActive($out['users']['inactive'], false);
$group = \App\Models\RoleGroup::create('[BT] Grupo', 'Grupo de prueba');
\App\Models\RoleGroup::setRoles($group, [$roles['editor']]);
\App\Models\RoleGroup::setUsers($group, [$out['users']['user2']]);
$out['groups'] = ['bt' => $group];

// Token F4 válido y revocado
$plain = bin2hex(random_bytes(16));
$hash = hash('sha256', $plain);
$db->execute("INSERT INTO form_access_tokens (form_id,email,token_hash,expires_at) VALUES (:f,'permitido@bt.local',:h,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))", ['f' => $allow, 'h' => $hash]);
$out['tokens']['allow_valid'] = $plain;
$plain2 = bin2hex(random_bytes(16));
$hash2 = hash('sha256', $plain2);
$db->execute("INSERT INTO form_access_tokens (form_id,email,token_hash,expires_at) VALUES (:f,'permitido@bt.local',:h,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))", ['f' => $allow, 'h' => $hash2]);
$db->execute("UPDATE form_access_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = :h", ['h' => $hash2]);
$out['tokens']['allow_revoked'] = $plain2;

// Código de roster
$row = $db->fetch('SELECT code FROM form_participants WHERE form_id = :f AND cedula = :c', ['f' => $roster, 'c' => 'BT-001']);
$out['participants']['roster_code'] = $row['code'] ?? null;

Cache::forgetLike('dash:');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
