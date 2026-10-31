# Security Policy

## Supported versions

Security fixes are released for the latest minor and patch release of the current major version of
`maxcxam/larsadmin-medialibrary`.

| Version | Supported |
| --- | --- |
| 1.x (latest minor/patch) | Yes |
| 1.x (older minor/patch releases) | No, update to the latest 1.x |

When a new major version is released, older majors stop receiving security fixes. The add-on is versioned
independently of the core `maxcxam/larsadmin`; keep both on their latest supported releases.

## Reporting a vulnerability

Please **do not open a public issue, discussion or pull request** for a security problem.

Report it privately through GitHub private vulnerability reporting: open
<https://github.com/maxcxam/larsadmin-medialibrary/security/advisories/new> (or the repository's **Security**
tab, then **Report a vulnerability**) and fill in the form. Only the maintainer can see the report. If the problem
is in the core panel rather than in this add-on, report it to
<https://github.com/maxcxam/larsadmin/security/advisories/new> instead; when unsure, use either.

Please include:

- the affected versions of the add-on and of Larsadmin, `spatie/laravel-medialibrary`, PHP and Laravel;
- a description of the problem and its impact (what an attacker can do, and with what access);
- steps to reproduce or a minimal proof of concept, such as a model, an Entity, a policy, a request or a file;
- any suggested fix or mitigation, if you have one.

Areas of particular interest: the media routes (serving, reorder, delete) and their authorization, URLs issued to
users without access, files served inline that should not be, files stored in the web root that were meant to be
private, image metadata that survives `->stripMetadata()` or a file it stores unstripped, and its working directory
`storage/app/larsadmin-medialibrary-strip`.

## Out of scope

Behaviour that is documented as a limitation in the guide (`docs/guide/en/medialibrary.md`), for example:

- media on a **public disk** are readable by anyone who has the URL (`->private()` changes links only and is
  refused on a public upload disk; sensitive files belong on a private disk);
- without `->stripMetadata()` originals keep their EXIF/GPS metadata, and PDF / Office metadata is never touched;
- files of a deleted record are deleted by `spatie/laravel-medialibrary` before the panel's transaction commits;
- a file left behind when the PHP process dies in the middle of an upload (a fatal error, a killed worker).

## What happens next

- You can expect a first reply within about **7 days**.
- The report is confirmed or declined, and you are kept informed while a fix is prepared.
- Fixes are released as **patch versions** of the supported release line. Each fix is announced in a
  **GitHub Security Advisory** and in a `Security` entry in [CHANGELOG.md](CHANGELOG.md).
- With your permission, you are credited in the advisory and the changelog. Tell us if you prefer
  to stay anonymous.

## Українською

Не відкривайте публічних issue щодо вразливостей. Повідомляйте про них приватно через GitHub
private vulnerability reporting: <https://github.com/maxcxam/larsadmin-medialibrary/security/advisories/new>
(вкладка **Security**, кнопка **Report a vulnerability**). Вкажіть версії доповнення, Larsadmin,
`spatie/laravel-medialibrary`, PHP і Laravel, опис проблеми, кроки відтворення. Першу відповідь ви отримаєте
орієнтовно протягом 7 днів. Виправлення виходять патч-версіями гілки 1.x з GitHub Security Advisory і записом
`Security` у [CHANGELOG.md](CHANGELOG.md); за вашою згодою вас буде згадано як автора повідомлення.
