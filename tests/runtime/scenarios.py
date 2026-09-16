"""What the runtime checks prove in a running Dolibarr.

scripts/local_check.py starts one Dolibarr per supported version with MariaDB
and Mailpit, runs fixtures.php, and then calls the scenarios below in order.
Each scenario raises CheckFailed with what went wrong and returns a one-line
result. They only use what a user and the cron use: the web pages, the
Dolibarr cron runner, the mail server and the database.
"""

from __future__ import annotations

import json
import re
import subprocess
import time
from dataclasses import dataclass, field
from typing import Callable

from dolibarr_http import Browser, Mailpit, Page

MODULE_TABLES = ("mahnwesen_case", "mahnwesen_history", "mahnwesen_rule", "mahnwesen_attempt",
                 "mahnwesen_attempt_file", "mahnwesen_fee", "mahnwesen_pause", "mahnwesen_run")
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


def invoice(stack: Stack, key: str) -> dict:
    return stack.fixtures["invoices"][key]


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
    templates = int(stack.value("SELECT COUNT(*) FROM llx_c_email_templates WHERE module = 'mahnwesen'") or 0)
    expect(templates == 8, f"expected 8 starter email templates (4 stages, de_DE and en_US), found {templates}")
    hooks = stack.value("SELECT value FROM llx_const WHERE name = 'MAIN_MODULE_MAHNWESEN_HOOKS' AND entity = 1") or ""
    expect("invoicecard" in hooks and "emailtemplates" in hooks, f"hooks not registered: {hooks!r}")
    return (f"Dolibarr {stack.fixtures['dolibarr']} on PHP {stack.fixtures['php']}: module active, "
            f"{len(MODULE_TABLES)} tables, cron job, 8 templates")


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
                    "company_recent": ("0", "60.00")}
    for key, (level, amount) in expectations.items():
        row = cases.get(str(invoice(stack, key)["id"]))
        expect(row is not None, f"no case for {key}")
        expect(row[1] == level and row[2] == "open" and row[3] == "0" and row[4] == amount,
               f"case for {key} is {row[1:]}, expected level {level}, open, not paused, {amount}")
    created = int(stack.value("SELECT COUNT(*) FROM llx_mahnwesen_history WHERE action = 'case_created'") or 0)
    expect(created == 3, f"expected 3 case_created history rows, found {created}")
    # Dolibarr stores the element type of an invoice event as 'invoice'.
    linked = {row[0]: int(row[1]) for row in stack.sql(
        "SELECT fk_element, COUNT(*) FROM llx_actioncomm WHERE ref_ext LIKE 'mahnwesen-history-%' "
        "AND elementtype IN ('invoice', 'facture') GROUP BY fk_element")}
    unlinked = [key for key in expectations if linked.get(str(invoice(stack, key)["id"]), 0) < 1]
    expect(not unlinked, f"no agenda event on the invoice for {', '.join(unlinked)}")
    agenda = sum(linked.values())
    return f"3 cases with the expected stages and balances, {agenda} agenda events"


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
    for what, path in paths.items():
        page_ok(browser.get(path), what)
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
    attempts = stack.sql("SELECT rowid, status, mode, level FROM llx_mahnwesen_attempt")
    expect(len(attempts) == 1 and attempts[0][1:] == ["sent", "manual", "1"],
           f"expected one sent manual attempt at stage 1, found {attempts}")
    recorded = {row[0]: row[1] for row in stack.sql(
        f"SELECT display_name, sha256 FROM llx_mahnwesen_attempt_file WHERE fk_attempt = {int(attempts[0][0])}")}
    expect(set(received) == set(recorded),
           f"attachments received {sorted(received)} differ from those recorded {sorted(recorded)}")
    differing = [name for name in received if received[name] != recorded[name]]
    expect(not differing, f"SHA-256 in the database differs from the delivered bytes: {differing}")
    history = stack.value("SELECT COUNT(*) FROM llx_mahnwesen_history WHERE action = 'notice_sent' "
                          f"AND result = 'success' AND level = 1 AND fk_facture = {company['id']}")
    expect(history == "1", "no notice_sent history row for the reminder")

    again = browser.submit(form, {"action": "send_notice", "confirm_send": "1", "receiver[]": contact},
                           button=("sendmail", "1"))
    expect(again.status == 200, f"the repeated send answered HTTP {again.status}")
    expect(len(mailpit.messages()) == 1, "submitting the same composer twice sent a second email")
    count = stack.value("SELECT COUNT(*) FROM llx_mahnwesen_attempt")
    expect(count == "1", f"the repeated send created another attempt ({count} in total)")
    return f"one email to the billing contact, {len(received)} attachments with matching SHA-256, repeat refused"


def automation(stack: Stack) -> str:
    """With automatic sending on, the Dolibarr cron sends each due stage once."""
    private = invoice(stack, "private_overdue")
    mailpit = stack.mailpit()
    mailpit.clear()
    stack.cron()
    idle = stack.sql("SELECT status, attempted, sent FROM llx_mahnwesen_run ORDER BY rowid DESC LIMIT 1")
    expect(idle and idle[0] == ["success", "0", "0"], f"with automation off the run was {idle}")
    expect(not mailpit.messages(), "the cron sent email while automatic sending was off")

    stack.sql("UPDATE llx_const SET value = '1' WHERE name = 'MAHNWESEN_AUTO_SEND_ENABLED' AND entity = 1")
    stack.sql("UPDATE llx_mahnwesen_rule SET send_email = 1 WHERE level = 1 AND entity = 1")
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


SCENARIOS = (
    ("install", "Module, tables, cron job and templates after activation", install, ()),
    ("synchronise", "Synchronise creates the expected cases", synchronise, ("install",)),
    ("pages", "Every page and integration point renders", pages, ("synchronise",)),
    ("access", "Sales representatives only reach their customers", access, ("synchronise",)),
    ("manual-send", "The composer sends one reminder with verified attachments", manual_send, ("pages",)),
    ("automation", "The cron sends automatically only when switched on, once", automation, ("manual-send",)),
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
