# Posicionamiento de VanillaForms frente a los constructores de encuestas

**Fecha:** 2026-09-28
**Contexto:** comparativa frente a los constructores populares (Google Forms, Typeform,
SurveyMonkey, Jotform, Qualtrics, Tally) y a la arena open-source self-hosted (LimeSurvey,
Formbricks). Los datos de niveles de planes son aproximados y conviene verificarlos antes de
usarlos en material comercial.

## 1. La arena

VanillaForms **no compite en la arena de los SaaS** (Typeform, Google Forms, SurveyMonkey,
Jotform, Qualtrics, Tally) ni en el espacio open-source maduro (LimeSurvey). Su punto de
entrada es **"avanzado + soberano + seguro"**: encuestas con mecánica de nivel medio-alto,
pero con los datos del formulario y de las respuestas en un MariaDB propio, sin coste por
respondiente y con postura de seguridad de nivel auditoría.

## 2. Matriz de paridad (dónde empatas con los grandes)

| Capacidad | VanillaForms | Contra quién |
|---|---|---|
| Lógica de salto (pregunta + página), regex, randomización | ✅ validado server + client | SurveyMonkey / Typeform gama media (Google Forms ni de cerca) |
| Quiz con scoring server-side | ✅ | casi nadie lo hace así |
| Multi-paso con reanudar borrador | ✅ | Typeform / Tally |
| Matriz/Likert, NPS, estrellas, ranking, escala | ✅ | núcleo de SurveyMonkey |
| Apertura/cierre por calendario, cupo, estados cerrado/lleno | ✅ | par o mejor (Google Forms no tiene cupo) |
| Analítica + CSV/JSON + tabla paginada server-side | ✅ | par con Google Forms básico; por debajo de Qualtrics |
| Anti-abuso (honeypot + rate limit + MIME real en uploads) | ✅ | mejora a Google Forms; honeypot de Typeform solo en pagos |
| Registro de auditoría (`security_log`) y RBAC por grupos | ✅ | sin equivalencia publicada en los SaaS |
| i18n del flujo público (es/en) | ✅ | parcial: Typeform/Tally multi-idioma |

## 3. Diferenciales (dónde ganas)

1. **Cero coste por respondiente + soberanía de datos.** Los SaaS limitan las respuestas en
   sus planes free (aprox. Typeform ~10/mes, Jotform ~100/mes, Tally ~100/mes, SurveyMonkey
   ~100/encuesta) y cobran por volumen. En VanillaForms las respuestas son ilimitadas: el
   límite es tu servidor.
2. **Postura de seguridad publicada y verificada.** RBAC con roles+grupos, `security_log`
   de auditoría, CSRF, rate limits (login/fill/dashboard), SSRF-guard en webhooks, allowlist
   de redirects, honeypot, CSP, uploads con detección de MIME real + extensiones denegadas +
   descarga autenticada con sandbox. Verificado por **465 tests automatizados**. Ningún SaaS
   cierra este argumento y, para quien no puede mandar datos de encuestados a terceros, es el
   argumento de compra.
3. **Superficie de ataque mínima.** Cero Composer/npm en runtime: nada que explotar vía
   dependencias de terceros. Corres en PHP + MariaDB estándar.
4. **Control técnico.** Export/import de estructura round-trip, vista imprimible, QR, embed,
   webhook con firewall SSRF, SMTP propio.

## 4. Brechas (dónde pierdes)

| Brecha | Detalle |
|---|---|
| UX/atractivo del flujo | El tipo "una pregunta por vista" conversacional (Typeform), la riqueza de widgets (Jotform) y el polish móvil siguen siendo superiores |
| Integraciones/no-code | Zapier/Sheets/Slack/CRM vía OAuth: nosotros solo webhook crudo + correo |
| IA | Los grandes ya generan formularios/temas/análisis con IA |
| Investigación avanzada | Qualtrics (conjoint/MaxDiff/text analytics), SurveyMonkey (panel, benchmarks) |
| Comercio/pagos | Jotform (campos de producto + Stripe) |
| Multilingüe del flujo | Solo es/en; Typeform/Tally multi-idioma |
| Colaboración | RBAC de acceso sí; edición concurrente no |
| Anti-bot | Honeypot + rate limit sin reCAPTCHA/Turnstile |

## 5. Frente al nicho self-hosted

- **LimeSurvey** es el "Excel" de las encuestas: gana en madurez, tipos de pregunta, cuotas,
  plugins y comunidad; pero es admin-heavy y de UI añeja, y su postura de seguridad no se
  verifica con una suite comparable. VanillaForms gana en builder moderno, simplicidad y
  465 tests.
- **Formbricks** es moderno pero está orientado a encuestas **in-app** (SDK, targeting).
  VanillaForms es el formulario público clásico con panel completo.

## 6. Frase de posicionamiento

> Un constructor tipo SurveyMonkey/Tally **self-hosted**, sin coste por respondiente, con
> postura de seguridad auditada (RBAC + log + SSRF + rate-limit + CSRF) y cero dependencias
> runtime, hecho para organizaciones que quieren encuestas avanzadas sin delegar sus datos ni
> pagar por volumen, con un acabado técnico profesional (465 tests) que LimeSurvey no tiene y
> el SaaS no te puede prometer.

## 7. Público objetivo

Universidades / ONG / CSR con volumen alto de encuestados, feedback de producto, y equipos
con requisitos de privacidad que no aceptan "tus respuestas viven en servidores de terceros".

## 8. Cómo se cierran las brechas

El plan operativo que ataca las brechas anteriores está en
[docs/uiux-roadmap.md](uiux-roadmap.md), con una matriz de cobertura explícita: qué cierra
cada punto y qué queda fuera de alcance a propósito.