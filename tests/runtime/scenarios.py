"""What the runtime checks prove in a running Dolibarr.

scripts/local_check.py starts one Dolibarr per supported version with MariaDB
and Mailpit, runs the base stage of fixtures.php, and then calls the scenarios
below in order. The module arrives as a user installs it: the package from
build_release.py, uploaded through Deploy an external module.
Each scenario raises CheckFailed with what went wrong and returns a one-line
result. They only use what a user and the cron use: the web pages, the
Dolibarr cron runner, the mail server and the database.
"""

from __future__ import annotations

import base64
import datetime
import hashlib
import html
import json
import re
import subprocess
import time
import zipfile
import zlib
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

from dolibarr_http import Browser, Mailpit, Page, token_of

MODULE_DIR = "/var/www/html/custom/mahnwesen"
TESTS_DIR = "/opt/mahnwesen-tests"
MODULE_TABLES = ("mahnwesen_case", "mahnwesen_history", "mahnwesen_rule", "mahnwesen_attempt",
                 "mahnwesen_attempt_file", "mahnwesen_fee", "mahnwesen_pause", "mahnwesen_run",
                 "mahnwesen_profile", "mahnwesen_profile_match", "mahnwesen_event")
LANGS = Path(__file__).resolve().parents[2] / "langs"
PHP_PROBLEM = re.compile(r"PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error):"
                         r"\s*(.+?) in (/var/www/html/custom/mahnwesen/\S+) on line \d+")
# An uncaught error raised in Dolibarr's code but called from module code: the
# log line carries the stack trace with a literal backslash-n between frames.
PHP_UNCAUGHT = re.compile(r"PHP (Fatal error|Recoverable fatal error):\s*(Uncaught .+?) in /\S+?:\d+\\nStack trace:"
                          r".*?#\d+ /var/www/html/custom/mahnwesen/(\S+?)\(\d+\)")


class CheckFailed(Exception):
    """A scenario found a problem. The message says what."""


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise CheckFailed(message)


@dataclass
class Stack:
    """One running Dolibarr with its database and mail server."""

    version: str
    image: str
    web: str
    db: str
    mail: str
    web_port: int
    mail_port: int
    admin_password: str
    sales_password: str
    other_password: str
    db_password: str
    cron_key: str
    run: Callable[..., subprocess.CompletedProcess]
    docker: str
    package: Path
    previous_package: Path | None = None
    upgrade_packages: list = field(default_factory=list)
    fixtures: dict = field(default_factory=dict)
    notes: dict = field(default_factory=dict)

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.web_port}"

    @property
    def module_version(self) -> str:
        return package_version(self.package)

    def mailpit(self) -> Mailpit:
        return Mailpit(f"http://127.0.0.1:{self.mail_port}")

    def browser(self, who: str = "admin") -> Browser:
        passwords = {"admin": self.admin_password, "rtsales": self.sales_password,
                     "rtother": self.other_password}
        browser = Browser(self.url)
        browser.login(who, passwords[who])
        return browser

    def sql(self, query: str) -> list[list[str]]:
        completed = self.run(self.docker, "exec", "-e", f"MYSQL_PWD={self.db_password}", self.db,
                             "mariadb", "-uroot", "-N", "-B", "dolibarr", "-e", query,
                             check=False, timeout=120)
        if completed.returncode != 0:
            raise CheckFailed(f"SQL failed: {query}\n{completed.stderr.strip()}")
        return [line.split("\t") for line in completed.stdout.splitlines()]

    def value(self, query: str) -> str | None:
        rows = self.sql(query)
        return rows[0][0] if rows and rows[0] else None

    def const(self, name: str) -> str | None:
        return self.value(f"SELECT value FROM llx_const WHERE name = '{name}' AND entity IN (0, 1) ORDER BY entity DESC LIMIT 1")

    def php_fixture(self, stage: str, *arguments: str) -> dict:
        """Run one stage of fixtures.php as the web server user and return what it printed."""
        completed = self.run(self.docker, "exec", "-u", "www-data",
                             "--env", f"RT_SALES_PASSWORD={self.sales_password}",
                             "--env", f"RT_OTHER_PASSWORD={self.other_password}",
                             "--env", f"RT_CRON_KEY={self.cron_key}", self.web, "php",
                             f"{TESTS_DIR}/fixtures.php", stage, *arguments, check=False, timeout=600)
        self.notes.setdefault("fixtures", []).append((stage, completed.stdout + completed.stderr))
        if completed.returncode != 0:
            raise CheckFailed(f"fixtures.php {stage} failed:\n{(completed.stdout + completed.stderr)[-1500:]}")
        return parse_fixtures(completed.stdout)

    def shell(self, command: str) -> subprocess.CompletedProcess:
        return self.run(self.docker, "exec", "-u", "www-data", self.web, "sh", "-c", command, check=False, timeout=120)

    def cron(self, expect_ok: bool = True) -> str:
        """Run the Mahnwesen job through Dolibarr's own cron runner, as the daily cron would.

        --force stands in for the next day: the job is daily, and the check
        runs it several times within a minute. A run with failures or
        warnings ends with a non-zero result; pass expect_ok=False for those
        and look at the run it recorded.
        """
        job = int(self.fixtures["cron_job"])
        completed = self.run(self.docker, "exec", "-u", "www-data", self.web, "php",
                             "/var/www/scripts/cron/cron_run_jobs.php", self.cron_key, "admin", str(job),
                             "--force", check=False, timeout=600)
        output = completed.stdout + completed.stderr
        if not expect_ok:
            if "Result of run_jobs" not in output:
                raise CheckFailed(f"the Dolibarr cron runner did not run the Mahnwesen job:\n{output[-1500:]}")
            return output
        if completed.returncode != 0 or "Result of run_jobs OK" not in output:
            raise CheckFailed(f"the Dolibarr cron runner did not run the Mahnwesen job:\n{output[-1500:]}")
        result = self.value(f"SELECT lastresult FROM llx_cronjob WHERE rowid = {job}")
        if result != "0":
            raise CheckFailed(f"the Mahnwesen job ended with result {result!r}:\n{output[-1500:]}")
        return output

    def log(self, since: float | None = None) -> str:
        """The web server's log, from a Unix time on when given."""
        since_args = ("--since", f"{int(since)}") if since else ()
        completed = self.run(self.docker, "logs", *since_args, self.web, check=False, timeout=120)
        return completed.stdout + completed.stderr

    def files(self, directory: str) -> list[str]:
        """File names in a directory of the Dolibarr container, empty when it does not exist."""
        completed = self.run(self.docker, "exec", self.web, "sh", "-c", f"ls -1 '{directory}' 2>/dev/null || true",
                             check=False, timeout=60)
        return sorted(line.strip() for line in completed.stdout.splitlines() if line.strip())


def invoice(stack: Stack, key: str) -> dict:
    return stack.fixtures["invoices"][key]


def translations(key: str) -> list[str]:
    """The German and English text of a module language key."""
    found = []
    for language in ("de_DE", "en_US"):
        for line in (LANGS / language / "mahnwesen.lang").read_text(encoding="utf-8").splitlines():
            if line.startswith(key + "="):
                found.append(line.split("=", 1)[1].strip())
    if not found:
        raise CheckFailed(f"language key {key} not found")
    return found


def form_with_action(page: Page, action: str, what: str):
    form = next((form for form in page.forms() if form.value("action") == action), None)
    expect(form is not None, f"{what}: no form with action {action}")
    return form


STRAY_NAME = re.compile(r"_A\d+\.pdf$|_\d{8}_\d{6}\.pdf$|_Invoice_A\d+|_Attachment_A\d+")
DOCUMENTS = "/var/www/documents"


def invoice_documents(stack: "Stack", key: str) -> list[str]:
    return stack.files(f"{DOCUMENTS}/facture/{invoice(stack, key)['ref']}")


def container_date(stack: "Stack", days: int, pattern: str = "d.m.Y") -> str:
    """Today plus (or minus) some days as PHP in the Dolibarr container formats it."""
    completed = stack.run(stack.docker, "exec", stack.web, "php", "-r",
                          f"echo date('{pattern}', strtotime('{days:+d} days'));", check=False, timeout=60)
    expect(completed.returncode == 0 and completed.stdout.strip(), f"PHP in the container gave no date: {completed.stderr}")
    return completed.stdout.strip()


def pdf_text(data: bytes) -> str:
    """The content streams of a PDF, inflated, as Latin-1 text for simple searches."""
    parts = []
    for match in re.finditer(rb"stream\r?\n(.*?)\r?\nendstream", data, re.S):
        try:
            parts.append(zlib.decompress(match.group(1)).decode("latin-1"))
        except zlib.error:
            parts.append(match.group(1).decode("latin-1"))
    return "\n".join(parts)


def check_letter(pdf: bytes, what: str, must: list, must_not: tuple = ()) -> None:
    """What a dunning letter has to show, on one page (#30)."""
    content = pdf_text(pdf)
    pages = len(re.findall(rb"/Type\s*/Page[^s]", pdf))
    missing = [text for text in must if text not in content]
    present = [text for text in must_not if text in content]
    expect(pdf.startswith(b"%PDF") and not missing and not present and pages == 1,
           f"{what}: missing {missing}, should not show {present}, {pages} page(s) (#25, #30)")


def container_file(stack: "Stack", path: str) -> bytes:
    import base64
    completed = stack.shell(f"base64 -w0 '{path}'")
    expect(completed.returncode == 0, f"{path} could not be read: {completed.stderr}")
    return base64.b64decode(completed.stdout.strip())


def page_ok(page: Page, what: str) -> Page:
    expect(page.status == 200, f"{what}: HTTP {page.status}")
    expect(not page.denied(), f"{what}: access denied")
    # A PHP fatal error ends the page early and still answers HTTP 200.
    expect("</html>" in page.text.lower(), f"{what}: the page ends early, PHP probably stopped: ...{page.text[-300:]!r}")
    problems = page.errors()
    expect(not problems, f"{what}: the page shows {', '.join(problems)}")
    return page


def module_list(browser: Browser) -> Page:
    return page_ok(browser.get("/admin/modules.php?mode=common&search_keyword=mahnwesen"), "module list")


def module_link(page: Page, action: str) -> str:
    """The enable or disable link of the module in Dolibarr's module list."""
    for href in re.findall(r'href="([^"]*modules\.php\?[^"]*)"', page.text):
        target = html.unescape(href)
        if f"action={action}&" in target + "&" and "value=modMahnwesen" in target:
            return target
    raise CheckFailed(f"the module list offers no action={action} link for modMahnwesen")


def switch_module(stack: Stack, action: str) -> None:
    """Enable (set) or disable (reset) the module from Dolibarr's module list."""
    browser = stack.browser()
    page_ok(browser.get(module_link(module_list(browser), action)), f"module list action {action}")


def package_version(package: Path) -> str:
    """The module version inside a package."""
    with zipfile.ZipFile(package) as bundle:
        text = bundle.read("mahnwesen/core/modules/modMahnwesen.class.php").decode("utf-8")
    return re.search(r"\$this->version\s*=\s*'([^']+)'", text).group(1)


def package_settings(package: Path) -> list[str]:
    """The MAHNWESEN_ settings the descriptor inside a package creates on activation."""
    with zipfile.ZipFile(package) as bundle:
        text = bundle.read("mahnwesen/core/modules/modMahnwesen.class.php").decode("utf-8")
    return sorted(set(re.findall(r"=> array\('(MAHNWESEN_[A-Z0-9_]+)', 'chaine'", text)))


def upload(stack: Stack, package: Path) -> list[str]:
    """Deploy an external module, as an administrator does it; the installed files must be the package."""
    browser = stack.browser()
    page = page_ok(browser.get("/admin/modules.php?mode=deploy"), "deploy page")
    form = page.form(name="forminstall")
    # checkforcompliance asks dolibarr.org for a blacklist; the check runs offline.
    fields = [(name, value) for name, value in form.values() if name != "checkforcompliance"]
    result = browser.post_multipart(form.url(), fields, [("fileinstall", package.name, package.read_bytes())])
    expect(result.status == 200, f"uploading {package.name} answered HTTP {result.status}")
    expect(not result.errors(), f"the upload page shows {', '.join(result.errors())}")
    installed = stack.shell(f"cd {MODULE_DIR} && find . -type f | sort")
    expect(installed.returncode == 0, f"the upload did not create {MODULE_DIR}:\n{result.text[-800:]}")
    files = sorted(line[2:] for line in installed.stdout.splitlines() if line.startswith("./"))
    with zipfile.ZipFile(package) as bundle:
        packaged = sorted(info.filename[len("mahnwesen/"):] for info in bundle.infolist() if not info.is_dir())
    expect(files == packaged, f"deployed files differ from {package.name}: "
                              f"missing {sorted(set(packaged) - set(files))[:5]}, extra {sorted(set(files) - set(packaged))[:5]}")
    # PHP's opcode cache keeps serving the replaced files until it checks their
    # dates again, every opcache.revalidate_freq seconds (2 in the images). An
    # activation within that time ran the old descriptor and missed new settings.
    time.sleep(opcache_revalidate_seconds(stack) + 1)
    return files


def opcache_revalidate_seconds(stack: Stack) -> int:
    if "opcache_freq" not in stack.notes:
        completed = stack.shell("php -r \"echo ini_get('opcache.validate_timestamps') ? (int) ini_get('opcache.revalidate_freq') : 0;\"")
        stack.notes["opcache_freq"] = int(completed.stdout.strip() or 0) if completed.returncode == 0 else 2
    return stack.notes["opcache_freq"]


# ------------------------------------------------------------------ scenarios

STAGE_CONSTANTS = r"name LIKE 'MAHNWESEN\\_STAGE_\\_DAYS' OR name LIKE 'MAHNWESEN\\_PRIVATE\\_FEE\\__' OR name LIKE 'MAHNWESEN\\_PAYMENT\\_DAYS\\__'"


def set_const(stack: Stack, name: str, value: str) -> None:
    stack.sql(f"DELETE FROM llx_const WHERE name = '{name}' AND entity = 1; "
              f"INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('{name}', 1, '{value}', 'chaine', 0)")


def upgrade_from(stack: Stack, package: Path) -> str:
    """One earlier release, with cases and settings, upgraded to this package."""
    old = package_version(package)
    upload(stack, package)
    switch_module(stack, "set")
    expect(stack.const("MAIN_MODULE_MAHNWESEN") == "1", f"{old} could not be enabled")
    # Cases, history and stages exist in every version; later tables came with later versions.
    tables = {row[0] for row in stack.sql("SHOW TABLES LIKE 'llx_mahnwesen_%'")}
    missing = [name for name in ("mahnwesen_case", "mahnwesen_history", "mahnwesen_rule") if f"llx_{name}" not in tables]
    expect(not missing, f"enabling the package of {old} created no tables {', '.join(missing)}")
    stack.php_fixture("legacy")
    # Settings where the old version keeps them: days and business fee in the
    # stage table, the private fee and the payment period as constants.
    stack.sql("UPDATE llx_mahnwesen_rule SET fee_amount = 55 WHERE level = 3 AND entity = 1")
    stack.sql("UPDATE llx_mahnwesen_rule SET days_after_due = 12 WHERE level = 2 AND entity = 1")
    set_const(stack, "MAHNWESEN_PRIVATE_FEE_3", "7.00")
    set_const(stack, "MAHNWESEN_PRIVATE_FEES_ALLOWED", "1")
    set_const(stack, "MAHNWESEN_PAYMENT_DAYS_1", "14")

    def state() -> list:
        return stack.sql("SELECT (SELECT COUNT(*) FROM llx_mahnwesen_case), (SELECT COUNT(*) FROM llx_mahnwesen_history), "
                         "(SELECT COUNT(*) FROM llx_c_email_templates WHERE module = 'mahnwesen')")[0]
    before = state()
    expect(before[0] == "4", f"{old} did not create the 4 cases: {before}")
    # A version that already knows profiles keeps its own; older fee settings are split up (#32).
    had_profiles = stack.const("MAHNWESEN_PROFILES_MIGRATED") == "1"

    upload(stack, stack.package)
    switch_module(stack, "reset")
    switch_module(stack, "set")
    expect(stack.const("MAIN_MODULE_MAHNWESEN") == "1", f"{stack.module_version} could not be enabled over {old}")
    after = state()
    expect(after == before, f"the upgrade from {old} changed cases, history or the starter templates: {before} -> {after}")
    tables = {row[0] for row in stack.sql("SHOW TABLES LIKE 'llx_mahnwesen_%'")}
    missing = [name for name in MODULE_TABLES if f"llx_{name}" not in tables]
    expect(not missing, f"after the upgrade from {old} the tables {', '.join(missing)} are missing")
    # The company fee, the private fee (allowed) and the fee of customers of
    # unclear type (none) become three profiles, each with the stage settings (#20, #32).
    def rules_of(code: str) -> dict:
        return {row[0]: row[1:] for row in stack.sql(
            "SELECT r.level, r.days_after_due, ROUND(r.fee_amount, 2), r.payment_days FROM llx_mahnwesen_rule r "
            f"JOIN llx_mahnwesen_profile p ON p.rowid = r.fk_profile WHERE p.code = '{code}' AND r.entity = 1 ORDER BY r.level")}
    default, company, private = rules_of("default"), rules_of("company"), rules_of("private")
    types = {row[0]: row[1] for row in stack.sql("SELECT m.customer_type, p.code FROM llx_mahnwesen_profile_match m "
                                                  "JOIN llx_mahnwesen_profile p ON p.rowid = m.fk_profile WHERE m.kind = 'customer_type'")}
    if had_profiles:
        expect(default.get("1", [None] * 3)[2] == "14" and default.get("2", [None])[0] == "12" and default.get("3", [None] * 2)[1] == "55.00"
               and not company and not private and not types,
               f"the upgrade from {old} changed the profiles it already had: default {default}, company {company}, "
               f"private {private}, customer types {types} (#32)")
    else:
        expect(all(rules.get("1", [None] * 3)[2] == "14" and rules.get("2", [None])[0] == "12" for rules in (default, company, private))
               and [rules.get("3", [None] * 2)[1] for rules in (default, company, private)] == ["0.00", "55.00", "7.00"],
               f"the upgrade from {old} did not move the stage and fee settings into profiles: "
               f"default {default}, company {company}, private {private} (#20, #32)")
        expect(types == {"company": "company", "private": "private"}, f"the profiles after the upgrade from {old} apply to {types} (#32)")
    leftovers = stack.sql(f"SELECT name FROM llx_const WHERE {STAGE_CONSTANTS} OR name LIKE 'MAHNWESEN\\_%\\_FEES\\_ALLOWED'")
    expect(not leftovers, f"the upgrade from {old} left the old stage constants {leftovers} (#20, #32)")
    assets = stack.sql("SELECT name FROM llx_const WHERE name IN ('MAIN_MODULE_MAHNWESEN_CSS', 'MAIN_MODULE_MAHNWESEN_JS')")
    expect(not assets, f"the upgrade from {old} still loads the module's CSS or JS on every page: {assets} (#27)")
    columns = {row[0] for row in stack.sql("SHOW COLUMNS FROM llx_mahnwesen_rule")}
    body = stack.value("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
                       "AND TABLE_NAME = 'llx_mahnwesen_attempt' AND COLUMN_NAME = 'body_html'")
    expect(not columns & {"minimum_amount", "generate_pdf", "fee_private"} and body == "mediumtext",
           f"the schema after the upgrade from {old}: rule columns {sorted(columns)}, body_html {body} (#20)")
    missing = [name for name in package_settings(stack.package) if stack.const(name) is None]
    if missing:
        rows = stack.sql("SELECT name, entity, value FROM llx_const WHERE name LIKE 'MAHNWESEN%' OR name = 'MAIN_MODULE_MAHNWESEN' ORDER BY name")
        expect(False, f"the upgrade from {old} did not add the settings {missing}; the settings table holds {rows}")
    page_ok(stack.browser().get("/custom/mahnwesen/index.php"), f"dashboard after the upgrade from {old}")
    stack.php_fixture("reset")
    expect(stack.const("MAIN_MODULE_MAHNWESEN") is None and not stack.sql("SHOW TABLES LIKE 'llx_mahnwesen_%'")
           and not stack.sql("SELECT name FROM llx_extrafields WHERE name LIKE 'mahnwesen%'"),
           f"the reset after the upgrade from {old} left module state behind")
    return old


def upgrade(stack: Stack) -> str:
    """Installations of earlier releases take the new package: data stays, settings move, new settings arrive (#20, #23)."""
    expect(stack.fixtures.get("dolibarr", "").startswith(stack.version.rsplit(".", 1)[0]),
           f"the container runs Dolibarr {stack.fixtures.get('dolibarr')}, expected {stack.version}")
    if not stack.upgrade_packages:
        return "no earlier release to upgrade from"
    done = [upgrade_from(stack, package) for package in stack.upgrade_packages]
    # PHP messages of the old versions are not this package's.
    stack.notes["log_since"] = time.time()
    return (f"{', '.join(done)} -> {stack.module_version}: cases, history and templates kept; "
            "stage settings moved into the stage table, old constants gone; all settings present")


def deploy(stack: Stack) -> str:
    """The package goes in the way an administrator installs it: Deploy an external module."""
    if stack.previous_package is None:
        expect(stack.shell(f"test ! -e {MODULE_DIR}").returncode == 0, "the module exists before the upload")
    files = upload(stack, stack.package)
    return f"{stack.package.name} deployed: {len(files)} files in custom/mahnwesen on Dolibarr {stack.fixtures['dolibarr']}"


def enable(stack: Stack) -> str:
    """Enabling from the module list works; then the users and settings the checks need."""
    browser = stack.browser()
    page = module_list(browser)
    page_ok(browser.get(module_link(page, "set")), "enable")
    expect(stack.const("MAIN_MODULE_MAHNWESEN") == "1", "MAIN_MODULE_MAHNWESEN is not 1 after enabling from the module list")
    stack.fixtures.update(stack.php_fixture("enabled"))
    return f"enabled from the module list, cron job {stack.fixtures['cron_job']}, users rtsales and rtother"


def install(stack: Stack) -> str:
    """The module is active, its tables and cron job exist, the starter templates are there."""
    enabled = stack.value("SELECT value FROM llx_const WHERE name = 'MAIN_MODULE_MAHNWESEN' AND entity = 1")
    expect(enabled == "1", "MAIN_MODULE_MAHNWESEN is not 1 after activation")
    tables = {row[0] for row in stack.sql("SHOW TABLES LIKE 'llx_mahnwesen_%'")}
    missing = [name for name in MODULE_TABLES if f"llx_{name}" not in tables]
    expect(not missing, f"tables missing after activation: {', '.join(missing)}")
    expect(int(stack.fixtures.get("cron_job") or 0) > 0, "the daily Mahnwesen cron job was not registered")
    starters = stack.sql("SELECT lang, label FROM llx_c_email_templates WHERE module = 'mahnwesen' ORDER BY rowid")
    expect(len(starters) == 4 and all(row[0] == "de_DE" for row in starters),
           f"a German company without English customers should get the 4 German starter templates only, found {starters} (#53)")
    expect(not any("(" in row[1] for row in starters), f"a starter label contains parentheses: {starters} (#53)")
    hooks = stack.value("SELECT value FROM llx_const WHERE name = 'MAIN_MODULE_MAHNWESEN_HOOKS' AND entity = 1") or ""
    expect("invoicecard" in hooks and "emailtemplates" in hooks, f"hooks not registered: {hooks!r}")
    return (f"Dolibarr {stack.fixtures['dolibarr']} on PHP {stack.fixtures['php']}: module active, "
            f"{len(MODULE_TABLES)} tables, cron job, 4 German starter templates")


def case_list(stack: Stack) -> str:
    """The dashboard lists the stored cases with filters, sorting and pages (#26)."""
    browser = stack.browser()

    def refs(query: str) -> list[str]:
        page = page_ok(browser.get("/custom/mahnwesen/index.php" + query), f"case list {query}")
        return [html.unescape(ref) for ref in re.findall(r'<tr class="oddeven" data-case="\d+">\s*<td[^>]*><a [^>]*>([^<]+)</a>', page.text)]

    everything = {invoice(stack, key)["ref"] for key in ("company_overdue", "company_recent", "private_overdue", "company_renamed")}
    listed = refs("")
    expect(set(listed) == everything, f"the case list shows {listed}, expected the 4 cases (#26)")
    level4 = refs("?search_level=4&search_status=all")
    expect(level4 == [invoice(stack, "company_overdue")["ref"]], f"filtering on the 3rd dunning notice shows {level4} (#26)")
    rita = refs("?search_company=Rita")
    expect(rita == [invoice(stack, "private_overdue")["ref"]], f"filtering on the customer shows {rita} (#26)")
    by_amount = refs("?sortfield=c.remaining_amount&sortorder=desc")
    expected = [invoice(stack, key)["ref"] for key in ("company_overdue", "private_overdue", "company_recent", "company_renamed")]
    expect(by_amount == expected, f"sorted by amount the list is {by_amount}, expected {expected} (#26)")
    first = refs("?sortfield=c.remaining_amount&sortorder=desc&limit=2")
    second = refs("?sortfield=c.remaining_amount&sortorder=desc&limit=2&page=1")
    expect(first == expected[:2] and second == expected[2:], f"pages of two show {first} and {second} (#26)")
    return "4 stored cases, filtered by stage and customer, sorted by amount, in pages"


def synchronise(stack: Stack) -> str:
    """The dashboard button creates one case per overdue invoice and mirrors history to the agenda."""
    browser = stack.browser()
    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    form = next((form for form in dashboard.forms() if form.value("action") == "sync_cases"), None)
    expect(form is not None, "the dashboard has no synchronise button")
    page_ok(browser.submit(form), "synchronise")
    cases = {row[0]: row for row in stack.sql(
        "SELECT fk_facture, current_level, status, paused, ROUND(remaining_amount, 2) FROM llx_mahnwesen_case")}
    expectations = {"company_overdue": ("4", "120.00"), "private_overdue": ("2", "96.00"),
                    "company_recent": ("0", "60.00"), "company_renamed": ("3", "36.00")}
    for key, (level, amount) in expectations.items():
        row = cases.get(str(invoice(stack, key)["id"]))
        expect(row is not None, f"no case for {key}")
        expect(row[1] == level and row[2] == "open" and row[3] == "0" and row[4] == amount,
               f"case for {key} is {row[1:]}, expected level {level}, open, not paused, {amount}")
    created = int(stack.value("SELECT COUNT(*) FROM llx_mahnwesen_history WHERE action = 'case_created'") or 0)
    expect(created == 4, f"expected 4 case_created history rows, found {created}")
    # Dolibarr stores the element type of an invoice event as 'invoice'.
    linked = {row[0]: int(row[1]) for row in stack.sql(
        "SELECT fk_element, COUNT(*) FROM llx_actioncomm WHERE ref_ext LIKE 'mahnwesen-history-%' "
        "AND elementtype IN ('invoice', 'facture') GROUP BY fk_element")}
    unlinked = [key for key in expectations if linked.get(str(invoice(stack, key)["id"]), 0) < 1]
    expect(not unlinked, f"no agenda event on the invoice for {', '.join(unlinked)}")
    agenda = sum(linked.values())
    return f"4 cases with the expected stages and balances, {agenda} agenda events"


def pages(stack: Stack) -> str:
    """Every module page and both Dolibarr integration points render without errors."""
    browser = stack.browser()
    overdue = invoice(stack, "company_overdue")["id"]
    paths = {
        "dashboard": "/custom/mahnwesen/index.php",
        "attempts": "/custom/mahnwesen/attempts.php",
        "figures": "/custom/mahnwesen/stats.php",
        "setup general": "/custom/mahnwesen/admin/setup.php?tab=general",
        "setup profiles": "/custom/mahnwesen/admin/setup.php?tab=profiles",
        "setup stages": "/custom/mahnwesen/admin/setup.php?tab=stages",
        "setup interest": "/custom/mahnwesen/admin/setup.php?tab=interest",
        "setup templates": "/custom/mahnwesen/admin/setup.php?tab=templates",
        "setup automation": "/custom/mahnwesen/admin/setup.php?tab=automation",
        "invoice tab": f"/custom/mahnwesen/invoice.php?id={overdue}",
        "composer": f"/custom/mahnwesen/notice.php?id={overdue}",
    }
    old_version = re.compile(r"\b[Vv]ersion 0\.\d|\bv0\.\d")
    for what, path in paths.items():
        page = page_ok(browser.get(path), what)
        stale = old_version.search(html.unescape(page.text))
        expect(stale is None, f"{what} still names an old module version: {stale.group(0) if stale else ''} (#9)")
    automation_tab = browser.get(paths["setup automation"]).text
    expect('name="attach_invoice_default"' not in automation_tab,
           "the setup still offers 'attach invoice PDF by default', which has no effect (#10)")
    card = page_ok(browser.get(f"/compta/facture/card.php?facid={overdue}"), "invoice card")
    expect(f"mahnwesen/notice.php?id={overdue}" in card.text,
           "the invoice card has no dunning action (invoicecard hook)")
    templates = page_ok(browser.get("/admin/mails_templates.php"), "email templates")
    expect("mahnwesen_reminder" in templates.text,
           "Dolibarr's email template page does not offer the Mahnwesen types (emailtemplates hook)")
    # The variable help loads with the template page only, in the user's language; the CSS with the module's pages (#27, #28).
    expect("mahnwesen-emailtemplates.js" in templates.text and "window.mahnwesenTemplateHelp" in templates.text
           and "Aktuelle Mahnstufe" in html.unescape(templates.text),
           "the email template page lacks the translated variable help (#27, #28)")
    foreign = page_ok(browser.get("/societe/list.php"), "third-party list")
    expect("/mahnwesen/css/" not in foreign.text and "/mahnwesen/js/" not in foreign.text,
           "a Dolibarr page outside the module loads the module's CSS or JS (#27)")
    expect("/mahnwesen/css/mahnwesen.css" in browser.get(paths["dashboard"]).text, "the dashboard lacks the module's CSS")
    before = stack.sql("SELECT rowid, name FROM llx_const WHERE name LIKE 'MAIN_MODULE_MAHNWESEN%' ORDER BY rowid")
    page_ok(browser.get(paths["setup general"]), "setup")
    after = stack.sql("SELECT rowid, name FROM llx_const WHERE name LIKE 'MAIN_MODULE_MAHNWESEN%' ORDER BY rowid")
    expect(before == after and not any(row[1] == "MAIN_MODULE_MAHNWESEN_JS" for row in after),
           f"opening the setup rewrote the module's settings: {before} -> {after} (#27)")
    return f"{len(paths) + 2} pages render, invoice card action and template types present"


def access(stack: Stack) -> str:
    """A sales representative reaches his customers' invoices and nothing else."""
    company = invoice(stack, "company_overdue")
    private = invoice(stack, "private_overdue")
    sales = stack.browser("rtsales")
    dashboard = page_ok(sales.get("/custom/mahnwesen/index.php"), "dashboard for the sales representative")
    expect(company["ref"] in dashboard.text, "the sales representative does not see his customer's invoice")
    expect(private["ref"] not in dashboard.text, "the sales representative sees another customer's invoice")
    page_ok(sales.get(f"/custom/mahnwesen/invoice.php?id={company['id']}"), "invoice tab of his customer")
    page_ok(sales.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer for his customer")
    for path in (f"/custom/mahnwesen/invoice.php?id={private['id']}", f"/custom/mahnwesen/notice.php?id={private['id']}"):
        expect(sales.get(path).denied(), f"{path} is open to a sales representative of another customer")
    other = stack.browser("rtother")
    expect(other.get(f"/custom/mahnwesen/invoice.php?id={company['id']}").denied(),
           "a user who is not the customer's sales representative opens the invoice tab")
    expect(other.get(f"/custom/mahnwesen/notice.php?id={company['id']}").denied(),
           "a user who is not the customer's sales representative opens the composer")
    return "own customer open, other customers denied on dashboard, tab and composer"


PAYMENT_TEMPLATE_LINE = ("<p>Frist: __MAHNWESEN_PAYMENT_DEADLINE__ (__MAHNWESEN_PAYMENT_DAYS__ Tage), "
                         "naechste Stufe __MAHNWESEN_NEXT_STAGE_DATE__</p>"
                         "<p>Mit freundlichen Gruessen<br>Kassa-Team Runtime</p><!--MAHNWESEN_PDF_END-->"
                         "<p>Signatur Bankdaten intern</p>")
# A long legal footer: the body of the reminder passes the old 60 KB limit (#20).
# The database repeats the text; a command line on Windows holds 32 KB only.
LONG_FOOTER_SQL = "'<p>', REPEAT('Rechtlicher Hinweis zur Zahlungserinnerung. ', 1500), 'ENDE-DES-HINWEISES</p>'"


def payment_deadline(stack: Stack) -> str:
    """A stage's payment period reaches the template variables and refuses nonsense (#64)."""
    company = invoice(stack, "company_overdue")
    browser = stack.browser()
    stages = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=stages"), "stages setup")
    page_ok(browser.submit(form_with_action(stages, "save_stages", "stages setup"), {"stage_payment_days_1": "10"}),
            "set a payment period of 10 days for the payment reminder")
    saved = stack.value("SELECT payment_days FROM llx_mahnwesen_rule WHERE level = 1 AND entity = 1")
    expect(saved == "10", f"the payment period of the payment reminder is {saved!r} after saving 10")
    stages = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=stages"), "stages setup")
    refused = browser.submit(form_with_action(stages, "save_stages", "stages setup"), {"stage_payment_days_2": "400"})
    messages = [label.replace("%s", "2") for label in translations("MahnwesenStageValuesInvalid")]
    expect(any(message in html.unescape(refused.text) for message in messages),
           "a payment period of 400 days was not refused with a message")
    kept = stack.value("SELECT payment_days FROM llx_mahnwesen_rule WHERE level = 2 AND entity = 1")
    expect(kept in (None, "0"), f"a refused payment period was saved anyway: {kept!r}")
    variables = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=templates"), "templates setup")
    expect("__MAHNWESEN_PAYMENT_DEADLINE__" in variables.text and "__MAHNWESEN_PAYMENT_DAYS__" in variables.text,
           "the variable help of the setup does not list the payment deadline variables")

    stack.sql("UPDATE llx_c_email_templates SET content = CONCAT(content, '" + PAYMENT_TEMPLATE_LINE + "', " + LONG_FOOTER_SQL + ") "
              "WHERE module = 'mahnwesen' AND type_template = 'mahnwesen_reminder' AND lang = 'de_DE'")
    body = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer").form(name="mailform").value("message") or ""
    deadline, after = container_date(stack, 10), container_date(stack, 11)
    expected = f"Frist: {deadline} (10 Tage), naechste Stufe {after}"
    expect(expected in body, f"the composer's reminder text lacks {expected!r}: "
           f"{re.search(r'Frist:[^<]*', body).group(0) if 'Frist:' in body else body[-300:]}")
    return f"10 days saved, 400 refused, composer says pay by {deadline} and next stage {after}"


def manual_send(stack: Stack) -> str:
    """The composer delivers exactly one reminder with the recorded attachments."""
    company = invoice(stack, "company_overdue")
    mailpit = stack.mailpit()
    mailpit.clear()
    browser = stack.browser()
    composer = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer")
    form = composer.form(name="mailform")
    contact = str(stack.fixtures["contacts"]["billing"])
    sent = browser.submit(form, {"action": "send_notice", "confirm_send": "1", "receiver[]": contact},
                          button=("sendmail", "1"))
    page_ok(sent, "send")
    expect("/custom/mahnwesen/invoice.php" in sent.url, f"the send did not return to the invoice tab: {sent.url}")
    messages = mailpit.messages()
    expect(len(messages) == 1, f"expected one email, Mailpit received {len(messages)}")
    message = messages[0]
    recipients = [entry["Address"] for entry in message["To"]]
    expect(recipients == ["berta.billing@runtime-gmbh.test"], f"sent to {recipients}, not the billing contact")
    expect(company["ref"] in message["Subject"], f"subject {message['Subject']!r} lacks the invoice reference")
    received = mailpit.attachment_hashes(message["ID"])
    attempts = stack.sql(f"SELECT rowid, status, mode, level FROM llx_mahnwesen_attempt WHERE fk_facture = {company['id']}")
    expect(len(attempts) == 1 and attempts[0][1:] == ["sent", "manual", "1"],
           f"expected one sent manual attempt at stage 1, found {attempts}")
    recorded = {row[0]: row[1] for row in stack.sql(
        f"SELECT display_name, sha256 FROM llx_mahnwesen_attempt_file WHERE fk_attempt = {int(attempts[0][0])}")}
    expect(set(received) == set(recorded),
           f"attachments received {sorted(received)} differ from those recorded {sorted(recorded)}")
    differing = [name for name in received if received[name] != recorded[name]]
    expect(not differing, f"SHA-256 in the database differs from the delivered bytes: {differing}")
    headers = json.dumps(mailpit._json(f"/api/v1/message/{message['ID']}/headers"))
    expect(f"inv{company['id']}" in headers, f"the email carries no Dolibarr track id inv{company['id']} (#28)")
    expected_names = {f"{company['ref']}_Zahlungserinnerung.pdf", f"{company['ref']}.pdf"}
    expect(set(received) == expected_names,
           f"the email's attachments are named {sorted(received)}, expected {sorted(expected_names)} (#52)")
    attempt_id = int(attempts[0][0])
    evidence = stack.sql(f"SELECT rowid, snapshot_path, sha256 FROM llx_mahnwesen_attempt_file WHERE fk_attempt = {attempt_id}")
    outside = [row[1] for row in evidence if not row[1].startswith(f"{DOCUMENTS}/mahnwesen/attempts/{attempt_id}/")]
    expect(not outside, f"evidence files are kept outside the attempt folder: {outside} (#19)")
    documents = invoice_documents(stack, "company_overdue")
    expect(f"{company['ref']}_Zahlungserinnerung.pdf" in documents,
           f"the invoice documents lack the dunning PDF under its stage name: {documents} (#52)")
    stray = [name for name in documents if STRAY_NAME.search(name)]
    expect(not stray, f"the invoice documents carry timestamped or attempt copies: {stray} (#52)")
    for row in evidence:
        download = browser.get(f"/custom/mahnwesen/attempts.php?evidence={row[0]}")
        expect(download.status == 200 and hashlib.sha256(download.body).hexdigest() == row[2],
               f"downloading evidence file {row[0]} did not return the recorded bytes (HTTP {download.status}) (#19)")
    stranger = stack.browser("rtother").get(f"/custom/mahnwesen/attempts.php?evidence={evidence[0][0]}")
    expect(hashlib.sha256(stranger.body).hexdigest() != evidence[0][2],
           "a user outside the customer scope downloads delivery evidence")
    history = stack.value("SELECT COUNT(*) FROM llx_mahnwesen_history WHERE action = 'notice_sent' "
                          f"AND result = 'success' AND level = 1 AND fk_facture = {company['id']}")
    expect(history == "1", "no notice_sent history row for the reminder")

    sent_html = mailpit.message(message["ID"]).get("HTML") or ""
    stored = int(stack.value(f"SELECT LENGTH(body_html) FROM llx_mahnwesen_attempt WHERE rowid = {attempt_id}") or 0)
    expect(stored > 60000 and "ENDE-DES-HINWEISES" in sent_html,
           f"a reminder of {stored} bytes was not stored and sent in full (#20)")
    sent_day = datetime.date.fromisoformat(stack.value(f"SELECT DATE(reserved_at) FROM llx_mahnwesen_attempt WHERE rowid = {attempt_id}"))
    deadline_day = sent_day + datetime.timedelta(days=10)
    deadline = deadline_day.strftime("%d.%m.%Y")
    # The mailer may wrap a long line, so the text is compared without its line breaks.
    sent_flat = re.sub(r"\s+", " ", sent_html)
    expect(f"Frist: {deadline} (10 Tage)" in sent_flat,
           f"the sent email does not name the payment deadline {deadline}, it says "
           f"{(re.search(r'Frist:[^<]*', sent_flat) or re.search('$^', '')).group(0) if 'Frist:' in sent_flat else 'nothing'!r} (#64)")
    recorded_text = stack.value("SELECT message FROM llx_mahnwesen_history WHERE action = 'notice_sent' "
                                f"AND level = 1 AND fk_facture = {company['id']}") or ""
    expect(f"Payment deadline: {deadline_day.isoformat()}" in recorded_text,
           f"the history of the sent reminder does not record its payment deadline: {recorded_text!r} (#64)")
    letter = stack.value(f"SELECT rowid FROM llx_mahnwesen_attempt_file WHERE fk_attempt = {attempt_id} "
                         f"AND display_name = '{company['ref']}_Zahlungserinnerung.pdf'")
    letter_pdf = browser.get(f"/custom/mahnwesen/attempts.php?evidence={letter}").body
    content = pdf_text(letter_pdf)
    expect("Zahlbar bis" in content and deadline in content,
           f"the dunning PDF does not show 'Zahlbar bis {deadline}' (#64)")
    check_letter(letter_pdf, "the payment reminder PDF", [company["ref"], "120,00", "Kundenweg 7", "Kassa-Team Runtime"],
                 ("Signatur Bankdaten intern", "ENDE-DES-HINWEISES"))
    tab = html.unescape(page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={company['id']}"), "invoice tab").text)
    after = container_date(stack, 11)
    expect(after in tab, f"the invoice tab does not hold the next stage back until {after}, the day after the deadline (#64)")

    again = browser.submit(form, {"action": "send_notice", "confirm_send": "1", "receiver[]": contact},
                           button=("sendmail", "1"))
    expect(again.status == 200, f"the repeated send answered HTTP {again.status}")
    expect(len(mailpit.messages()) == 1, "submitting the same composer twice sent a second email")
    count = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_attempt WHERE fk_facture = {company['id']}")
    expect(count == "1", f"the repeated send created another attempt ({count} in total)")
    return f"one email to the billing contact, {len(received)} attachments with matching SHA-256, repeat refused"


def preview(stack: Stack) -> str:
    """A generated preview PDF opens for the sender and his sales team, not for others (#5)."""
    company = invoice(stack, "company_overdue")
    contact = str(stack.fixtures["contacts"]["billing"])
    browser = stack.browser()
    composer = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer")
    shown = page_ok(browser.submit(composer.form(name="mailform"),
                                   {"action": "generate_preview", "receiver[]": contact}), "generate preview")
    section = shown.text.find('class="mahnwesen-document-preview"')
    expect(section >= 0, "the composer shows no document preview after generating the preview")
    match = re.search(r'<iframe[^>]+src="([^"#]+)', shown.text[section:])
    expect(match is not None, "the composer shows no preview frame after generating the preview")
    url = html.unescape(match.group(1))
    for who, client in (("the administrator", browser), ("the sales representative", stack.browser("rtsales"))):
        pdf = client.get(url)
        expect(pdf.status == 200 and pdf.body.startswith(b"%PDF"),
               f"{who} cannot open the preview PDF at {url} (HTTP {pdf.status}, access denied: {pdf.denied()})")
    stranger = stack.browser("rtother").get(url)
    expect(not stranger.body.startswith(b"%PDF"), "a user outside the customer scope opens the preview PDF")
    return "preview PDF opens for administrator and sales representative, not for another user"


def attachment_choice(stack: Stack) -> str:
    """A differently named invoice PDF is found, and an unticked invoice PDF stays out of the email (#6, #8)."""
    renamed = invoice(stack, "company_renamed")
    contact = str(stack.fixtures["contacts"]["billing"])
    mailpit = stack.mailpit()
    mailpit.clear()
    browser = stack.browser()
    composer = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={renamed['id']}"), "composer")
    form = composer.form(name="mailform")
    expect(form.has("attach_invoice"),
           f"the composer does not offer the invoice PDF {renamed['last_main_doc']} that last_main_doc names")
    link = re.search(r'href="([^"]*document\.php\?modulepart=invoice[^"]*)"', composer.text)
    expect(link is not None, "the composer has no link to the invoice PDF")
    pdf = browser.get(html.unescape(link.group(1)))
    expect(pdf.status == 200 and pdf.body.startswith(b"%PDF"),
           f"the invoice PDF link does not open the PDF (HTTP {pdf.status}): {html.unescape(link.group(1))}")
    sent = browser.submit(form, {"action": "send_notice", "confirm_send": "1", "receiver[]": contact},
                          drop=("attach_invoice",), button=("sendmail", "1"))
    page_ok(sent, "send with the invoice PDF unticked")
    messages = mailpit.messages()
    expect(len(messages) == 1, f"expected one email, Mailpit received {len(messages)}")
    names = sorted(mailpit.attachment_hashes(messages[0]["ID"]))
    expect(names == [f"{renamed['ref']}_Zahlungserinnerung.pdf"],
           f"with the invoice PDF unticked the email carried {names}")
    roles = [row[0] for row in stack.sql(
        "SELECT f.file_role FROM llx_mahnwesen_attempt_file f JOIN llx_mahnwesen_attempt a ON a.rowid = f.fk_attempt "
        f"WHERE a.fk_facture = {renamed['id']}")]
    expect(roles == ["dunning"], f"the attempt recorded the attachments {roles}")
    return "renamed invoice PDF offered and opens; unticked, the email carries only the dunning PDF"


def pause_label(stack: Stack) -> str:
    """An indefinite pause reads as indefinite, a dated one shows its date, closing ends the pause (#7)."""
    recent = invoice(stack, "company_recent")
    indefinite = translations("MahnwesenPauseIndefinite")
    until = translations("MahnwesenPauseUntil")
    browser = stack.browser()
    tab_url = f"/custom/mahnwesen/invoice.php?id={recent['id']}"

    editor = page_ok(browser.get(tab_url + "&edit=pause"), "pause editor")
    page_ok(browser.submit(form_with_action(editor, "pause_case", "pause editor"),
                           {"pause_reason": "Runtime check: dispute", "pause_until": ""}), "pause indefinitely")
    tab = page_ok(browser.get(tab_url), "tab of the indefinitely paused case")
    readable = html.unescape(tab.text)
    expect(any(f"<strong>{label}</strong>" in readable for label in indefinite),
           "an indefinite pause is not shown as indefinite")
    expect(not any(f"{label} <strong>" in readable for label in until),
           "an indefinite pause is shown with an end date")

    page_ok(browser.submit(form_with_action(tab, "resume_case", "paused tab")), "resume")
    editor = page_ok(browser.get(tab_url + "&edit=pause"), "pause editor")
    end = (datetime.date.today() + datetime.timedelta(days=10)).isoformat()
    page_ok(browser.submit(form_with_action(editor, "pause_case", "pause editor"),
                           {"pause_reason": "Runtime check: promise to pay", "pause_until": end}), "pause until a date")
    readable = html.unescape(page_ok(browser.get(tab_url), "tab of the dated pause").text)
    expect(any(f"{label} <strong>" in readable for label in until), "a dated pause does not show its end date")

    stack.sql(f"UPDATE llx_facture SET fk_statut = 2, paye = 1 WHERE rowid = {recent['id']}")
    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    page_ok(browser.submit(form_with_action(dashboard, "sync_cases", "dashboard")), "synchronise")
    case = stack.sql(f"SELECT status, paused FROM llx_mahnwesen_case WHERE fk_facture = {recent['id']}")
    expect(case and case[0] == ["closed", "0"], f"the paid invoice's case is {case}, expected closed and not paused")
    active = stack.value("SELECT COUNT(*) FROM llx_mahnwesen_pause p JOIN llx_mahnwesen_case c ON c.rowid = p.fk_case "
                         f"WHERE c.fk_facture = {recent['id']} AND p.status = 'active'")
    expect(active == "0", f"closing the case left {active} active pause rows")
    return "indefinite and dated pause labelled correctly, closing the case ends its pause"


def failure_reason(stack: Stack) -> str:
    """A refused action says why (#11)."""
    company = invoice(stack, "company_overdue")
    browser = stack.browser()
    tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={company['id']}"), "invoice tab")
    ask = re.search(r'href="([^"]*invoice\.php\?id=\d+&amp;action=ask_skip[^"]*)"', tab.text)
    expect(ask is not None, "the invoice tab offers no way to skip the stage")
    dialog = page_ok(browser.get(html.unescape(ask.group(1))), "skip confirmation")
    refused = browser.submit(form_with_action(dialog, "skip_stage", "skip confirmation"), {"skip_reason": "", "confirm": "yes"})
    expect(refused.status == 200, f"skipping without a reason answered HTTP {refused.status}")
    expect("A reason is required to skip a dunning stage." in html.unescape(refused.text),
           "skipping a stage without a reason fails without saying why")
    skipped = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE action = 'stage_skipped' AND fk_facture = {company['id']}")
    expect(skipped == "0", "a stage was skipped without a reason")
    return "skipping without a reason is refused and the page names the reason"


def documents(stack: Stack) -> str:
    """Generating a stage's PDF twice leaves one file under its plain name (#52)."""
    private = invoice(stack, "private_overdue")
    browser = stack.browser()
    composer = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={private['id']}"), "composer")
    announced = html.unescape(composer.text)
    expect(f"{private['ref']}_Zahlungserinnerung.pdf" in announced and "A<id>" not in announced,
           "the composer does not announce the dunning PDF under its plain name")
    # The company letterhead of Dolibarr's PDF setup is drawn on the letter (#25).
    stack.shell(f"mkdir -p {DOCUMENTS}/mycompany && cp '{DOCUMENTS}/{private['last_main_doc']}' {DOCUMENTS}/mycompany/letterhead.pdf")
    stack.sql("DELETE FROM llx_const WHERE name = 'MAIN_ADD_PDF_BACKGROUND' AND entity = 1; "
              "INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('MAIN_ADD_PDF_BACKGROUND', 1, 'letterhead.pdf', 'chaine', 0)")
    try:
        for attempt in (1, 2):
            tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={private['id']}"), "invoice tab")
            page_ok(browser.submit(form_with_action(tab, "generate_notice_pdf", "invoice tab")), f"generate the PDF ({attempt})")
    finally:
        stack.sql("DELETE FROM llx_const WHERE name = 'MAIN_ADD_PDF_BACKGROUND' AND entity = 1")
    letter = container_file(stack, f"{DOCUMENTS}/facture/{private['ref']}/{private['ref']}_Zahlungserinnerung.pdf")
    expect(b"/Subtype /Form" in letter, "the dunning PDF does not carry the letterhead of the PDF setup (#25)")
    files = invoice_documents(stack, "private_overdue")
    expected = sorted([f"{private['ref']}.pdf", f"{private['ref']}_Zahlungserinnerung.pdf"])
    expect(files == expected, f"after generating twice the invoice documents are {files}, expected {expected}")
    generated = stack.value("SELECT COUNT(*) FROM llx_mahnwesen_history WHERE action = 'document_generated' "
                            f"AND fk_facture = {private['id']}")
    expect(generated == "2", f"expected two document_generated history rows, found {generated}")
    return "one PDF per stage in the invoice documents, replaced on regeneration, both generations in the history"


def automation(stack: Stack) -> str:
    """Switched on in the setup, the Dolibarr cron sends each due stage once (#12)."""
    private = invoice(stack, "private_overdue")
    mailpit = stack.mailpit()
    mailpit.clear()
    stack.cron()
    idle = stack.sql("SELECT status, attempted, sent FROM llx_mahnwesen_run ORDER BY rowid DESC LIMIT 1")
    expect(idle and idle[0] == ["success", "0", "0"], f"with automation off the run was {idle}")
    expect(not mailpit.messages(), "the cron sent email while automatic sending was off")

    browser = stack.browser()
    confirmation = translations("MahnwesenAutoSendConfirmationRequired")
    stages = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=stages"), "stages setup")
    page_ok(browser.submit(form_with_action(stages, "save_stages", "stages setup"), {"stage_auto_send_1": "1"}),
            "allow automatic sending for the payment reminder")
    settings = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=automation"), "automation setup")
    unconfirmed = browser.submit(form_with_action(settings, "save_automation", "automation setup"),
                                 {"auto_send_enabled": "1"}, drop=("auto_send_confirm",))
    expect(any(label in html.unescape(unconfirmed.text) for label in confirmation),
           "automatic sending was switched on without the confirmation")
    settings = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=automation"), "automation setup")
    page_ok(browser.submit(form_with_action(settings, "save_automation", "automation setup"),
                           {"auto_send_enabled": "1", "auto_send_confirm": "1"}), "switch automatic sending on")
    enabled = stack.value("SELECT value FROM llx_const WHERE name = 'MAHNWESEN_AUTO_SEND_ENABLED' AND entity = 1")
    expect(enabled == "1", f"automatic sending is {enabled!r} after confirming it in the setup")
    settings = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=automation"), "automation setup")
    saved = browser.submit(form_with_action(settings, "save_automation", "automation setup"),
                           {"auto_send_max": "11"}, drop=("auto_send_confirm",))
    expect(not any(label in html.unescape(saved.text) for label in confirmation),
           "saving the automation tab while automatic sending stays on asks for the confirmation again (#12)")
    limit = stack.value("SELECT value FROM llx_const WHERE name = 'MAHNWESEN_AUTO_SEND_MAX' AND entity = 1")
    expect(limit == "11", f"the run limit is {limit!r} after saving 11")
    stack.cron()
    run = stack.sql("SELECT status, attempted, sent, failed FROM llx_mahnwesen_run ORDER BY rowid DESC LIMIT 1")
    expect(run and run[0] == ["success", "1", "1", "0"], f"expected one automatic send, the run was {run}")
    messages = mailpit.messages()
    expect(len(messages) == 1 and messages[0]["To"][0]["Address"] == "rita@privat.test"
           and private["ref"] in messages[0]["Subject"],
           f"expected the reminder for {private['ref']} to rita@privat.test, got "
           f"{[(m['To'][0]['Address'], m['Subject']) for m in messages]}")

    stack.cron()
    again = stack.sql("SELECT status, attempted, sent FROM llx_mahnwesen_run ORDER BY rowid DESC LIMIT 1")
    expect(again and again[0] == ["success", "0", "0"], f"the second cron run was {again}")
    expect(len(mailpit.messages()) == 1, "the second cron run sent the reminder again")
    return "off: nothing sent; on: one reminder to the private customer; next run: nothing repeated"


def php_messages(stack: Stack) -> set:
    """PHP errors, warnings, notices and deprecations raised in module code."""
    found = set()
    log = stack.log(stack.notes.get("log_since"))
    for match in PHP_PROBLEM.finditer(log):
        found.add(f"{match.group(3).replace('/var/www/html/custom/mahnwesen/', '')}: {match.group(1)}: {match.group(2)}")
    for match in PHP_UNCAUGHT.finditer(log):
        found.add(f"{match.group(3)}: {match.group(1)}: {match.group(2)}")
    return found


def reactivation(stack: Stack) -> str:
    """Disabling and enabling the module keeps manual sending and the data, and stops automatic sending (#54)."""
    def counts() -> list:
        return stack.sql("SELECT (SELECT COUNT(*) FROM llx_mahnwesen_case), (SELECT COUNT(*) FROM llx_mahnwesen_history), "
                         "(SELECT COUNT(*) FROM llx_mahnwesen_attempt), (SELECT COUNT(*) FROM llx_c_email_templates WHERE module = 'mahnwesen')")[0]

    def setting(name: str) -> str | None:
        return stack.value(f"SELECT value FROM llx_const WHERE name = '{name}' AND entity = 1")

    expect(setting("MAHNWESEN_MANUAL_SEND_ENABLED") == "1" and setting("MAHNWESEN_AUTO_SEND_ENABLED") == "1",
           "manual and automatic sending should both be on before the module is disabled")
    before = counts()
    browser = stack.browser()
    modules = page_ok(browser.get("/admin/modules.php?search_keyword=mahnwesen"), "module list")
    token = token_of(modules)
    browser.get(f"/admin/modules.php?action=reset&value=modMahnwesen&confirm=yes&token={token}&search_keyword=mahnwesen")
    expect(stack.value("SELECT value FROM llx_const WHERE name = 'MAIN_MODULE_MAHNWESEN' AND entity = 1") in (None, "0"),
           "the module list did not disable Mahnwesen")
    token = token_of(browser.get("/admin/modules.php?search_keyword=mahnwesen"))
    browser.get(f"/admin/modules.php?action=set&value=modMahnwesen&token={token}&search_keyword=mahnwesen")
    expect(stack.value("SELECT value FROM llx_const WHERE name = 'MAIN_MODULE_MAHNWESEN' AND entity = 1") == "1",
           "the module list did not enable Mahnwesen again")
    expect(setting("MAHNWESEN_MANUAL_SEND_ENABLED") == "1", "re-activating the module switched manual sending off (#54)")
    expect(setting("MAHNWESEN_AUTO_SEND_ENABLED") in (None, "0"), "re-activating the module kept automatic sending on")
    after = counts()
    expect(after == before, f"cases, history, attempts and starter templates changed on re-activation: {before} -> {after}")
    page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard after re-activation")
    return "manual sending kept, automatic sending off, cases, history, attempts and templates unchanged"


def templates(stack: Stack) -> str:
    """Own templates beat starters, a stage's template can be fixed, starters follow the languages in use (#53)."""
    company = invoice(stack, "company_overdue")
    private = stack.fixtures["customers"]["private"]
    browser = stack.browser()

    def preselected() -> tuple[str, str]:
        form = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer").form(name="mailform")
        return form.value("modelmailselected") or "", form.value("subject") or ""

    stack.sql("INSERT INTO llx_c_email_templates (entity, module, type_template, lang, private, fk_user, datec, label, position, "
              "defaultfortype, enabled, active, email_from, topic, joinfiles, content) VALUES (1, NULL, 'mahnwesen_dunning1', '', 0, NULL, "
              "NOW(), 'Eigene 1. Mahnung', 0, 0, '1', 1, '', 'Eigene 1. Mahnung zu {INVOICE_REF}', '1', '<p>Eigener Text {INVOICE_REF}</p>')")
    own = stack.value("SELECT rowid FROM llx_c_email_templates WHERE label = 'Eigene 1. Mahnung'")
    starter = stack.value("SELECT rowid FROM llx_c_email_templates WHERE module = 'mahnwesen' AND type_template = 'mahnwesen_dunning1' AND lang = 'de_DE'")
    chosen, subject = preselected()
    expect(chosen == own and subject == f"Eigene 1. Mahnung zu {company['ref']}",
           f"the composer preselects template {chosen} ({subject!r}) instead of the own template {own}")

    setup = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=templates"), "templates setup")
    page_ok(browser.submit(form_with_action(setup, "save_templates", "templates setup"), {"template_2": starter}),
            "fix the 1st dunning notice template")
    chosen, _ = preselected()
    expect(chosen == starter, f"after fixing the starter template the composer preselects {chosen}, not {starter}")
    stages = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=stages"), "stages setup")
    page_ok(browser.submit(form_with_action(stages, "save_stages", "stages setup")), "save the stages")
    kept = stack.value("SELECT email_template FROM llx_mahnwesen_rule WHERE level = 2 AND entity = 1")
    expect(kept == f"native:{starter}", f"saving the stages replaced the chosen template with {kept!r}")
    setup = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=templates"), "templates setup")
    page_ok(browser.submit(form_with_action(setup, "save_templates", "templates setup"), {"template_2": "auto"}),
            "back to automatic")

    stack.sql("INSERT INTO llx_c_email_templates (entity, module, type_template, lang, private, fk_user, datec, label, position, "
              "defaultfortype, enabled, active, email_from, topic, joinfiles, content) VALUES (1, 'mahnwesen', 'mahnwesen_dunning3', 'en_US', "
              "0, NULL, NOW(), 'Mahnwesen - 3. Mahnung (English)', 40, 1, '1', 1, '', 'Third reminder', '1', '<p>Legacy</p>')")
    stack.sql(f"UPDATE llx_societe SET default_lang = 'en_US' WHERE rowid = {int(private)}")
    english = html.unescape(page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={invoice(stack, 'private_overdue')['id']}"), "composer").text)
    expect("_1stNotice.pdf" in english and "_1.Mahnung.pdf" not in english,
           "the composer for an English customer does not name the PDF in English (#28)")
    setup = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=templates"), "templates setup")
    page_ok(browser.submit(form_with_action(setup, "create_starter_templates", "templates setup")), "create starter templates")
    english = stack.sql("SELECT type_template, label FROM llx_c_email_templates WHERE module = 'mahnwesen' AND lang = 'en_US' ORDER BY type_template")
    expected = [["mahnwesen_dunning2", "Mahnwesen - 2. Mahnung - English"], ["mahnwesen_dunning3", "Mahnwesen - 3. Mahnung - English"],
                ["mahnwesen_reminder", "Mahnwesen - Zahlungserinnerung - English"]]
    expect(english == expected,
           f"with an English customer the English starters should be those without an own neutral template, the old label renamed; found {english}")
    return "own template preselected, fixed choice kept across stage saves, English starters only where needed, old labels renamed"


def no_payment_period(stack: Stack) -> None:
    """Stage timing without the reminder's payment deadline, which would hold the next stage back on its own."""
    stack.sql("UPDATE llx_mahnwesen_rule SET payment_days = 0 WHERE level = 1 AND entity = 1")


def stage_spacing(stack: Stack) -> str:
    """The next stage is due at the start of the day the spacing ends, not at the time of day of the last notice (#18)."""
    private = invoice(stack, "private_overdue")
    no_payment_period(stack)
    # The reminder went out seven days ago - the spacing between the reminder
    # and the 1st dunning notice - late in the evening.
    sent = container_date(stack, -7, "Y-m-d") + " 23:59:59"
    stack.sql(f"UPDATE llx_mahnwesen_history SET date_creation = '{sent}' WHERE fk_facture = {private['id']} "
              "AND action = 'notice_sent' AND level = 1")
    tab = page_ok(stack.browser().get(f"/custom/mahnwesen/invoice.php?id={private['id']}"), "invoice tab")
    ready = re.search(rf'class="butAction" href="[^"]*notice\.php\?id={private["id"]}"', tab.text)
    waiting = re.search(r'class="butActionRefused classfortooltip" title="([^"]*)"', tab.text)
    expect(ready is not None, "a reminder sent seven days ago at 23:59 does not make the 1st dunning notice due today: "
           f"{html.unescape(waiting.group(1)) if waiting else 'no send action'} (#18)")
    return f"reminder sent {sent}, the 1st dunning notice is due today"


def open_attempt(stack: Stack) -> str:
    """An unresolved attempt blocks skipping its stage; confirming it late keeps the higher fee and the latest notice date (#17)."""
    renamed = invoice(stack, "company_renamed")
    browser = stack.browser()
    no_payment_period(stack)
    case = stack.value(f"SELECT rowid FROM llx_mahnwesen_case WHERE fk_facture = {renamed['id']}")
    stack.sql(f"UPDATE llx_mahnwesen_history SET date_creation = '{container_date(stack, -30, 'Y-m-d H:i:s')}' "
              f"WHERE fk_facture = {renamed['id']} AND action = 'notice_sent' AND level = 1")

    def attempt(level: int, status: str, fee: str, days_ago: int) -> str:
        stack.sql("INSERT INTO llx_mahnwesen_attempt (entity, fk_case, fk_facture, level, mode, status, recipient, sender, subject, "
                  "amount_invoice, amount_fee, amount_total, currency_code, reserved_at) VALUES "
                  f"(1, {case}, {renamed['id']}, {level}, 'manual', '{status}', 'berta.billing@runtime-gmbh.test', "
                  f"'mahnwesen@runtime-verein.test', 'Runtime check stage {level}', 36, {fee}, 36 + {fee}, 'EUR', "
                  f"'{container_date(stack, -days_ago, 'Y-m-d H:i:s')}')")
        return stack.value(f"SELECT MAX(rowid) FROM llx_mahnwesen_attempt WHERE fk_case = {case}")

    ambiguous = attempt(2, "ambiguous", "40", 20)
    tab_url = f"/custom/mahnwesen/invoice.php?id={renamed['id']}"
    tab = page_ok(browser.get(tab_url), "invoice tab with an unresolved attempt")
    expect(not any(form.value("action") == "skip_stage" for form in tab.forms()),
           f"the invoice tab offers to skip the 1st dunning notice while attempt {ambiguous} is unresolved (#17)")
    blocked = [label.replace("%s", ambiguous) for label in translations("MahnwesenSkipBlockedByAttempt")]
    expect(any(label in html.unescape(tab.text) for label in blocked), "the invoice tab does not say why skipping is blocked")
    browser.post(tab_url, [("token", token_of(tab)), ("id", str(renamed["id"])), ("action", "skip_stage"),
                           ("confirm", "yes"), ("skip_reason", "Runtime check: skip despite the open attempt")])
    skipped = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_facture = {renamed['id']} AND action = 'stage_skipped'")
    expect(skipped == "0", f"a stage was skipped while its attempt {ambiguous} was unresolved (#17)")

    # What an earlier version allowed: the stage skipped anyway, the next stage
    # sent with its higher fee, and only then the old attempt confirmed.
    stack.sql("INSERT INTO llx_mahnwesen_history (entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, message, "
              f"date_creation) VALUES (1, {case}, {renamed['id']}, 'stage_skipped', 2, 36, 'manual', 'success', 'before 1.1.0', "
              f"'{container_date(stack, -19, 'Y-m-d H:i:s')}')")
    higher = attempt(3, "sent", "60", 10)
    later_notice = container_date(stack, -10, "Y-m-d H:i:s")
    stack.sql("INSERT INTO llx_mahnwesen_fee (entity, fk_case, fk_facture, fk_attempt, level, amount, currency_code, status, date_creation) "
              f"VALUES (1, {case}, {renamed['id']}, {higher}, 3, 60, 'EUR', 'open', '{later_notice}')")
    stack.sql(f"UPDATE llx_mahnwesen_case SET last_notice_at = '{later_notice}' WHERE rowid = {case}")
    attempts = page_ok(browser.get("/custom/mahnwesen/attempts.php"), "delivery attempts")
    form = next((form for form in attempts.forms() if form.value("action") == "resolve_attempt"
                 and form.value("attempt_id") == ambiguous), None)
    expect(form is not None, f"the delivery attempts page offers no resolution for attempt {ambiguous}")
    page_ok(browser.submit(form, {"resolution": "confirmed_sent", "reason": "Runtime check: found in the sent folder"}),
            "confirm the old attempt as delivered")
    status = stack.value(f"SELECT status FROM llx_mahnwesen_attempt WHERE rowid = {ambiguous}")
    expect(status == "sent", f"confirming attempt {ambiguous} left it {status!r}")
    fees = stack.sql(f"SELECT level, ROUND(amount, 2), status FROM llx_mahnwesen_fee WHERE fk_case = {case} ORDER BY level")
    expect(fees == [["2", "40.00", "superseded"], ["3", "60.00", "open"]],
           f"after the late confirmation the fees are {fees}, expected the 40 superseded and the 60 still open (#17)")
    kept = stack.value(f"SELECT last_notice_at FROM llx_mahnwesen_case WHERE rowid = {case}")
    expect(kept == later_notice, f"the late confirmation moved the case's last notice from {later_notice} to {kept} (#17)")
    return f"skipping refused while attempt {ambiguous} was open; confirmed late, it left the 60 fee open and the last notice date"


def last_run(stack: Stack) -> list[str]:
    rows = stack.sql("SELECT rowid, status, attempted, sent, skipped, failed, REPLACE(summary, '\\n', ' ') "
                     "FROM llx_mahnwesen_run ORDER BY rowid DESC LIMIT 1")
    return rows[0] if rows else []


OPS_ADDRESS = "ops@runtime-verein.test"


def automatic_stage_two(stack: Stack) -> None:
    """Automatic sending on, for the 1st dunning notice only, with a retry limit of two."""
    stack.sql("UPDATE llx_const SET value = '1' WHERE name = 'MAHNWESEN_AUTO_SEND_ENABLED' AND entity = 1")
    if stack.value("SELECT COUNT(*) FROM llx_const WHERE name = 'MAHNWESEN_AUTO_SEND_ENABLED' AND entity = 1") == "0":
        stack.sql("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('MAHNWESEN_AUTO_SEND_ENABLED', 1, '1', 'chaine', 0)")
    stack.sql("DELETE FROM llx_const WHERE name = 'MAHNWESEN_AUTO_RETRY_MAX' AND entity = 1")
    stack.sql("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('MAHNWESEN_AUTO_RETRY_MAX', 1, '2', 'chaine', 0)")
    stack.sql("UPDATE llx_mahnwesen_rule SET send_email = CASE WHEN level = 2 THEN 1 ELSE 0 END WHERE entity = 1")


def smtp_outage(stack: Stack) -> str:
    """With the mail server down, automatic attempts fail retryably and stop at the retry limit (#14)."""
    private = invoice(stack, "private_overdue")
    automatic_stage_two(stack)

    def attempts() -> list[list[str]]:
        return stack.sql(f"SELECT rowid, status FROM llx_mahnwesen_attempt WHERE fk_facture = {private['id']} AND level = 2 ORDER BY rowid")

    stack.run(stack.docker, "stop", stack.mail, check=True, timeout=120)
    try:
        for run in (1, 2, 3):
            stack.cron(expect_ok=False)
            found = attempts()
            expected = min(run, 2)
            expect(len(found) == expected and all(row[1] == "failed" for row in found),
                   f"after cron run {run} with the mail server down the attempts of the 1st dunning notice are {found}, "
                   f"expected {expected} failed and none ambiguous (#14)")
        third = last_run(stack)
        expect(third[2:4] == ["0", "0"], f"the third run tried again beyond the retry limit of two: {third}")
    finally:
        stack.run(stack.docker, "start", stack.mail, check=False, timeout=120)
    deadline = time.time() + 60
    while True:
        try:
            stack.mailpit().messages()
            break
        except OSError:
            expect(time.time() < deadline, "Mailpit did not come back within 60 s")
            time.sleep(1)
    return "mail server down: two failed attempts, the third run stopped at the retry limit, nothing ambiguous"


def broken_invoice(stack: Stack) -> str:
    """One invoice the synchronisation cannot write does not stop automatic dunning of the others (#15)."""
    private = invoice(stack, "private_overdue")
    broken = invoice(stack, "company_overdue")
    browser = stack.browser()
    # After the outage an operator releases the failed attempts, one run at a time (#21).
    failed = [row[0] for row in stack.sql(f"SELECT rowid FROM llx_mahnwesen_attempt WHERE fk_facture = {private['id']} AND status = 'failed'")]
    page = page_ok(browser.get("/custom/mahnwesen/attempts.php"), "delivery attempts")
    releases = [form for form in page.forms() if form.value("action") == "release_run"]
    expect(len(releases) == 2, f"the attempts page should offer to release the failed attempts of the two outage runs, "
                               f"offers {len(releases)} (#21)")
    for form in releases:
        page_ok(browser.submit(form, {"reason": "Runtime check: mail server back"}), f"release run {form.value('run_id')}")
    statuses = stack.sql(f"SELECT status FROM llx_mahnwesen_attempt WHERE rowid IN ({', '.join(failed)})")
    expect([row[0] for row in statuses] == ["resolved"] * len(failed),
           f"releasing the runs left the failed attempts {statuses} (#21)")

    # The operator wants to hear about runs that go wrong (#21).
    settings = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=automation"), "automation setup")
    refused = browser.submit(form_with_action(settings, "save_automation", "automation setup"), {"run_notify_email": "not-an-address"})
    expect(any(label in html.unescape(refused.text) for label in translations("MahnwesenRunNotifyEmailInvalid")),
           "an invalid notification address was not refused")
    settings = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=automation"), "automation setup")
    page_ok(browser.submit(form_with_action(settings, "save_automation", "automation setup"), {"run_notify_email": OPS_ADDRESS}),
            "set the notification address")
    expect(stack.const("MAHNWESEN_RUN_NOTIFY_EMAIL") == OPS_ADDRESS, "the notification address was not saved")
    stack.sql(f"""DELIMITER //
CREATE TRIGGER rt_broken_invoice BEFORE UPDATE ON llx_mahnwesen_case FOR EACH ROW BEGIN
IF NEW.fk_facture = {int(broken['id'])} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Runtime check: broken invoice'; END IF;
END //
DELIMITER ;""")
    mailpit = stack.mailpit()
    mailpit.clear()
    try:
        stack.cron(expect_ok=False)
    finally:
        stack.sql("DROP TRIGGER IF EXISTS rt_broken_invoice")
    run = last_run(stack)
    messages = mailpit.messages()
    dunning = [m for m in messages if m["To"][0]["Address"] != OPS_ADDRESS]
    expect([m["To"][0]["Address"] for m in dunning] == ["rita@privat.test"],
           f"with {broken['ref']} broken the cron did not send the 1st dunning notice to the private customer: "
           f"run {run}, emails {[(m['To'][0]['Address'], m['Subject']) for m in messages]} (#15)")
    expect(run[1] == "warning" and broken["ref"] in run[6],
           f"the run with a broken invoice should end as a warning naming {broken['ref']}: {run} (#15)")
    notices = [m["Subject"] for m in messages if m["To"][0]["Address"] == OPS_ADDRESS]
    expect(len(notices) == 1 and f"#{run[0]}" in notices[0],
           f"the run with a warning should notify {OPS_ADDRESS} once about run {run[0]}, got {notices} (#21)")
    return (f"{broken['ref']} could not be synchronised; the private customer still got the 1st dunning notice, "
            f"run {run[0]} is a warning naming it; failed attempts released per run; {OPS_ADDRESS} notified")


def dry_run(stack: Stack) -> str:
    """The dry run decides exactly as the cron, which then sends what it announced (#16, #21)."""
    company = invoice(stack, "company_overdue")
    browser = stack.browser()
    case = stack.value(f"SELECT rowid FROM llx_mahnwesen_case WHERE fk_facture = {company['id']}")
    # The reminder went out eight days ago, and a promise to pay paused the
    # case until yesterday: the cron lifts the pause and sends the 1st notice.
    stack.sql(f"UPDATE llx_mahnwesen_history SET date_creation = '{container_date(stack, -8, 'Y-m-d H:i:s')}' "
              f"WHERE fk_facture = {company['id']} AND action = 'notice_sent' AND level = 1")
    stack.sql("INSERT INTO llx_mahnwesen_pause (entity, fk_case, fk_facture, status, pause_until, reason, date_creation) "
              f"VALUES (1, {case}, {company['id']}, 'active', '{container_date(stack, -1, 'Y-m-d')} 00:00:00', "
              f"'Runtime check: promise to pay', '{container_date(stack, -3, 'Y-m-d H:i:s')}')")
    stack.sql(f"UPDATE llx_mahnwesen_case SET paused = 1 WHERE rowid = {case}")
    # A fee for the 1st dunning notice, so the letter shows it (#30).
    stack.sql("UPDATE llx_mahnwesen_rule SET fee_amount = 40 WHERE level = 2 AND entity = 1")

    stack.sql("UPDATE llx_cronjob SET status = 1 WHERE methodename = 'sendEmailsRemindersOnInvoiceDueDate'")
    try:
        dashboard = html.unescape(page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard").text)
    finally:
        stack.sql("UPDATE llx_cronjob SET status = 0 WHERE methodename = 'sendEmailsRemindersOnInvoiceDueDate'")
    warning = [label.split("<a")[0].strip() for label in translations("MahnwesenCoreReminderActive")]
    expect(any(text in dashboard for text in warning),
           "the dashboard does not warn that Dolibarr's own payment reminder is active as well (#21)")

    runs = lambda: stack.value("SELECT COUNT(*) FROM llx_mahnwesen_run WHERE mode = 'dry_run'")
    before = runs()
    sales = stack.browser("rtsales")
    sales_dashboard = sales.get("/custom/mahnwesen/index.php")
    expect('value="dry_run"' not in sales_dashboard.text, "a user without the dry run right is offered the dry run (#16)")
    sales.post("/custom/mahnwesen/index.php", [("token", token_of(sales_dashboard)), ("action", "dry_run")])
    expect(runs() == before, "a user without the dry run right wrote a dry run record (#16)")

    result = browser.submit(form_with_action(page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard"), "dry_run", "dashboard"))
    page_ok(result, "dry run")
    decisions = {}
    for row in result.text.split('<tr class="oddeven">'):
        ref = re.search(r"invoice\.php\?id=\d+\">([^<]+)</a>", row)
        decision = re.search(r'data-decision="(\w+)"', row)
        if ref and decision:
            decisions[html.unescape(ref.group(1))] = decision.group(1)
    ready = sorted(ref for ref, decision in decisions.items() if decision == "send")
    expect(ready == [company["ref"]],
           f"the dry run should announce exactly the 1st dunning notice for {company['ref']} (paused until yesterday), "
           f"announced {ready}; all decisions {decisions}; summary "
           f"{html.unescape((re.search(r'id=.mahnwesen-dry-run-summary.>(.*?)</div>', result.text, re.S) or re.search('$^', '')).group(0) if re.search(r'id=.mahnwesen-dry-run-summary', result.text) else 'missing')!r}, "
           f"{result.text.count('data-decision')} decision cells (#16)")
    paused = stack.value(f"SELECT paused FROM llx_mahnwesen_case WHERE rowid = {case}")
    expect(paused == "1" and runs() == str(int(before) + 1), "the dry run changed the case or wrote no run record (#16)")

    mailpit = stack.mailpit()
    mailpit.clear()
    stack.cron()
    sent = sorted({ref for m in mailpit.messages() for ref in decisions if ref in m["Subject"]})
    notices = [m for m in mailpit.messages() if m["To"][0]["Address"] == OPS_ADDRESS]
    expect(sent == ready, f"the dry run announced {ready}, the cron then sent {sent} (#16)")
    evidence = stack.value("SELECT f.rowid FROM llx_mahnwesen_attempt_file f JOIN llx_mahnwesen_attempt a ON a.rowid = f.fk_attempt "
                           f"WHERE a.fk_facture = {company['id']} AND a.level = 2 AND f.file_role = 'dunning' ORDER BY f.rowid DESC LIMIT 1")
    check_letter(browser.get(f"/custom/mahnwesen/attempts.php?evidence={evidence}").body, "the 1st dunning notice PDF",
                 [company["ref"], "120,00", "40,00", "160,00", "Kundenweg 7"])
    expect(not notices, f"a run without problems notified {OPS_ADDRESS} (#21)")
    return f"dry run and cron agree on {ready}, the expired pause included; no dry run without the right; reminder job warned"


def invoice_view(stack: Stack) -> str:
    """The invoice card has one dunning action; tab and composer speak plainly; skipping asks first (#56)."""
    overdue = invoice(stack, "company_overdue")
    browser = stack.browser()
    card = page_ok(browser.get(f"/compta/facture/card.php?facid={overdue['id']}"), "invoice card")
    actions = card.text.count(f"mahnwesen/notice.php?id={overdue['id']}")
    expect(actions == 1 and not any(form.value("action") == "generate_notice_pdf" for form in card.forms()),
           f"the invoice card should carry one dunning action, it links the composer {actions} time(s) or offers the PDF form (#56)")
    tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={overdue['id']}"), "dunning tab")
    shown = html.unescape(re.sub(r"<[^>]+>", " ", tab.text))
    # The wording before #56; its language keys are gone since #27.
    old_wording = ("Gespeicherter Restbetrag", "Stored remaining amount", "Aktuell berechnete Mahnstufe", "Currently calculated stage")
    for code in ("TE_SMALL", "TE_PRIVATE", *old_wording):
        expect(code not in shown, f"the dunning tab still shows {code!r} (#56)")
    expect(any(label in shown for label in translations("MahnwesenNextRequiredStage")),
           "the dunning tab does not name the next step (#56)")
    expect(not any(form.value("action") == "skip_stage" for form in tab.forms()),
           "the dunning tab offers the skip form without a confirmation (#56)")
    composer = html.unescape(re.sub(r"<[^>]+>", " ", page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={overdue['id']}"), "composer").text))
    expect(not any(label in composer for label in old_wording[2:]),
           "the composer still shows the calculated stage next to the next step (#56)")
    return "one action on the invoice card; tab and composer without codes and technical labels"


def billing_role(stack: Stack) -> str:
    """Without a billing contact on the invoice, the customer's default billing contact is the recipient (#22)."""
    private = invoice(stack, "private_overdue")
    customer = stack.fixtures["customers"]["private"]
    admin = stack.fixtures["users"]["admin"]
    stack.sql("INSERT INTO llx_socpeople (entity, fk_soc, lastname, firstname, email, statut, datec, fk_user_creat) VALUES "
              f"(1, {customer}, 'Payer', 'Paula', 'paula.payer@privat.test', 1, NOW(), {admin})")
    contact = stack.value("SELECT rowid FROM llx_socpeople WHERE email = 'paula.payer@privat.test'")
    role = stack.value("SELECT rowid FROM llx_c_type_contact WHERE element = 'facture' AND source = 'external' AND code = 'BILLING'")
    stack.sql("INSERT INTO llx_societe_contacts (entity, date_creation, fk_soc, fk_c_type_contact, fk_socpeople) "
              f"VALUES (1, NOW(), {customer}, {role}, {contact})")
    composer = page_ok(stack.browser().get(f"/custom/mahnwesen/notice.php?id={private['id']}"), "composer")
    expect(f'value="{contact}"' in composer.text and "paula.payer@privat.test" in html.unescape(composer.text),
           "the composer does not offer the customer's default billing contact for an invoice without one (#22)")
    company = invoice(stack, "company_overdue")
    other = html.unescape(page_ok(stack.browser().get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer").text)
    expect("paula.payer@privat.test" not in other, "another customer's billing contact appears in the composer")
    return "the customer's default billing contact is offered when the invoice has none"


def dunning_block(stack: Stack) -> str:
    """A block on the customer or the invoice stops manual and automatic notices until its last day (#37)."""
    company = invoice(stack, "company_overdue")
    private = invoice(stack, "private_overdue")
    browser = stack.browser()
    fields = stack.sql("SELECT elementtype, name FROM llx_extrafields WHERE name LIKE 'mahnwesen_block%' ORDER BY elementtype, name")
    expect(len(fields) == 6, f"activation did not add the block fields to customers and invoices: {fields} (#37)")
    card = html.unescape(page_ok(browser.get(f"/compta/facture/card.php?facid={company['id']}"), "invoice card").text)
    expect(any(label in card for label in translations("MahnwesenBlock")), "the invoice card lacks the field for the dunning block (#37)")
    customer = stack.value(f"SELECT fk_soc FROM llx_facture WHERE rowid = {company['id']}")

    def block(table: str, object_id, until: str, reason: str) -> None:
        stack.sql(f"DELETE FROM llx_{table}_extrafields WHERE fk_object = {object_id}")
        stack.sql(f"INSERT INTO llx_{table}_extrafields (fk_object, mahnwesen_block, mahnwesen_block_until, mahnwesen_block_reason) "
                  f"VALUES ({object_id}, 1, {until}, '{reason}')")

    def details() -> dict:
        result = page_ok(browser.submit(form_with_action(page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard"),
                                                         "dry_run", "dashboard")), "dry run")
        found = {}
        for row in result.text.split('<tr class="oddeven">'):
            ref = re.search(r"invoice\.php\?id=\d+\">([^<]+)</a>", row)
            detail = re.search(r'data-detail="(\w+)"', row)
            if ref and detail:
                found[html.unescape(ref.group(1))] = detail.group(1)
        return found

    reason = "Runtime check: Ratenzahlung vereinbart"
    try:
        block("societe", customer, "NULL", reason)
        block("facture", private["id"], f"'{container_date(stack, 0, 'Y-m-d')}'", "Runtime check: Rechnung strittig")
        found = details()
        expect(found.get(company["ref"]) == "blocked_customer" and found.get(private["ref"]) == "blocked_invoice",
               f"the dry run does not name the blocks, it decided {found} (#37)")
        tab = html.unescape(page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={company['id']}"), "dunning tab").text)
        expect(reason in tab and any(label in tab for label in translations("MahnwesenBlockOnCustomer")),
               "the dunning tab does not name the customer's block and its reason (#37)")
        composer = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer")
        expect(reason in html.unescape(composer.text) and 'id="sendmail"' not in composer.text,
               "the composer offers sending despite the customer's block (#37)")
        listed = html.unescape(page_ok(browser.get("/custom/mahnwesen/index.php?search_status=blocked"), "blocked cases").text)
        expected = {row[0] for row in stack.sql("SELECT f.ref FROM llx_mahnwesen_case c JOIN llx_facture f ON f.rowid = c.fk_facture "
                                                f"WHERE c.status = 'open' AND (f.fk_soc = {customer} OR f.rowid = {private['id']})")}
        shown = set(re.findall(r'<tr class="oddeven" data-case="\d+">\s*<td[^>]*><a [^>]*>([^<]+)</a>', listed))
        expect(shown == expected and reason in listed and "Runtime check: Rechnung strittig" in listed,
               f"the dashboard lists {shown} as blocked, expected {expected} with their reasons (#37)")

        # The customer's block ended yesterday; the invoice's lasts through today.
        block("societe", customer, f"'{container_date(stack, -1, 'Y-m-d')}'", reason)
        found = details()
        expect(not found.get(company["ref"], "").startswith("blocked") and found.get(private["ref"]) == "blocked_invoice",
               f"a block applies after its last day or ends before it, the dry run decided {found} (#37)")
    finally:
        stack.sql(f"DELETE FROM llx_societe_extrafields WHERE fk_object = {customer}")
        stack.sql(f"DELETE FROM llx_facture_extrafields WHERE fk_object = {private['id']}")
    return "customer and invoice blocks named in dry run, tab, composer and list; a block ends after its last day"


def profiles(stack: Stack) -> str:
    """Company, membership and merchandise invoices get their own profiles, a mixed one the most careful (#32)."""
    browser = stack.browser()
    expect(stack.sql("SELECT code FROM llx_mahnwesen_profile WHERE entity = 1") == [["default"]],
           "a new installation should have the default profile only (#32)")
    data = stack.php_fixture("profiles")
    made = data["invoices"]
    company = invoice(stack, "company_overdue")
    setup = "/custom/mahnwesen/admin/setup.php"

    def create(label: str, changes: dict, drop: tuple = (), fee: str = "0") -> str:
        page = page_ok(browser.get(f"{setup}?tab=profiles"), "profiles setup")
        page_ok(browser.submit(form_with_action(page, "create_profile", "profiles setup"), {"profile_label": label}), f"add {label}")
        profile = stack.value(f"SELECT rowid FROM llx_mahnwesen_profile WHERE label = '{label}'")
        expect(profile, f"the profile {label} was not added (#32)")
        form = page_ok(browser.get(f"{setup}?tab=profiles&profile={profile}&edit=1"), f"form of {label}")
        page_ok(browser.submit(form_with_action(form, "save_profile", f"form of {label}"), changes, drop=drop), f"save {label}")
        stages = page_ok(browser.get(f"{setup}?tab=stages&profile={profile}"), f"stages of {label}")
        page_ok(browser.submit(form_with_action(stages, "save_stages", f"stages of {label}"),
                               {f"stage_fee_{level}": fee for level in range(1, 5)}), f"fees of {label}")
        return profile

    firms = create("Runtime Firmen", {"customer_type": "company"}, fee="40")
    dues = create("Runtime Mitgliedsbeitrag", {"product_categories[]": data["categories"]["membership"],
                                               "profile_final_step": "membership_review"}, drop=("profile_auto_allowed",))
    shop = create("Runtime Merchandise", {"product_categories[]": data["categories"]["merchandise"]}, fee="5")
    default = stack.value("SELECT rowid FROM llx_mahnwesen_profile WHERE code = 'default'")
    taken = page_ok(browser.submit(form_with_action(page_ok(browser.get(f"{setup}?tab=profiles&profile={shop}&edit=1"), "form"),
                                                    "save_profile", "form"), {"customer_type": "company"}), "a second profile for companies")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_profile_match WHERE customer_type = 'company'") == "1"
           and "Runtime Firmen" in html.unescape(taken.text), "a customer type was given to two profiles (#32)")

    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    page_ok(browser.submit(form_with_action(dashboard, "sync_cases", "dashboard")), "synchronise")
    result = page_ok(browser.submit(form_with_action(page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard"),
                                                     "dry_run", "dashboard")), "dry run")
    found = {}
    for row in result.text.split('<tr class="oddeven">'):
        ref = re.search(r"invoice\.php\?id=\d+\">([^<]+)</a>", row)
        profile = re.search(r'data-profile="(\d+)"', row)
        if ref and profile:
            found[html.unescape(ref.group(1))] = profile.group(1)
    wanted = {company["ref"]: firms, made["membership"]["ref"]: dues, made["merchandise"]["ref"]: shop, made["mixed"]["ref"]: dues}
    expect({ref: found.get(ref) for ref in wanted} == wanted,
           f"the dry run names the profiles {found}, expected {wanted} (#32)")

    def tab(key: str) -> str:
        return html.unescape(re.sub(r"<[^>]+>", " ", page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={made[key]['id']}"), f"tab {key}").text))
    mixed = tab("mixed")
    expect("Runtime Mitgliedsbeitrag" in mixed and "Mitgliedsbeitrag, Merchandise" in mixed and "Runtime Merchandise" in mixed
           and any(text in mixed for text in translations("MahnwesenProfileMostCareful")),
           "the tab of a mixed invoice does not say that several profiles apply and the most careful one holds (#32)")
    expect(any(text in tab("membership") for text in translations("MahnwesenFinalStepMembership")),
           "the tab of a dues invoice does not name the final step of its profile (#32)")
    expect("5,00" in tab("merchandise"), "the merchandise invoice does not carry the fee of its profile (#32)")

    # A preset comes switched off, and the invoice card offers active profiles only.
    presets = page_ok(browser.get(f"{setup}?tab=profiles"), "profiles setup")
    preset = next((form for form in presets.forms() if form.value("action") == "add_profile_preset" and form.value("preset") == "club"), None)
    expect(preset is not None, "the setup does not offer the club preset (#32)")
    page_ok(browser.submit(preset), "add the club preset")
    club = stack.sql("SELECT rowid, active, auto_allowed, final_step FROM llx_mahnwesen_profile WHERE code = 'club'")
    expect(club and club[0][1:] == ["0", "0", "membership_review"], f"the club preset should come switched off: {club} (#32)")
    # Dolibarr 24 asks for the session token on a link with an action.
    card_url = f"/compta/facture/card.php?facid={made['merchandise']['id']}"
    token = token_of(page_ok(browser.get(card_url), "invoice card"))
    card = page_ok(browser.get(f"{card_url}&action=edit_extras&attribute=mahnwesen_profile&token={token}"),
                   "invoice card, dunning profile field")
    options = set(re.findall(r'<option value="(\d+)"', card.text.split('name="options_mahnwesen_profile"', 1)[-1].split("</select>", 1)[0]))
    expect({firms, dues, shop, default} <= options and club[0][0] not in options,
           f"the invoice card offers the profiles {sorted(options)}, expected the active ones only (#32)")
    page_ok(browser.submit(form_with_action(card, "update_extras", "invoice card"), {"options_mahnwesen_profile": firms}),
            "choose a profile on the invoice")
    chosen = tab("merchandise")
    expect("Runtime Firmen" in chosen and any(text in chosen for text in translations("MahnwesenProfileReasonChoice")) and "40,00" in chosen,
           "the profile chosen on the invoice does not apply (#32)")
    stack.fixtures["profile_invoices"] = made
    stack.fixtures["profile_ids"] = {"firms": firms, "dues": dues, "shop": shop}
    return "company, dues, merchandise and mixed invoices get their profiles; presets off; the invoice's choice wins"


def interest(stack: Stack) -> str:
    """Interest per profile: per day over a change of the base rate, in letter, email and ledger (#33)."""
    browser = stack.browser()
    setup = "/custom/mahnwesen/admin/setup.php"
    made = stack.fixtures["profile_invoices"]
    merchandise, membership = made["merchandise"], made["membership"]
    firms = stack.fixtures["profile_ids"]["firms"]

    def add_rate(days: int, rate: str) -> str:
        day = container_date(stack, days, "Y-m-d")
        page = page_ok(browser.get(f"{setup}?tab=interest"), "interest setup")
        page_ok(browser.submit(form_with_action(page, "save_interest_rate", "interest setup"),
                               {"rate_from": day, "rate_value": rate, "rate_note": "Runtime"}), f"add base rate {rate}")
        return day

    first, second = add_rate(-60, "3,62"), add_rate(-10, "5,5")
    stored = {row[0]: row[1] for row in stack.sql("SELECT date_from, ROUND(rate, 2) FROM llx_mahnwesen_interest_rate ORDER BY date_from")}
    expect(stored == {first: "3.62", second: "5.50"}, f"the base rates are stored as {stored} (#33)")

    form = page_ok(browser.get(f"{setup}?tab=profiles&profile={firms}&edit=1"), "profile form")
    page_ok(browser.submit(form_with_action(form, "save_profile", "profile form"),
                           {"interest_mode": "base_plus", "interest_rate": "9,2"}), "save the interest rule")

    # What the module must arrive at, counted the same way: per day from the day
    # after the due date, with the base rate of that day plus 9.2 points.
    principal = float(stack.value(f"SELECT ROUND(c.remaining_amount, 2) FROM llx_mahnwesen_case c WHERE c.fk_facture = {merchandise['id']}"))
    due = stack.value(f"SELECT DATE(date_lim_reglement) FROM llx_facture WHERE rowid = {merchandise['id']}")
    today = container_date(stack, 0, "Y-m-d")
    day = datetime.date.fromisoformat(due) + datetime.timedelta(days=1)
    end = datetime.date.fromisoformat(today)
    rates = sorted(((datetime.date.fromisoformat(start), float(value)) for start, value in stored.items()), reverse=True)
    total, days = 0.0, 0
    while day <= end:
        base = next((value for start, value in rates if start <= day), None)
        if base is not None:
            total += principal * ((base + 9.2) / 100) / 365
            days += 1
        day += datetime.timedelta(days=1)
    # The customer of this invoice reads English since the template check, the admin German.
    expected = f"{round(total, 2):.2f}"
    expected_de = expected.replace(".", ",")
    expect(len({next((value for start, value in rates if start <= datetime.date.fromisoformat(due) + datetime.timedelta(days=offset)), None)
                for offset in (1, days)}) == 2,
           f"the test invoice should run over a change of the base rate: {days} days from {due} (#33)")

    tab = html.unescape(re.sub(r"<[^>]+>", " ", page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={merchandise['id']}"), "dunning tab").text))
    expect(expected_de in tab and any(label in tab for label in translations("MahnwesenPartInterest")),
           f"the dunning tab does not show the interest {expected_de}: {tab[:400]!r} (#33)")
    without = html.unescape(re.sub(r"<[^>]+>", " ", page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={membership['id']}"), "dunning tab").text))
    expect(not any(label in without for label in translations("MahnwesenPartInterest")),
           "an invoice whose profile has no interest rule shows interest anyway (#33)")

    # The cron sends the reminder of that profile; letter, email and ledger carry the interest.
    before = stack.value(f"SELECT ROUND(total_ttc, 2) FROM llx_facture WHERE rowid = {merchandise['id']}")
    # The starter template of the reminder names the interest; take exactly that one.
    starter = stack.value("SELECT rowid FROM llx_c_email_templates WHERE module = 'mahnwesen' AND type_template = 'mahnwesen_reminder' "
                          "AND lang = 'de_DE' ORDER BY rowid LIMIT 1")
    expect(starter, "the starter template of the reminder is missing (#33)")
    stack.sql(f"UPDATE llx_mahnwesen_rule SET send_email = 1, email_template = 'native:{starter}' WHERE fk_profile = {firms} AND level = 1 AND entity = 1")
    set_const(stack, "MAHNWESEN_AUTO_SEND_ENABLED", "1")
    mailpit = stack.mailpit()
    mailpit.clear()
    try:
        # Older cases of earlier checks can end this run with warnings; only this notice matters.
        stack.cron(expect_ok=False)
    finally:
        set_const(stack, "MAHNWESEN_AUTO_SEND_ENABLED", "0")
    message = next((m for m in mailpit.messages()
                    if merchandise["ref"] in m["Subject"] and m["To"][0]["Address"] != OPS_ADDRESS), None)
    expect(message is not None, f"the cron sent no reminder for {merchandise['ref']} (#33)")
    full = mailpit.message(message["ID"])
    body = html.unescape((full.get("HTML") or "") + (full.get("Text") or ""))
    expect(expected in body, f"the email does not name the interest {expected} (#33)")
    evidence = stack.value("SELECT f.rowid FROM llx_mahnwesen_attempt_file f JOIN llx_mahnwesen_attempt a ON a.rowid = f.fk_attempt "
                           f"WHERE a.fk_facture = {merchandise['id']} AND f.file_role = 'dunning' ORDER BY f.rowid DESC LIMIT 1")
    check_letter(browser.get(f"/custom/mahnwesen/attempts.php?evidence={evidence}").body, "the reminder with interest", [merchandise["ref"], expected])
    booked = stack.sql("SELECT ROUND(amount, 2), status FROM llx_mahnwesen_fee WHERE kind = 'interest' AND fk_facture = "
                       f"{merchandise['id']} ORDER BY rowid DESC")
    expect(booked and booked[0] == [f"{round(total, 2):.2f}", "open"], f"the ledger holds {booked} as interest (#33)")
    after = stack.value(f"SELECT ROUND(total_ttc, 2) FROM llx_facture WHERE rowid = {merchandise['id']}")
    expect(after == before, f"the interest changed the invoice: {before} -> {after} (#33)")
    return f"interest {expected} over {days} days and a change of the base rate, in tab, email, letter and ledger"


def claim_invoice(stack: Stack) -> str:
    """Open fees and interest become their own draft invoice, settled when it is paid (#34)."""
    browser = stack.browser()
    merchandise = stack.fixtures["profile_invoices"]["merchandise"]
    case = stack.value(f"SELECT rowid FROM llx_mahnwesen_case WHERE fk_facture = {merchandise['id']}")
    open_claims = {row[0]: row[1] for row in stack.sql(f"SELECT kind, ROUND(amount, 2) FROM llx_mahnwesen_fee WHERE fk_case = {case} AND status = 'open'")}
    expect(set(open_claims) == {"fee", "interest"}, f"the case should have an open fee and open interest, it has {open_claims} (#34)")
    total = round(sum(float(value) for value in open_claims.values()), 2)
    before = stack.value(f"SELECT ROUND(total_ttc, 2) FROM llx_facture WHERE rowid = {merchandise['id']}")

    tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={merchandise['id']}"), "dunning tab")
    created = page_ok(browser.submit(form_with_action(tab, "invoice_claims", "dunning tab")), "the new claim invoice")
    claim_id = stack.value(f"SELECT fk_claim_invoice FROM llx_mahnwesen_fee WHERE fk_case = {case} AND status = 'invoiced' LIMIT 1")
    expect(claim_id and f"facid={claim_id}" in created.url, f"the claim invoice {claim_id} did not open: {created.url} (#34)")
    invoiced = stack.sql(f"SELECT kind, ROUND(amount, 2), fk_claim_invoice FROM llx_mahnwesen_fee WHERE fk_case = {case} AND status = 'invoiced' ORDER BY kind")
    expect([row[:2] for row in invoiced] == sorted([[kind, value] for kind, value in open_claims.items()])
           and {row[2] for row in invoiced} == {claim_id},
           f"the claims did not move onto the invoice: {invoiced} (#34)")
    draft = stack.sql(f"SELECT fk_statut, paye, ROUND(total_ttc, 2), fk_soc FROM llx_facture WHERE rowid = {claim_id}")[0]
    lines = stack.sql(f"SELECT ROUND(total_ttc, 2), description FROM llx_facturedet WHERE fk_facture = {claim_id} ORDER BY rowid")
    customer = stack.value(f"SELECT fk_soc FROM llx_facture WHERE rowid = {merchandise['id']}")
    expect(draft[:3] == ["0", "0", f"{total:.2f}"] and draft[3] == customer and len(lines) == len(open_claims)
           and all(merchandise["ref"] in line[1] for line in lines),
           f"the claim invoice should be a draft over {total} for the same customer, it is {draft} with lines {lines} (#34)")
    after = stack.value(f"SELECT ROUND(total_ttc, 2) FROM llx_facture WHERE rowid = {merchandise['id']}")
    expect(after == before, f"the claim invoice changed the original invoice: {before} -> {after} (#34)")
    again = html.unescape(re.sub(r"<[^>]+>", " ", page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={merchandise['id']}"), "dunning tab").text))
    expect('id="mahnwesen-claim-invoice"' not in page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={merchandise['id']}"), "dunning tab").text
           and not any(label in again for label in translations("MahnwesenPartFee")),
           "the tab still asks for fees that are on the claim invoice (#34)")

    # Paying the claim invoice settles the claims, once.
    stack.sql(f"UPDATE llx_facture SET fk_statut = 2, paye = 1 WHERE rowid = {claim_id}")
    stack.cron(expect_ok=False)
    settled = stack.sql(f"SELECT kind, status FROM llx_mahnwesen_fee WHERE fk_case = {case} AND fk_claim_invoice = {claim_id} ORDER BY kind")
    history = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_case = {case} AND action = 'fee_paid'")
    expect(settled == [["fee", "paid"], ["interest", "paid"]] and history == str(len(open_claims)),
           f"after paying the claim invoice the claims are {settled} with {history} entries (#34)")
    stack.cron(expect_ok=False)
    twice = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_case = {case} AND action = 'fee_paid'")
    expect(twice == history, f"a second run counted the claims again: {history} -> {twice} (#34)")
    return f"fees and interest of {total:.2f} on their own draft invoice, settled once it is paid"


def membership(stack: Stack) -> str:
    """A dues invoice is known by Dolibarr's link to its subscription, a sale to the member is not (#58)."""
    browser = stack.browser()
    setup = "/custom/mahnwesen/admin/setup.php"
    data = stack.php_fixture("member")
    dues = data["invoice"]
    merchandise = stack.fixtures["profile_invoices"]["merchandise"]

    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    page_ok(browser.submit(form_with_action(dashboard, "sync_cases", "dashboard")), "synchronise")
    presets = page_ok(browser.get(f"{setup}?tab=profiles"), "profiles setup")
    preset = next((form for form in presets.forms() if form.value("action") == "add_profile_preset" and form.value("preset") == "membership"), None)
    expect(preset is not None, "the setup does not offer the membership preset (#58)")
    page_ok(browser.submit(preset), "add the membership preset")
    profile = stack.sql("SELECT rowid, active, auto_allowed, final_step FROM llx_mahnwesen_profile WHERE code = 'membership'")
    expect(profile and profile[0][1:] == ["0", "0", "membership_review"], f"the membership preset should come switched off: {profile} (#58)")
    profile_id = profile[0][0]
    linked = stack.value(f"SELECT p.code FROM llx_mahnwesen_profile_match m JOIN llx_mahnwesen_profile p ON p.rowid = m.fk_profile WHERE m.kind = 'membership'")
    expect(linked == "membership", f"the preset is not tied to membership fee invoices: {linked} (#58)")

    # While it is switched off, the dues invoice keeps the ordinary profile.
    def profile_of(invoice_id: str) -> str:
        page = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={invoice_id}"), "dunning tab")
        row = re.search(r'id="mahnwesen-profile">(.*?)</td>', page.text, re.S)
        expect(row is not None, "the dunning tab names no profile (#32)")
        return html.unescape(re.sub(r"<[^>]+>", " ", row.group(1)))

    before = profile_of(dues["id"])
    expect("Mitgliedsbeitrag" not in before, f"a switched-off profile already applies: {before} (#58)")
    form = page_ok(browser.get(f"{setup}?tab=profiles&profile={profile_id}&edit=1"), "profile form")
    page_ok(browser.submit(form_with_action(form, "save_profile", "profile form"), {"profile_active": "1"}), "switch the profile on")

    after = profile_of(dues["id"])
    expect("Mitgliedsbeitrag" in after and any(text in after for text in translations("MahnwesenProfileReasonMembership")),
           f"the dues invoice does not get the membership profile: {after} (#58)")
    sale = profile_of(merchandise["id"])
    expect("Mitgliedsbeitrag" not in sale, f"a sale to the same member got the membership profile: {sale} (#58)")
    member_tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={dues['id']}"), "dunning tab")
    expect(f"adherents/card.php?rowid={data['member']}" in member_tab.text,
           "the dunning tab of a dues invoice does not link to the membership (#58)")

    # A mixed invoice: the dues link and a merchandise line, the most careful profile wins.
    shop_category = stack.fixtures["profile_ids"]["shop"]
    mixed = stack.fixtures["profile_invoices"]["mixed"]
    stack.sql(f"INSERT INTO llx_element_element (fk_source, sourcetype, fk_target, targettype) VALUES "
              f"({data['subscription']}, 'subscription', {mixed['id']}, 'facture')")
    both = profile_of(mixed["id"])
    expect("Mitgliedsbeitrag" in both and any(text in both for text in translations("MahnwesenProfileMostCareful")),
           f"a mixed invoice does not take the most careful profile: {both} (#58)")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_profile WHERE rowid = {shop_category}") == "1", "the merchandise profile disappeared")
    return "dues invoice by its subscription link, sale to the member untouched, mixed invoice most careful"


def payment_trigger(stack: Stack) -> str:
    """A payment and a correction re-evaluate the case at once, without the daily run (#36)."""
    browser = stack.browser()
    invoice_row = stack.php_fixture("payment")["invoice"]
    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    page_ok(browser.submit(form_with_action(dashboard, "sync_cases", "dashboard")), "synchronise")
    case = stack.value(f"SELECT rowid FROM llx_mahnwesen_case WHERE fk_facture = {invoice_row['id']}")
    expect(stack.value(f"SELECT ROUND(remaining_amount, 2) FROM llx_mahnwesen_case WHERE rowid = {case}") == "48.00",
           "the case of the new invoice does not hold its open amount (#36)")

    # 10 of 48 paid: the case follows at once, nothing else runs.
    stack.php_fixture("pay", str(invoice_row["id"]), "10")
    after_part = stack.sql(f"SELECT ROUND(remaining_amount, 2), status FROM llx_mahnwesen_case WHERE rowid = {case}")[0]
    expect(after_part == ["38.00", "open"], f"after a partial payment the case holds {after_part} (#36)")

    # The rest: the case is done, without the daily run.
    stack.php_fixture("pay", str(invoice_row["id"]), "38")
    after_full = stack.sql(f"SELECT ROUND(remaining_amount, 2), status FROM llx_mahnwesen_case WHERE rowid = {case}")[0]
    paid = stack.value(f"SELECT paye FROM llx_facture WHERE rowid = {invoice_row['id']}")
    expect(after_full[1] in ("closed", "fee_open") and paid == "1",
           f"after full payment the case is {after_full} while the invoice is paid={paid} (#36)")
    history = stack.sql(f"SELECT action FROM llx_mahnwesen_history WHERE fk_case = {case} ORDER BY rowid")
    expect(any(row[0] == "case_closed" for row in history), f"the history of the paid case is {history} (#36)")

    # A cancelled payment reopens the case, without repeating notices.
    payment = stack.value(f"SELECT fk_paiement FROM llx_paiement_facture WHERE fk_facture = {invoice_row['id']} ORDER BY rowid DESC LIMIT 1")
    before_notices = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_case = {case} AND action = 'notice_sent'")
    stack.php_fixture("unpay", str(payment))
    noted = stack.value(f"SELECT recheck FROM llx_mahnwesen_case WHERE rowid = {case}")
    expect(noted == "1", "the case of a removed payment was not noted for re-evaluation (#36)")
    page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    reopened = stack.sql(f"SELECT ROUND(remaining_amount, 2), status FROM llx_mahnwesen_case WHERE rowid = {case}")[0]
    after_notices = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_case = {case} AND action = 'notice_sent'")
    expect(reopened[1] == "open" and float(reopened[0]) > 0 and after_notices == before_notices,
           f"after the payment was cancelled the case is {reopened} with {after_notices} notices instead of {before_notices} (#36)")
    return "payment, full payment and cancellation reach the case at once, without the daily run"


def payment_ways(stack: Stack) -> str:
    """The letter carries the QR code for the invoice amount and says what it covers (#35)."""
    browser = stack.browser()
    # A fresh overdue invoice of the company, so a notice is actually due; the
    # bank account comes after it, so the invoice names it as Dolibarr 21 needs.
    company = stack.php_fixture("payment")["invoice"]
    stack.php_fixture("bank")
    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    page_ok(browser.submit(form_with_action(dashboard, "sync_cases", "dashboard")), "synchronise")
    contact = str(stack.fixtures["contacts"]["billing"])

    def letter() -> bytes:
        composer = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer")
        shown = page_ok(browser.submit(composer.form(name="mailform"),
                                       {"action": "generate_preview", "receiver[]": contact}), "generate preview")
        section = shown.text.find('class="mahnwesen-document-preview"')
        match = re.search(r'<iframe[^>]+src="([^"#]+)', shown.text[section:]) if section >= 0 else None
        expect(match is not None, "the composer shows no preview of the letter (#35)")
        pdf = browser.get(html.unescape(match.group(1)))
        expect(pdf.body.startswith(b"%PDF"), "the preview is no PDF (#35)")
        return pdf.body

    with_qr = letter()
    text = pdf_text(with_qr)
    title = translations("MahnwesenPaymentQrTitle")
    # This case carries a fee, so the code must say that it covers the invoice amount only.
    only = [line.split("%s")[0].strip() for line in translations("MahnwesenPaymentCoversInvoiceOnly")]
    expect(any(label in text for label in title) and any(part and part in text for part in only),
           f"the letter does not name the QR code and what it covers: {text[:300]!r} (#35)")
    expect(b"/Image" in with_qr or b"/XObject" in with_qr, "the letter carries no QR code image (#35)")

    set_const(stack, "MAHNWESEN_LETTER_QR", "0")
    try:
        without = pdf_text(letter())
        expect(not any(label in without for label in title), "the letter still shows the QR code although it is switched off (#35)")
    finally:
        set_const(stack, "MAHNWESEN_LETTER_QR", "1")

    # Without an online payment provider the email says nothing about paying online.
    composer = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer")
    body = html.unescape(composer.text)
    link = [line.split("%s")[0].strip() for line in translations("MahnwesenPaymentLinkParagraph")]
    expect(not any(part and part in body for part in link),
           "the email offers online payment although no provider is switched on (#35)")
    return "QR code for the invoice amount with its scope named, switchable, and no invented online payment"


def events(stack: Stack) -> str:
    """Every change of a case is noted with the change and reported afterwards (#59)."""
    browser = stack.browser()
    company = invoice(stack, "company_overdue")
    case = stack.value(f"SELECT rowid FROM llx_mahnwesen_case WHERE fk_facture = {company['id']}")
    sent = stack.sql(f"SELECT event_type, status, case_revision FROM llx_mahnwesen_event WHERE fk_case = {case} "
                     "AND event_type = 'MAHNWESEN_NOTICE_SENT' ORDER BY rowid")
    expect(sent and all(row[1] == "delivered" for row in sent) and all(int(row[2]) > 0 for row in sent),
           f"the sent notices of the case have no delivered events: {sent} (#59)")
    forbidden = stack.sql("SELECT event_id FROM llx_mahnwesen_event WHERE last_error LIKE '%@%' OR profile_code LIKE '%@%'")
    expect(not forbidden, f"an event carries an email address: {forbidden} (#59)")

    # Pause, resume and close each report once, and repeat nothing.
    def types() -> list:
        return [row[0] for row in stack.sql(f"SELECT event_type FROM llx_mahnwesen_event WHERE fk_case = {case} ORDER BY rowid")]

    before = types()
    tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={company['id']}&edit=pause"), "dunning tab, pause form")
    page_ok(browser.submit(form_with_action(tab, "pause_case", "dunning tab"), {"pause_reason": "Runtime events"}), "pause the case")
    tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={company['id']}"), "dunning tab")
    page_ok(browser.submit(form_with_action(tab, "resume_case", "dunning tab")), "resume the case")
    after = types()
    expect(after[len(before):] == ["MAHNWESEN_CASE_PAUSED", "MAHNWESEN_CASE_RESUMED"],
           f"pause and resume reported {after[len(before):]} (#59)")
    ids = stack.sql(f"SELECT event_id FROM llx_mahnwesen_event WHERE fk_case = {case}")
    expect(len({row[0] for row in ids}) == len(ids), f"the same transition was noted twice: {ids} (#59)")

    # The backlog is visible, and a second run repeats nothing.
    page = page_ok(browser.get("/custom/mahnwesen/attempts.php"), "delivery attempts")
    expect('data-event="MAHNWESEN_CASE_PAUSED"' in page.text, "the page does not show the events (#59)")
    stack.cron(expect_ok=False)
    expect(types() == after, "a run created events again (#59)")
    waiting = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_event WHERE fk_case = {case} AND status = 'pending'")
    expect(waiting == "0", f"{waiting} events of the case are still waiting (#59)")
    return f"{len(after)} events of one case, each once, all delivered and visible"


def api(stack: Stack) -> str:
    """The status API answers for its own customers only, and keeps the operations view apart (#57)."""
    data = stack.php_fixture("api")
    keys = data["keys"]
    company = invoice(stack, "company_overdue")
    private = invoice(stack, "private_overdue")
    browser = stack.browser()

    def call(role: str, path: str) -> tuple:
        page = browser.get(f"/api/index.php{path}", follow=False)
        return page.status, page.text

    def request(role: str, path: str) -> tuple:
        import urllib.request
        url = f"{stack.url}/api/index.php{path}"
        req = urllib.request.Request(url, headers={"DOLAPIKEY": keys[role], "Accept": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=30) as answer:
                return answer.status, json.loads(answer.read().decode("utf-8"))
        except urllib.error.HTTPError as error:
            body = error.read().decode("utf-8", errors="replace")
            try:
                return error.code, json.loads(body)
            except ValueError:
                return error.code, body

    status, own = request("portal", f"/mahnwesen/invoices/{company['id']}")
    expect(status == 200 and own.get("invoice_ref") == company["ref"] and own.get("thirdparty_id"),
           f"the portal user does not read its own customer's invoice: {status} {own} (#57)")
    expect(set(own) >= {"case_revision", "invoice_open", "fee_open", "interest_open", "claims_on_own_invoice", "currency", "profile_code"},
           f"the answer lacks fields of the contract: {sorted(own)} (#57)")
    forbidden = {"note_private", "body_html", "recipient", "bcc", "subject", "message"}
    expect(not (set(own) & forbidden), f"the answer carries internal fields: {sorted(set(own) & forbidden)} (#57)")

    status, foreign = request("portal", f"/mahnwesen/invoices/{private['id']}")
    expect(status == 404, f"the portal user reads an invoice of another customer: {status} {foreign} (#57)")
    status, missing = request("portal", "/mahnwesen/invoices/999999")
    expect(status == 404, f"an unknown invoice answers {status} instead of 404 (#57)")

    status, denied = request("portal", "/mahnwesen/status")
    expect(status == 401, f"the portal user reaches the operations view: {status} {denied} (#57)")
    status, operations = request("operations", "/mahnwesen/status")
    expect(status == 200 and "automatic_sending" in operations and "open_cases" in operations,
           f"the operations user does not read the state of the entity: {status} {operations} (#57)")
    # The archived letters of that invoice: only what really went out (#69).
    status, documents = request("portal", f"/mahnwesen/invoices/{company['id']}/documents")
    expect(status == 200 and documents.get("documents"), f"the sent letters of the invoice are {status} {documents} (#69)")
    first = documents["documents"][0]
    expect({"document_id", "attempt_id", "stage", "sent_at", "filename", "sha256", "size_bytes"} <= set(first),
           f"a letter lacks fields of the contract: {sorted(first)} (#69)")
    status, document = request("portal", f"/mahnwesen/documents/{first['document_id']}")
    expect(status == 200 and document.get("encoding") == "base64" and document.get("sha256") == first["sha256"]
           and document.get("sha256_now") == first["sha256"],
           f"the archived letter does not match its recorded hash: {status} {str(document)[:200]} (#69)")
    archived = base64.b64decode(document["content"])
    expect(archived.startswith(b"%PDF") and hashlib.sha256(archived).hexdigest() == first["sha256"],
           "the bytes handed out are not the archived ones (#69)")
    status, foreign_document = request("portal", "/mahnwesen/documents/999999")
    expect(status == 404, f"an unknown document answers {status} instead of 404 (#69)")

    status, listed = request("portal", f"/mahnwesen/thirdparties/{own['thirdparty_id']}?limit=2")
    expect(status == 200 and listed.get("limit") == 2 and listed.get("cases"),
           f"the list of a customer's cases is {status} {listed} (#57)")
    expect(all(case["thirdparty_id"] == own["thirdparty_id"] for case in listed["cases"]),
           "the list carries cases of another customer (#57)")

    # Reading changes nothing.
    before = stack.sql(f"SELECT status, current_level, revision FROM llx_mahnwesen_case WHERE fk_facture = {company['id']}")
    request("operations", f"/mahnwesen/invoices/{company['id']}")
    after = stack.sql(f"SELECT status, current_level, revision FROM llx_mahnwesen_case WHERE fk_facture = {company['id']}")
    expect(before == after, f"reading the API changed the case: {before} -> {after} (#57)")
    return "own customer readable, foreign and unknown alike 404, operations view separate, reading changes nothing"


def customer_tab(stack: Stack) -> str:
    """The customer tab shows their cases only, and the box counts as the dashboard does (#41)."""
    browser = stack.browser()
    company = invoice(stack, "company_overdue")
    private = invoice(stack, "private_overdue")
    customer = stack.value(f"SELECT fk_soc FROM llx_facture WHERE rowid = {company['id']}")
    card = page_ok(browser.get(f"/societe/card.php?socid={customer}"), "customer card")
    expect(f"mahnwesen/customer.php?socid={customer}" in card.text, "the customer card has no dunning tab (#41)")

    tab = page_ok(browser.get(f"/custom/mahnwesen/customer.php?socid={customer}"), "customer dunning tab")
    refs = set(re.findall(r'<tr class="oddeven" data-case="\d+">\s*<td[^>]*><a [^>]*>([^<]+)</a>', tab.text))
    expected = {row[0] for row in stack.sql("SELECT f.ref FROM llx_mahnwesen_case c JOIN llx_facture f ON f.rowid = c.fk_facture "
                                            f"WHERE f.fk_soc = {customer}")}
    expect(refs == expected and private["ref"] not in tab.text,
           f"the customer tab shows {refs}, expected {expected} and nothing of another customer (#41)")

    # A sales representative of another customer does not reach it.
    other = stack.browser("rtother")
    expect(other.get(f"/custom/mahnwesen/customer.php?socid={customer}").denied(),
           "a user outside the customer scope opens the customer tab (#41)")

    # The box counts what the dashboard counts.
    home = page_ok(browser.get("/index.php"), "home page")
    titles = translations("MahnwesenBoxTitle")
    expect(any(title in html.unescape(home.text) for title in titles), "the home page does not show the dunning box (#41)")
    due_box = re.search(r"(" + "|".join(re.escape(label) for label in translations("MahnwesenBoxDue")) + r")\s*</td>\s*<td[^>]*>\s*(\d+)",
                        html.unescape(home.text))
    expect(due_box is not None, "the dunning box does not count the steps due (#41)")
    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php?search_status=due&limit=100"), "dashboard, due cases")
    listed = len(re.findall(r'<tr class="oddeven" data-case="\d+">', dashboard.text))
    expect(int(due_box.group(2)) == listed, f"the box counts {due_box.group(2)} due, the dashboard lists {listed} (#41)")
    return f"customer tab with {len(refs)} cases, closed to others, box and dashboard agree on {listed} due"


def stats(stack: Stack) -> str:
    """The figures match what the stored cases and the ledger hold (#43)."""
    browser = stack.browser()
    page = page_ok(browser.get("/custom/mahnwesen/stats.php"), "figures")
    shown = {int(match.group(1)): (int(match.group(2)), int(match.group(3)))
             for match in re.finditer(r'data-stage="(\d)"><td>[^<]*</td>\s*<td class="right">(\d+)</td>\s*<td class="right">[^<]*</td>\s*'
                                      r'<td class="right" data-paid-share="(\d+)"', page.text)}
    expect(len(shown) == 4, f"the figures do not show the four stages: {shown} (#43)")
    for level in (1, 2, 3, 4):
        notices = stack.value("SELECT COUNT(*) FROM llx_mahnwesen_history WHERE action = 'notice_sent' AND result = 'success' "
                              f"AND level = {level} AND entity = 1")
        expect(shown[level][0] == int(notices), f"stage {level} shows {shown[level][0]} notices, the history holds {notices} (#43)")
    fee = re.search(r'data-open-fee="([0-9.]+)"', page.text)
    interest = re.search(r'data-open-interest="([0-9.]+)"', page.text)
    expect(fee is not None and interest is not None, "the figures do not show the open claims (#43)")
    for kind, match in (("fee", fee), ("interest", interest)):
        stored = stack.value(f"SELECT ROUND(COALESCE(SUM(amount), 0), 2) FROM llx_mahnwesen_fee WHERE kind = '{kind}' AND status = 'open' AND entity = 1")
        expect(abs(float(match.group(1)) - float(stored)) < 0.01,
               f"the open {kind} shows {match.group(1)}, the ledger holds {stored} (#43)")
    customers = len(re.findall(r'id="mahnwesen-stats-customers".*?</table>', page.text, re.S))
    expect(customers == 1 and 'id="mahnwesen-stats-profiles"' in page.text, "the figures lack the customer or profile table (#43)")
    sales = stack.browser("rtsales")
    limited = page_ok(sales.get("/custom/mahnwesen/stats.php"), "figures for the sales representative")
    expect(invoice(stack, "private_overdue")["ref"] not in limited.text and "Rita" not in limited.text,
           "the figures show another customer to a sales representative (#43)")
    return "figures per stage, claims, customers and profiles agree with the stored data"


def retention(stack: Stack) -> str:
    """After the retention the texts and copies are gone, the evidence stays (#42)."""
    company = invoice(stack, "company_overdue")
    attempt = stack.sql("SELECT rowid, recipient, subject, LENGTH(body_html) FROM llx_mahnwesen_attempt WHERE fk_facture = "
                        f"{company['id']} AND status = 'sent' AND body_html <> '' ORDER BY rowid DESC LIMIT 1")
    expect(attempt, "there is no sent notice with a stored text (#42)")
    attempt_id, recipient, subject, length = attempt[0]
    expect(int(length) > 0, "the stored notice has no text (#42)")
    files = stack.sql(f"SELECT rowid, snapshot_path, sha256, size_bytes FROM llx_mahnwesen_attempt_file WHERE fk_attempt = {attempt_id}")
    expect(files, "the sent notice has no archived copy (#42)")

    # Without a retention the daily run keeps everything.
    stack.cron(expect_ok=False)
    kept = stack.value(f"SELECT LENGTH(body_html) FROM llx_mahnwesen_attempt WHERE rowid = {attempt_id}")
    expect(kept == length, f"without a retention the text was deleted anyway: {length} -> {kept} (#42)")

    # With one day, everything older than that goes.
    stack.sql(f"UPDATE llx_mahnwesen_attempt SET reserved_at = '{container_date(stack, -30, 'Y-m-d')} 08:00:00' WHERE rowid = {attempt_id}")
    set_const(stack, "MAHNWESEN_RETENTION_DAYS", "1")
    try:
        stack.cron(expect_ok=False)
    finally:
        set_const(stack, "MAHNWESEN_RETENTION_DAYS", "0")
    after = stack.sql(f"SELECT LENGTH(body_html), recipient, subject FROM llx_mahnwesen_attempt WHERE rowid = {attempt_id}")[0]
    expect(after == ["0", recipient, subject], f"the retention changed more than the text: {after} (#42)")
    remaining = stack.sql(f"SELECT snapshot_path, sha256, size_bytes FROM llx_mahnwesen_attempt_file WHERE fk_attempt = {attempt_id}")
    expect(all(row[0] == "" for row in remaining), f"a copy still has its path: {remaining} (#42)")
    expect([row[1:] for row in remaining] == [row[2:] for row in files],
           f"the retention changed the evidence: {remaining} instead of {files} (#42)")
    for row in files:
        gone = stack.shell(f"test -e '{row[1]}'")
        expect(gone.returncode != 0, f"the copy {row[1]} is still on disk (#42)")
    return "text and copies deleted after the retention, date, recipient, size and checksum kept"


def handover(stack: Stack) -> str:
    """A handed over case is dunned no more and its file lies in the document store (#40)."""
    browser = stack.browser()
    company = invoice(stack, "company_overdue")
    case = stack.value(f"SELECT rowid FROM llx_mahnwesen_case WHERE fk_facture = {company['id']}")
    stack.sql(f"UPDATE llx_mahnwesen_case SET status = 'open' WHERE rowid = {case}")
    evidence = {row[0]: row[1] for row in stack.sql("SELECT f.display_name, f.sha256 FROM llx_mahnwesen_attempt_file f "
                                                    f"JOIN llx_mahnwesen_attempt a ON a.rowid = f.fk_attempt WHERE a.fk_facture = {company['id']} "
                                                    "AND a.status = 'sent' AND f.file_role = 'dunning' AND f.snapshot_path <> ''")}
    tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={company['id']}"), "dunning tab")
    expect('id="mahnwesen-handover"' in tab.text, "the dunning tab does not offer the handover (#40)")
    ask = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={company['id']}&action=ask_handover&token={token_of(tab)}"), "handover question")
    page_ok(browser.submit(form_with_action(ask, "confirm_handover", "handover question"),
                           {"confirm": "yes", "handover_reason": "Runtime: Inkassobuero Muster"}), "hand the case over")
    state = stack.sql(f"SELECT status, next_action_at FROM llx_mahnwesen_case WHERE rowid = {case}")[0]
    expect(state[0] == "handed_over" and state[1] in (None, "", "NULL"), f"the case is {state} after the handover (#40)")
    history = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_case = {case} AND action = 'case_handed_over'")
    expect(history == "1", f"the handover is {history} times in the history (#40)")

    # The automation leaves it alone from now on.
    result = page_ok(browser.submit(form_with_action(page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard"), "dry_run", "dashboard")), "dry run")
    for row in result.text.split('<tr class="oddeven">'):
        if company["ref"] in row:
            expect('data-decision="send"' not in row, f"the dry run still wants to send for a handed over case: {row[:200]} (#40)")

    # The file lies in Dolibarr's document store, with the checksums of the delivery.
    folder = f"/var/www/documents/ecm/mahnwesen/{company['ref']}"
    listed = stack.files(folder)
    expect(any(name.endswith("_Mahnakte.txt") for name in listed), f"the case file has no summary: {listed} (#40)")
    summary = container_file(stack, f"{folder}/{company['ref']}_Mahnakte.txt").decode("utf-8", errors="replace")
    expect("Runtime: Inkassobuero Muster" in summary, "the summary does not name the reason of the handover (#40)")
    for name, sha in evidence.items():
        expect(name in listed, f"the dunning letter {name} is missing in the case file: {listed} (#40)")
        expect(sha in summary, f"the summary does not carry the checksum of {name} (#40)")
        copied = hashlib.sha256(container_file(stack, f"{folder}/{name}")).hexdigest()
        expect(copied == sha, f"the copy of {name} differs from the delivery: {copied} instead of {sha} (#40)")
    indexed = stack.value(f"SELECT COUNT(*) FROM llx_ecm_files WHERE filepath = 'ecm/mahnwesen/{company['ref']}'")
    expect(int(indexed) >= len(evidence) + 1, f"the document store indexed {indexed} files of the case (#40)")
    return f"case handed over, automation silent, {len(listed)} files in the document store with matching checksums"


def bulk(stack: Stack) -> str:
    """Several cases at once: emails with the usual checks, letters for customers without one (#39)."""
    browser = stack.browser()
    made = stack.php_fixture("payment")["invoice"]
    postal = stack.php_fixture("postal")["invoice"]
    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    page_ok(browser.submit(form_with_action(dashboard, "sync_cases", "dashboard")), "synchronise")

    expect('name="case_invoice[]"' in page_ok(browser.get("/custom/mahnwesen/index.php?limit=100"), "dashboard").text,
           "the list has no selection boxes (#39)")

    mailpit = stack.mailpit()
    mailpit.clear()
    sent = page_ok(browser.post("/custom/mahnwesen/index.php", [("token", token_of(page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard"))),
                                                                ("action", "bulk_send"), ("case_invoice[]", str(made["id"])),
                                                                ("case_invoice[]", str(postal["id"]))]), "bulk send")
    mails = [m for m in mailpit.messages() if made["ref"] in m["Subject"]]
    expect(mails, f"the bulk send did not reach {made['ref']} (#39)")
    expect(not [m for m in mailpit.messages() if postal["ref"] in m["Subject"]],
           f"a customer without an email address got a mail for {postal['ref']} (#39)")

    # The letters of customers without an email address, as one PDF.
    before = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_facture = {postal['id']} AND action = 'notice_sent'")
    batch = browser.post("/custom/mahnwesen/index.php", [("token", token_of(page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard"))),
                                                         ("action", "bulk_letters"), ("case_invoice[]", str(postal["id"])),
                                                         ("case_invoice[]", str(made["id"]))])
    expect(batch.status == 200 and batch.body.startswith(b"%PDF"),
           f"the letter batch is no PDF: HTTP {batch.status}, it starts with {batch.body[:200]!r} (#39)")
    expect(postal["ref"] in pdf_text(batch.body), f"the batch does not hold the letter of {postal['ref']} (#39)")
    expect(made["ref"] not in pdf_text(batch.body), f"the batch holds a letter of a customer with an email address (#39)")
    after = stack.sql(f"SELECT mode, status FROM llx_mahnwesen_attempt WHERE fk_facture = {postal['id']} ORDER BY rowid DESC LIMIT 1")[0]
    expect(after == ["postal", "sent"], f"the printed letter was recorded as {after} (#39)")
    history = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_facture = {postal['id']} AND action = 'notice_sent'")
    expect(int(history) == int(before) + 1, f"the postal delivery is {before} -> {history} times in the history (#39)")
    return "bulk send by email, letters of customers without one as one PDF, recorded as postal"


def layouts(stack: Stack) -> str:
    """Both layouts show invoice, amounts and address, the chosen one builds the letter (#76)."""
    browser = stack.browser()
    company = stack.php_fixture("payment")["invoice"]
    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    page_ok(browser.submit(form_with_action(dashboard, "sync_cases", "dashboard")), "synchronise")
    contact = str(stack.fixtures["contacts"]["billing"])
    setup = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=general"), "general setup")
    offered = set(re.findall(r'<option value="([a-z0-9]+)"[^>]*>', setup.text.split('name="letter_layout"', 1)[-1].split("</select>", 1)[0]))
    expect({"standard", "letter"} <= offered, f"the setup offers the layouts {sorted(offered)} (#76)")

    def letter(layout: str) -> str:
        form = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=general"), "general setup")
        page_ok(browser.submit(form_with_action(form, "save_general", "general setup"), {"letter_layout": layout}), f"choose {layout}")
        expect(stack.const("MAHNWESEN_ADDON_PDF") == layout, f"the setup did not store the layout {layout} (#76)")
        composer = page_ok(browser.get(f"/custom/mahnwesen/notice.php?id={company['id']}"), "composer")
        shown = page_ok(browser.submit(composer.form(name="mailform"), {"action": "generate_preview", "receiver[]": contact}), "preview")
        section = shown.text.find('class="mahnwesen-document-preview"')
        match = re.search(r'<iframe[^>]+src="([^"#]+)', shown.text[section:]) if section >= 0 else None
        expect(match is not None, f"no preview with the layout {layout} (#76)")
        pdf = browser.get(html.unescape(match.group(1))).body
        expect(pdf.startswith(b"%PDF"), f"the preview with the layout {layout} is no PDF (#76)")
        return pdf_text(pdf)

    try:
        table, plain = letter("standard"), letter("letter")
    finally:
        set_const(stack, "MAHNWESEN_ADDON_PDF", "standard")
    for name, text in (("standard", table), ("letter", plain)):
        missing = [part for part in (company["ref"], "48,00", "Kundenweg 7") if part not in text]
        expect(not missing, f"the layout {name} lacks {missing} (#76)")
    total = translations("MahnwesenLetterTotal")[0]
    expect(total in plain and total not in table, "the two layouts are not different (#76)")
    return "setup offers both layouts; each shows invoice, amount and address, and they differ"


def collective(stack: Stack) -> str:
    """One customer with several due invoices gets one email with one letter listing them (#38)."""
    browser = stack.browser()
    made = stack.php_fixture("collective")
    invoices = made["invoices"]
    dashboard = page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard")
    page_ok(browser.submit(form_with_action(dashboard, "sync_cases", "dashboard")), "synchronise")
    form = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=general"), "general setup")
    page_ok(browser.submit(form_with_action(form, "save_general", "general setup"), {"collective_letters": "1"}), "switch on collective letters")
    expect(stack.const("MAHNWESEN_COLLECTIVE_LETTERS") == "1", "the setup did not store the collective letter switch (#38)")
    mailpit = stack.mailpit()
    mailpit.clear()
    try:
        token = token_of(page_ok(browser.get("/custom/mahnwesen/index.php"), "dashboard"))
        page_ok(browser.post("/custom/mahnwesen/index.php", [("token", token), ("action", "bulk_send")]
                             + [("case_invoice[]", str(invoice["id"])) for invoice in invoices]), "bulk send")
    finally:
        set_const(stack, "MAHNWESEN_COLLECTIVE_LETTERS", "0")
    mails = [m for m in mailpit.messages() if any(to["Address"] == made["email"] for to in m["To"])]
    expect(len(mails) == 1, f"the customer got {len(mails)} mails instead of one collective letter (#38)")
    files = mailpit.attachments(mails[0]["ID"])
    expect(len(files) == 1, f"the collective mail carries {sorted(files)} instead of one letter (#38)")
    letter = pdf_text(next(iter(files.values())))
    missing = [invoice["ref"] for invoice in invoices if invoice["ref"] not in letter]
    expect(not missing, f"the collective letter does not list {missing} (#38)")
    for invoice in invoices:
        attempt = stack.sql(f"SELECT mode, status FROM llx_mahnwesen_attempt WHERE fk_facture = {invoice['id']} ORDER BY rowid DESC LIMIT 1")
        expect(attempt and attempt[0] == ["manual", "sent"], f"the attempt of {invoice['ref']} is {attempt} (#38)")
        sent = stack.value(f"SELECT COUNT(*) FROM llx_mahnwesen_history WHERE fk_facture = {invoice['id']} AND action = 'notice_sent' AND result = 'success'")
        expect(int(sent) == 1, f"{invoice['ref']} is {sent} times sent in its history (#38)")
    return "three due invoices of one customer: one mail, one letter listing all, each recorded as sent"


SCENARIOS = (
    ("upgrade", "An installation of the previous release upgrades to this package", upgrade, ()),
    ("deploy", "The package deploys through Deploy an external module", deploy, ("upgrade",)),
    ("enable", "Enabling from the module list", enable, ("deploy",)),
    ("install", "Module, tables, cron job and templates after activation", install, ("enable",)),
    ("synchronise", "Synchronise creates the expected cases", synchronise, ("install",)),
    ("case-list", "The dashboard lists the stored cases", case_list, ("synchronise",)),
    ("pages", "Every page and integration point renders", pages, ("synchronise",)),
    ("access", "Sales representatives only reach their customers", access, ("synchronise",)),
    ("invoice-view", "One dunning action on the invoice card, plain wording", invoice_view, ("pages",)),
    ("preview", "The preview PDF opens for permitted users only", preview, ("pages",)),
    ("failure-reason", "A refused action says why", failure_reason, ("pages",)),
    ("payment-deadline", "A stage's payment period reaches the templates", payment_deadline, ("pages",)),
    ("manual-send", "The composer sends one reminder with verified attachments", manual_send, ("payment-deadline",)),
    ("attachment-choice", "Renamed invoice PDF found, unticked PDF not sent", attachment_choice, ("pages",)),
    ("pause-label", "Pause labels are right and closing ends the pause", pause_label, ("synchronise",)),
    ("documents", "One dunning PDF per stage in the invoice documents", documents, ("pages",)),
    ("automation", "The cron sends automatically only when switched on, once", automation,
     ("manual-send", "attachment-choice")),
    ("reactivation", "Re-activation keeps manual sending and data, stops automation", reactivation, ("automation",)),
    ("templates", "Own templates first, fixed choice, starters by language", templates, ("reactivation",)),
    ("stage-spacing", "The next stage is due at the start of the day", stage_spacing, ("automation",)),
    ("open-attempt", "Open attempts block skipping; late confirmations keep higher fees", open_attempt, ("automation",)),
    ("smtp-outage", "A mail server outage fails retryably up to the retry limit", smtp_outage, ("stage-spacing",)),
    ("broken-invoice", "One broken invoice does not stop automatic dunning", broken_invoice, ("smtp-outage",)),
    ("dry-run", "The dry run decides exactly as the cron", dry_run, ("broken-invoice",)),
    ("billing-role", "The customer's default billing contact as recipient", billing_role, ("dry-run",)),
    ("dunning-block", "A dunning block stops notices until its last day", dunning_block, ("billing-role",)),
    ("profiles", "Each kind of claim gets its dunning profile", profiles, ("dunning-block",)),
    ("interest", "Late-payment interest per profile, to the cent", interest, ("profiles",)),
    ("claim-invoice", "Open fees and interest as their own invoice", claim_invoice, ("interest",)),
    ("membership", "A dues invoice is known by its subscription", membership, ("claim-invoice",)),
    ("payment-trigger", "A payment reaches the case at once", payment_trigger, ("synchronise",)),
    ("payment-ways", "The letter offers a way to pay", payment_ways, ("interest",)),
    ("events", "Dunning changes are reported to other modules", events, ("dry-run",)),
    ("api", "The status API answers within its limits", api, ("synchronise",)),
    ("customer-tab", "The customer tab and the home page box", customer_tab, ("synchronise",)),
    ("stats", "The dunning figures agree with the data", stats, ("dry-run",)),
    ("retention", "Mail texts and copies go after the retention", retention, ("stats",)),
    ("handover", "A handed over case ends the automation", handover, ("retention",)),
    ("bulk", "Several cases at once, by email and on paper", bulk, ("handover",)),
    ("layouts", "The chosen layout builds the letter", layouts, ("bulk",)),
    ("collective", "One letter per customer for several due invoices", collective, ("layouts",)),
)


def wait_http(url: str, seconds: int) -> None:
    import urllib.error
    import urllib.request
    deadline = time.time() + seconds
    last = ""
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(url, timeout=5) as response:
                if response.status == 200:
                    return
        except urllib.error.HTTPError as error:
            last = f"HTTP {error.code}"
        except OSError as error:
            last = str(error)
        time.sleep(2)
    raise CheckFailed(f"{url} did not answer within {seconds} s ({last})")


def parse_fixtures(output: str) -> dict:
    start = output.find("{")
    if start < 0:
        raise CheckFailed(f"fixtures.php printed no JSON:\n{output[-1500:]}")
    return json.loads(output[start:])
