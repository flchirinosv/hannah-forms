#!/usr/bin/env python3
"""Bloque AJ: instalador web guiado, sobre una copia y bases descartables."""

import json, os, re, shutil, socket, subprocess, sys, time, urllib.request
from pathlib import Path
sys.path.insert(0, "tests/browser")
import vf

R = vf.R()
ROOT = vf.ROOT
PASS_DB = vf.dbpass()
ADMIN = ("Admin AJ", "aj-admin@test.local", "AjPassword123!")
BASE2 = ("Admin AJ 2", "aj-admin2@test.local", "AjPassword123!")


def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p


def root_sql(sql):
    p = subprocess.run(["mysql", "-uroot", "-N", "-B", "-e", sql], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr.strip())
    return [x.split("\t") for x in p.stdout.splitlines()]


def db_exists(name):
    return bool(root_sql(f"SHOW DATABASES LIKE '{name}'"))


def grant(name):
    root_sql(f"CREATE DATABASE IF NOT EXISTS `{name}`; GRANT ALL PRIVILEGES ON `{name}`.* TO 'mariadb'@'localhost'; FLUSH PRIVILEGES;")


def db1(sql, db):
    p = subprocess.run(["mysql", "-h127.0.0.1", "-umariadb", f"-p{PASS_DB}", db, "-N", "-B", "-e", sql], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr.strip())
    rows = [x.split("\t") for x in p.stdout.splitlines()]
    return rows[0] if rows else None


def csrf(html):
    m = re.search(r'name="_token"\s+value="([^"]+)"', html)
    return m.group(1) if m else ""


def txt(html):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html)).strip()


slug = str(os.getpid())
DB1 = "vf_aj_" + slug
DB2 = "vf_aj2_" + slug
DB_BAD = "vf_aj_bad_" + slug
PORT = free_port()
TMP = Path("/tmp") / ("vf_aj_" + slug)
URL = f"http://127.0.0.1:{PORT}"
proc = None
old_base = vf.BASE

try:
    # Copia de trabajo aislada, servida por un segundo PHP como `nobody`.
    shutil.copytree(ROOT, TMP, ignore=shutil.ignore_patterns(".git", ".opencode", "tests", "freddy", "freddy.zip", "pub.jar", "installed.lock"))
    for name in [DB1, DB2]:
        grant(name)
    envtxt = "\n".join([
        "APP_NAME=VanillaForms",
        f"APP_URL={URL}",
        "DB_HOST=127.0.0.1",
        "DB_PORT=3306",
        f"DB_NAME={DB1}",
        "DB_USER=mariadb",
        f'DB_PASSWORD="{PASS_DB}"',
        "DB_CHARSET=utf8mb4",
        "APP_TIMEZONE=America/Caracas",
        "APP_LOCALE=es",
    ]) + "\n"
    (TMP / ".env").write_text(envtxt)
    (TMP / ".env").chmod(0o600)
    sess = TMP / "storage" / "tmp" / "aj_sessions"
    sess.mkdir(parents=True, exist_ok=True)
    subprocess.run(["chown", "-R", "nobody:nogroup", str(TMP)], check=True)
    proc = subprocess.Popen(
        ["runuser", "-u", "nobody", "--", "php", "-d", f"session.save_path={sess}", "-S", f"127.0.0.1:{PORT}", "-t", "public"],
        cwd=str(TMP), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    for _ in range(60):
        try:
            urllib.request.urlopen(URL + "/install.php", timeout=2)
            break
        except Exception:
            time.sleep(.25)
    else:
        raise RuntimeError("el servidor de la copia no arrancó")

    vf.BASE = URL
    h = vf.Http()          # sesión sin login, solo para el instalador
    token = lambda html: csrf(html)

    # AJ3 — requisitos verdes.
    s3, h3, _ = h.get("/install.php")
    R.check("AJ3", s3 == 200 and "Todo listo." in h3 and "Continuar" in h3
            and "Corrige los requisitos" not in h3, f"{s3}")

    # AJ4 — uploads sin escritura => requisito rojo y sin Continuar.
    up = TMP / "storage" / "uploads"
    up.chmod(0o555)
    try:
        s4, h4, _ = h.get("/install.php")
    finally:
        up.chmod(0o775)
    R.check("AJ4", s4 == 200 and "storage/uploads/" in h4 and "no tiene permiso de escritura" in h4
            and "Corrige los requisitos" in h4 and "de la base de datos" not in h4,
            f"{s4}")

    # AJ6 — nombre inválido, sin crear nada.
    s6, h6, _ = h.get("/install.php?paso=base")
    t6 = token(h6)
    s6, b6, _ = h.req("POST", "/install.php?paso=base", {
        "_token": t6, "db_host": "127.0.0.1", "db_port": "3306", "db_name": DB_BAD + ";x",
        "db_user": "mariadb", "db_password": PASS_DB,
    })
    R.check("AJ6", "solo admite letras, números y guion bajo" in b6 and not db_exists(DB_BAD + ";x"),
            f"{s6}")

    # AJ7 — contraseña incorrecta: error real de MySQL, sin tablas.
    s7, h7, _ = h.get("/install.php?paso=base")
    s7, b7, _ = h.req("POST", "/install.php?paso=base", {
        "_token": token(h7), "db_host": "127.0.0.1", "db_port": "3306", "db_name": DB1,
        "db_user": "mariadb", "db_password": "definitivamente-mal",
    })
    R.check("AJ7", "Access denied for user" in b7 and "No se pudo conectar a la base de datos" in b7,
            f"{s7}")

    # AJ5 — conexión válida: esquema y paso Administrador.
    s5, h5, _ = h.get("/install.php?paso=base")
    s5, b5, url5 = h.req("POST", "/install.php?paso=base", {
        "_token": token(h5), "db_host": "127.0.0.1", "db_port": "3306", "db_name": DB1,
        "db_user": "mariadb", "db_password": PASS_DB,
    })
    R.check("AJ5", s5 == 200 and "Cuenta de administrador" in b5 and "paso=admin" in url5
            and db1("SHOW TABLES LIKE 'users'", DB1) is not None
            and db1("SELECT COUNT(*) FROM roles WHERE name='admin'", DB1)[0] == "1",
            f"{s5} {url5}")

    # AJ8 — email inválido.
    s8, h8, _ = h.get("/install.php?paso=admin")
    s8, b8, _ = h.req("POST", "/install.php?paso=admin", {
        "_token": token(h8), "admin_name": ADMIN[0], "admin_email": "no-es-email",
        "admin_password": ADMIN[2], "admin_password_confirm": ADMIN[2], "app_url": URL,
    })
    R.check("AJ8", "Email no válido." in b8 and db1("SELECT COUNT(*) FROM users", DB1)[0] == "0",
            f"{s8}")

    # AJ9 — contraseña corta y repetición distinta.
    s9a, h9a, _ = h.get("/install.php?paso=admin")
    s9a, b9a, _ = h.req("POST", "/install.php?paso=admin", {
        "_token": token(h9a), "admin_name": ADMIN[0], "admin_email": ADMIN[1],
        "admin_password": "corta", "admin_password_confirm": "corta", "app_url": URL,
    })
    s9b, h9b, _ = h.get("/install.php?paso=admin")
    s9b, b9b, _ = h.req("POST", "/install.php?paso=admin", {
        "_token": token(h9b), "admin_name": ADMIN[0], "admin_email": ADMIN[1],
        "admin_password": ADMIN[2], "admin_password_confirm": ADMIN[2] + "x", "app_url": URL,
    })
    R.check("AJ9", "al menos 10 caracteres" in b9a and "no coinciden" in b9b
            and db1("SELECT COUNT(*) FROM users", DB1)[0] == "0", f"{s9a}/{s9b}")

    # AJ10 — terminar instalación.
    s10, h10, _ = h.get("/install.php?paso=admin")
    s10, b10, url10 = h.req("POST", "/install.php?paso=admin", {
        "_token": token(h10), "admin_name": ADMIN[0], "admin_email": ADMIN[1],
        "admin_password": ADMIN[2], "admin_password_confirm": ADMIN[2], "app_url": URL,
    })
    R.check("AJ10", s10 == 200 and "Instalación completada" in b10 and "Ir a iniciar sesión" in b10
            and (TMP / "storage" / "installed.lock").is_file() and "paso=listo" in url10,
            f"{s10} {url10}")

    # AJ11 — login con el admin nuevo.
    h11 = vf.Http(ADMIN[1], ADMIN[2])
    s11, b11, u11 = h11.get("/dashboard")
    rid = db1(f"SELECT id FROM roles WHERE name='admin'", DB1)[0]
    uid = db1(f"SELECT role_id FROM users WHERE email='{ADMIN[1]}'", DB1)[0]
    R.check("AJ11", s11 == 200 and "dashboard" in u11 and uid == rid,
            f"{s11} {u11} role={uid}/{rid}")

    # AJ12 — roles y usuario.
    R.check("AJ12", db1("SELECT COUNT(*) FROM roles WHERE name='admin'", DB1)[0] == "1"
            and db1("SHOW TABLES LIKE 'app_settings'", DB1) is not None
            and db1(f"SELECT COUNT(*) FROM users WHERE email='{ADMIN[1]}' AND role_id={rid}", DB1)[0] == "1",
            "admin y app_settings")

    # AJ13 — .env escrito, clave nueva y 0600.
    envpath = TMP / ".env"
    env = envpath.read_text()
    mode = envpath.stat().st_mode & 0o777
    mkey = re.search(r"^APP_KEY=(.+)$", env, re.M)
    R.check("AJ13", f"DB_NAME={DB1}" in env and f"DB_USER=mariadb" in env
            and f"APP_URL={URL}" in env and mkey is not None and len(mkey.group(1).strip()) >= 32
            and mode == 0o600, f"mode={oct(mode)} key={len(mkey.group(1)) if mkey else 0}")

    # AJ14 — lock con fecha/email, sin contraseña.
    lock = json.loads((TMP / "storage" / "installed.lock").read_text())
    R.check("AJ14", "installed_at" in lock and lock.get("admin_email") == ADMIN[1]
            and ADMIN[2] not in (TMP / "storage" / "installed.lock").read_text(),
            str(lock))

    # AJ1 / AJ15 — instalador cerrado (403).
    s1, b1, _ = h.get("/install.php")
    R.check("AJ1", s1 == 403 and "Ya está instalado" in b1, f"{s1}")

    # AJ2 — con force vuelve el asistente.
    s2, b2, _ = h.get("/install.php?force")
    R.check("AJ2", s2 == 200 and "Requisitos del servidor" in b2, f"{s2}")

    # AJ16 — reinstall con APP_KEY existente: no se sobrescribe.
    key_before = mkey.group(1).strip()
    g16, h16, _ = h.get("/install.php?force&paso=base")
    p16, b16, u16 = h.req("POST", "/install.php?force&paso=base", {
        "_token": token(h16), "db_host": "127.0.0.1", "db_port": "3306", "db_name": DB2,
        "db_user": "mariadb", "db_password": PASS_DB,
    })
    g16b, h16b, _ = h.get("/install.php?force&paso=admin")
    p16b, b16b, u16b = h.req("POST", "/install.php?force&paso=admin", {
        "_token": token(h16b), "admin_name": BASE2[0], "admin_email": BASE2[1],
        "admin_password": BASE2[2], "admin_password_confirm": BASE2[2], "app_url": URL,
    })
    key_after = re.search(r"^APP_KEY=(.+)$", envpath.read_text(), re.M).group(1).strip()
    R.check("AJ16", p16 == 200 and p16b == 200 and key_after == key_before
            and db1(f"SELECT COUNT(*) FROM users WHERE email='{BASE2[1]}'", DB2)[0] == "1",
            f"key_igual={key_after == key_before}")

    # AJ17 — POST sin token: 400 y sin efectos.
    s17, b17, _ = h.req("POST", "/install.php?force&paso=base", {
        "db_host": "127.0.0.1", "db_port": "3306", "db_name": "vf_aj_nocsrf_" + slug,
        "db_user": "mariadb", "db_password": PASS_DB,
    })
    R.check("AJ17", s17 == 400 and "Token de seguridad inválido" in b17
            and not db_exists("vf_aj_nocsrf_" + slug), f"{s17}")

    # AJ15 se repite al final, tras la reinstalación.
    s15, b15, _ = h.get("/install.php")
    R.check("AJ15", s15 == 403 and "Ya está instalado" in b15, f"{s15}")
finally:
    vf.BASE = old_base
    if proc is not None:
        proc.terminate()
        try: proc.wait(timeout=10)
        except Exception: proc.kill()
    for name in [DB1, DB2, "vf_aj_nocsrf_" + slug]:
        try: root_sql(f"DROP DATABASE IF EXISTS `{name}`")
        except Exception: pass
    shutil.rmtree(TMP, ignore_errors=True)

print(R.report())
