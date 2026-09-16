"""What the runtime checks prove in a running Dolibarr.

scripts/local_check.py starts one Dolibarr per supported version with MariaDB
and Mailpit, runs fixtures.php, and then calls the scenarios below in order.
Each scenario raises CheckFailed with what went wrong and returns a one-line
result. They only use what a user and the cron use: the web pages, the
Dolibarr cron runner, the mail server and the database.
"""

from __future__ import annotations

import datetime
import hashlib
import html
import json
import re
import subprocess
import time
import zlib
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

from dolibarr_http import Browser, Mailpit, Page, token_of

MODULE_TABLES = ("mahnwesen_case", "mahnwesen_history", "mahnwesen_rule", "mahnwesen_attempt",
                 "mahnwesen_attempt_file", "mahnwesen_fee", "mahnwesen_pause", "mahnwesen_run")
LANGS = Path(__file__).resolve().parents[2] / "langs"
PHP_PROBLEM = re.compile(r"PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error):"
                         r"\s*(.+?) in (/var/www/html/custom/mahnwesen/\S+) on line \d+")


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
    fixtures: dict = field(default_factory=dict)
    notes: dict = field(default_factory=dict)

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.web_port}"

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

    def cron(self) -> str:
        """Run the Mahnwesen job through Dolibarr's own cron runner, as the daily cron would.

        --force stands in for the next day: the job is daily, and the check
        runs it several times within a minute.
        """
        job = int(self.fixtures["cron_job"])
        completed = self.run(self.docker, "exec", "-u", "www-data", self.web, "php",
                             "/var/www/scripts/cron/cron_run_jobs.php", self.cron_key, "admin", str(job),
                             "--force", check=False, timeout=600)
        output = completed.stdout + completed.stderr
        if completed.returncode != 0 or "Result of run_jobs OK" not in output:
            raise CheckFailed(f"the Dolibarr cron runner did not run the Mahnwesen job:\n{output[-1500:]}")
        result = self.value(f"SELECT lastresult FROM llx_cronjob WHERE rowid = {job}")
        if result != "0":
            raise CheckFailed(f"the Mahnwesen job ended with result {result!r}:\n{output[-1500:]}")
        return output

    def log(self) -> str:
        completed = self.run(self.docker, "logs", self.web, check=False, timeout=120)
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
    """Today plus some days as PHP in the Dolibarr container formats it."""
    completed = stack.run(stack.docker, "exec", stack.web, "php", "-r",
                          f"echo date('{pattern}', strtotime('+{days} days'));", check=False, timeout=60)
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


def page_ok(page: Page, what: str) -> Page:
    expect(page.status == 200, f"{what}: HTTP {page.status}")
    expect(not page.denied(), f"{what}: access denied")
    problems = page.errors()
    expect(not problems, f"{what}: the page shows {', '.join(problems)}")
    return page


# ------------------------------------------------------------------ scenarios

def install(stack: Stack) -> str:
    """The module is active, its tables and cron job exist, the starter templates are there."""
    expect(stack.fixtures.get("dolibarr", "").startswith(stack.version.rsplit(".", 1)[0]),
           f"the container runs Dolibarr {stack.fixtures.get('dolibarr')}, expected {stack.version}")
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
        "setup general": "/custom/mahnwesen/admin/setup.php?tab=general",
        "setup stages": "/custom/mahnwesen/admin/setup.php?tab=stages",
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
                         "naechste Stufe __MAHNWESEN_NEXT_STAGE_DATE__</p>")


def payment_deadline(stack: Stack) -> str:
    """A stage's payment period reaches the template variables and refuses nonsense (#64)."""
    company = invoice(stack, "company_overdue")
    browser = stack.browser()
    stages = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=stages"), "stages setup")
    page_ok(browser.submit(form_with_action(stages, "save_stages", "stages setup"), {"stage_payment_days_1": "10"}),
            "set a payment period of 10 days for the payment reminder")
    saved = stack.value("SELECT value FROM llx_const WHERE name = 'MAHNWESEN_PAYMENT_DAYS_1' AND entity = 1")
    expect(saved == "10", f"the payment period of the payment reminder is {saved!r} after saving 10")
    stages = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=stages"), "stages setup")
    refused = browser.submit(form_with_action(stages, "save_stages", "stages setup"), {"stage_payment_days_2": "400"})
    messages = [label.replace("%s", "2") for label in translations("MahnwesenStageValuesInvalid")]
    expect(any(message in html.unescape(refused.text) for message in messages),
           "a payment period of 400 days was not refused with a message")
    kept = stack.value("SELECT value FROM llx_const WHERE name = 'MAHNWESEN_PAYMENT_DAYS_2' AND entity = 1")
    expect(kept in (None, "0"), f"a refused payment period was saved anyway: {kept!r}")
    variables = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=templates"), "templates setup")
    expect("__MAHNWESEN_PAYMENT_DEADLINE__" in variables.text and "__MAHNWESEN_PAYMENT_DAYS__" in variables.text,
           "the variable help of the setup does not list the payment deadline variables")

    stack.sql("UPDATE llx_c_email_templates SET content = CONCAT(content, '" + PAYMENT_TEMPLATE_LINE + "') "
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

    deadline = container_date(stack, 10)
    expect(f"Frist: {deadline} (10 Tage)" in (mailpit.message(message["ID"]).get("HTML") or ""),
           f"the sent email does not name the payment deadline {deadline} (#64)")
    recorded_text = stack.value("SELECT message FROM llx_mahnwesen_history WHERE action = 'notice_sent' "
                                f"AND level = 1 AND fk_facture = {company['id']}") or ""
    expect(f"Payment deadline: {container_date(stack, 10, 'Y-m-d')}" in recorded_text,
           f"the history of the sent reminder does not record its payment deadline: {recorded_text!r} (#64)")
    letter = stack.value(f"SELECT rowid FROM llx_mahnwesen_attempt_file WHERE fk_attempt = {attempt_id} "
                         f"AND display_name = '{company['ref']}_Zahlungserinnerung.pdf'")
    content = pdf_text(browser.get(f"/custom/mahnwesen/attempts.php?evidence={letter}").body)
    expect("Zahlbar bis" in content and deadline in content,
           f"the dunning PDF does not show 'Zahlbar bis {deadline}' (#64)")
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
    refused = browser.submit(form_with_action(tab, "skip_stage", "invoice tab"), {"skip_reason": ""})
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
    for attempt in (1, 2):
        tab = page_ok(browser.get(f"/custom/mahnwesen/invoice.php?id={private['id']}"), "invoice tab")
        page_ok(browser.submit(form_with_action(tab, "generate_notice_pdf", "invoice tab")), f"generate the PDF ({attempt})")
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
    for match in PHP_PROBLEM.finditer(stack.log()):
        found.add(f"{match.group(3).replace('/var/www/html/custom/mahnwesen/', '')}: {match.group(1)}: {match.group(2)}")
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
    setup = page_ok(browser.get("/custom/mahnwesen/admin/setup.php?tab=templates"), "templates setup")
    page_ok(browser.submit(form_with_action(setup, "create_starter_templates", "templates setup")), "create starter templates")
    english = stack.sql("SELECT type_template, label FROM llx_c_email_templates WHERE module = 'mahnwesen' AND lang = 'en_US' ORDER BY type_template")
    expected = [["mahnwesen_dunning2", "Mahnwesen - 2. Mahnung - English"], ["mahnwesen_dunning3", "Mahnwesen - 3. Mahnung - English"],
                ["mahnwesen_reminder", "Mahnwesen - Zahlungserinnerung - English"]]
    expect(english == expected,
           f"with an English customer the English starters should be those without an own neutral template, the old label renamed; found {english}")
    return "own template preselected, fixed choice kept across stage saves, English starters only where needed, old labels renamed"


SCENARIOS = (
    ("install", "Module, tables, cron job and templates after activation", install, ()),
    ("synchronise", "Synchronise creates the expected cases", synchronise, ("install",)),
    ("pages", "Every page and integration point renders", pages, ("synchronise",)),
    ("access", "Sales representatives only reach their customers", access, ("synchronise",)),
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
