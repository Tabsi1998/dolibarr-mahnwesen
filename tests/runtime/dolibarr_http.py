"""A small browser for the runtime checks: Dolibarr sessions, forms and Mailpit.

Standard library only, so the local check runs right after a fresh clone. A
form is submitted the way a browser submits it - an unticked checkbox is not
sent - because that is exactly where server code and real browsers disagree.
"""

from __future__ import annotations

import hashlib
import http.cookiejar
import json
import re
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

# Text that must never appear in a page Dolibarr renders for a working module.
ERROR_MARKERS = (
    "Fatal error", "Parse error", "Uncaught ", "Stack trace:", "DB_ERROR", "Error SQL", "SQL error",
    "Include of main fails",
)
# A PHP warning, notice or deprecation as PHP prints it, with or without HTML. The
# words alone also appear in Dolibarr's own JavaScript comments.
PHP_MESSAGE = re.compile(r"\b(Warning|Notice|Deprecated)(?:</b>)?:[^\n]*? on line (?:<b>)?\d+")
ACCESS_DENIED_MARKERS = ("Access denied", "Zugriff verweigert", "accessforbidden", "Accès refusé")


class Page:
    def __init__(self, url: str, status: int, body: bytes, headers: dict):
        self.url = url
        self.status = status
        self.body = body
        self.headers = headers

    @property
    def text(self) -> str:
        return self.body.decode("utf-8", errors="replace")

    def errors(self) -> list[str]:
        text = self.text
        return ([marker.strip() for marker in ERROR_MARKERS if marker in text]
                + sorted({match.group(1) + ":" for match in PHP_MESSAGE.finditer(text)}))

    def denied(self) -> bool:
        return self.status == 403 or any(marker in self.text for marker in ACCESS_DENIED_MARKERS)

    def form(self, name: str | None = None, action_part: str | None = None) -> "Form":
        parser = _FormParser()
        parser.feed(self.text)
        for form in parser.forms:
            if name and form.name != name and form.id != name:
                continue
            if action_part and action_part not in (form.action or ""):
                continue
            form.base = self.url
            return form
        raise LookupError(f"no form {name or action_part!r} on {self.url}")

    def forms(self) -> list["Form"]:
        parser = _FormParser()
        parser.feed(self.text)
        for form in parser.forms:
            form.base = self.url
        return parser.forms


class Form:
    def __init__(self, attributes: dict):
        self.name = attributes.get("name")
        self.id = attributes.get("id")
        self.action = attributes.get("action") or ""
        self.method = (attributes.get("method") or "get").lower()
        self.base = ""
        # (name, value, kind, checked)
        self.fields: list[tuple[str, str, str, bool]] = []
        self.buttons: list[tuple[str, str]] = []

    def values(self) -> list[tuple[str, str]]:
        """What a browser submits without any click on a button."""
        sent = []
        for name, value, kind, checked in self.fields:
            if kind in ("checkbox", "radio") and not checked:
                continue
            sent.append((name, value))
        return sent

    def value(self, name: str) -> str | None:
        for field_name, value, kind, checked in self.fields:
            if field_name == name and (kind not in ("checkbox", "radio") or checked):
                return value
        return None

    def has(self, name: str) -> bool:
        return any(field[0] == name for field in self.fields)

    def url(self) -> str:
        return urllib.parse.urljoin(self.base, self.action or self.base)


class _FormParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.forms: list[Form] = []
        self.current: Form | None = None
        self.select: list | None = None
        self.textarea: list | None = None

    def handle_starttag(self, tag, attrs):
        attributes = {key: (value if value is not None else "") for key, value in attrs}
        if tag == "form":
            self.current = Form(attributes)
            self.forms.append(self.current)
            return
        if self.current is None:
            return
        name = attributes.get("name")
        if tag == "input" and name:
            kind = (attributes.get("type") or "text").lower()
            if kind in ("submit", "button", "image", "reset"):
                self.current.buttons.append((name, attributes.get("value", "")))
            elif kind == "file":
                return
            else:
                default = "on" if kind in ("checkbox", "radio") else ""
                self.current.fields.append((name, attributes.get("value", default), kind,
                                            "checked" in attributes))
        elif tag == "button" and name:
            self.current.buttons.append((name, attributes.get("value", "")))
        elif tag == "select" and name:
            self.select = [name, None, None, "multiple" in attributes]
        elif tag == "option" and self.select is not None:
            value = attributes.get("value", "")
            if self.select[2] is None:
                self.select[2] = value
            if "selected" in attributes:
                if self.select[3]:
                    self.current.fields.append((self.select[0], value, "select", True))
                    self.select[1] = value
                elif self.select[1] is None:
                    self.select[1] = value
        elif tag == "textarea" and name:
            self.textarea = [name, []]

    def handle_data(self, data):
        if self.textarea is not None:
            self.textarea[1].append(data)

    def handle_endtag(self, tag):
        if self.current is None:
            return
        if tag == "select" and self.select is not None:
            name, selected, first, multiple = self.select
            if not multiple:
                chosen = selected if selected is not None else first
                if chosen is not None:
                    self.current.fields.append((name, chosen, "select", True))
            self.select = None
        elif tag == "textarea" and self.textarea is not None:
            self.current.fields.append((self.textarea[0], "".join(self.textarea[1]), "textarea", True))
            self.textarea = None
        elif tag == "form":
            self.current = None


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Browser:
    """One Dolibarr session with its own cookies."""

    def __init__(self, base: str, timeout: int = 60):
        self.base = base.rstrip("/")
        self.timeout = timeout
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.plain = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _NoRedirect)

    def _url(self, path: str) -> str:
        return path if path.startswith("http") else f"{self.base}/{path.lstrip('/')}"

    def _open(self, request, follow: bool = True) -> Page:
        opener = self.opener if follow else self.plain
        try:
            with opener.open(request, timeout=self.timeout) as response:
                return Page(response.geturl(), response.status, response.read(), dict(response.headers))
        except urllib.error.HTTPError as error:
            return Page(error.url or request.full_url, error.code, error.read(), dict(error.headers or {}))

    def get(self, path: str, follow: bool = True) -> Page:
        return self._open(urllib.request.Request(self._url(path), method="GET"), follow)

    def post(self, path: str, fields: list[tuple[str, str]], follow: bool = True) -> Page:
        data = urllib.parse.urlencode(fields).encode("utf-8")
        request = urllib.request.Request(self._url(path), data=data, method="POST",
                                         headers={"Content-Type": "application/x-www-form-urlencoded"})
        return self._open(request, follow)

    def post_multipart(self, path: str, fields: list[tuple[str, str]], files: list[tuple[str, str, bytes]],
                       follow: bool = True) -> Page:
        """A form with enctype multipart/form-data, as a browser sends a file upload."""
        boundary = "----mahnwesen-runtime-" + hashlib.sha256(repr(fields).encode("utf-8")).hexdigest()[:24]
        body = bytearray()
        for name, value in fields:
            body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n"
                     f"{value}\r\n").encode("utf-8")
        for name, filename, data in files:
            body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"; filename=\"{filename}\"\r\n"
                     "Content-Type: application/zip\r\n\r\n").encode("utf-8")
            body += data + b"\r\n"
        body += f"--{boundary}--\r\n".encode("utf-8")
        request = urllib.request.Request(self._url(path), data=bytes(body), method="POST",
                                         headers={"Content-Type": f"multipart/form-data; boundary={boundary}"})
        return self._open(request, follow)

    def submit(self, form: Form, changes: dict | None = None, drop: tuple = (),
               button: tuple[str, str] | None = None, follow: bool = True) -> Page:
        """Submit a form as a browser would, with some values changed or removed."""
        changes = dict(changes or {})
        fields = []
        for name, value in form.values():
            if name in drop:
                continue
            if name in changes:
                fields.append((name, str(changes.pop(name))))
            else:
                fields.append((name, value))
        fields += [(name, str(value)) for name, value in changes.items()]
        if button:
            fields.append(button)
        return self.post(form.url(), fields, follow)

    def login(self, login: str, password: str) -> None:
        page = self.get("/index.php")
        form = page.form(name="login")
        after = self.submit(form, {"username": login, "password": password})
        if after.status != 200 or 'name="username"' in after.text and 'id="password"' in after.text:
            raise RuntimeError(f"login as {login} failed (HTTP {after.status})")


class Mailpit:
    def __init__(self, base: str, timeout: int = 30):
        self.base = base.rstrip("/")
        self.timeout = timeout

    def _json(self, path: str, method: str = "GET"):
        request = urllib.request.Request(f"{self.base}{path}", method=method)
        with urllib.request.urlopen(request, timeout=self.timeout) as response:
            body = response.read()
        return json.loads(body) if body.strip() else None

    def messages(self) -> list[dict]:
        return self._json("/api/v1/messages?limit=500").get("messages") or []

    def message(self, message_id: str) -> dict:
        return self._json(f"/api/v1/message/{message_id}")

    def attachment_hashes(self, message_id: str) -> dict:
        """File name to SHA-256 of every attachment, from the bytes Mailpit received."""
        detail = self.message(message_id)
        hashes = {}
        for part in detail.get("Attachments") or []:
            url = f"{self.base}/api/v1/message/{message_id}/part/{part['PartID']}"
            with urllib.request.urlopen(url, timeout=self.timeout) as response:
                hashes[part["FileName"]] = hashlib.sha256(response.read()).hexdigest()
        return hashes

    def attachments(self, message_id: str) -> dict:
        """File name to bytes of every attachment, as Mailpit received them."""
        files = {}
        for part in self.message(message_id).get("Attachments") or []:
            url = f"{self.base}/api/v1/message/{message_id}/part/{part['PartID']}"
            with urllib.request.urlopen(url, timeout=self.timeout) as response:
                files[part["FileName"]] = response.read()
        return files

    def clear(self) -> None:
        request = urllib.request.Request(f"{self.base}/api/v1/messages", method="DELETE")
        with urllib.request.urlopen(request, timeout=self.timeout) as response:
            response.read()


def token_of(page: Page) -> str:
    match = re.search(r'name="token" value="([^"]+)"', page.text)
    if not match:
        raise LookupError(f"no CSRF token on {page.url}")
    return match.group(1)
