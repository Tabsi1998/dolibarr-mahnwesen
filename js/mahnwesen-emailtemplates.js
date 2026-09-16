/* Mahnwesen - contextual helper for Dolibarr native email templates */
(function () {
    'use strict';

    var tokens = [
        ['__MAHNWESEN_STAGE__', 'aktuelle Mahnstufe'],
        ['__MAHNWESEN_OPEN_AMOUNT__', 'offener Rechnungsbetrag'],
        ['__MAHNWESEN_FEE__', 'Mahnspesen der aktuellen Stufe'],
        ['__MAHNWESEN_TOTAL__', 'offener Betrag + Mahnspesen'],
        ['__MAHNWESEN_CUSTOMER_CLASS__', 'Privatperson / Unternehmen / unklar'],
        ['__MAHNWESEN_NEXT_STAGE_DATE__', 'Datum der nächsten Mahnstufe'],
        ['__MAHNWESEN_FEE_PARAGRAPH__', 'fertiger Absatz zu Mahnspesen'],
        ['{INVOICE_REF}', 'Rechnungsnummer'],
        ['{CUSTOMER_NAME}', 'Kundenname'],
        ['{INVOICE_DATE}', 'Rechnungsdatum'],
        ['{DUE_DATE}', 'Fälligkeitsdatum'],
        ['{OPEN_AMOUNT}', 'offener Rechnungsbetrag'],
        ['{DUNNING_FEE}', 'Mahnspesen'],
        ['{DUNNING_TOTAL}', 'Gesamtbetrag des Mahnschreibens'],
        ['{DUNNING_STAGE}', 'Mahnstufe'],
        ['{TODAY}', 'heutiges Datum'],
        ['{COMPANY_NAME}', 'eigene Firma / Institution'],
        ['{NEXT_STAGE_DATE}', 'nächste Mahnstufe'],
        ['{FEE_PARAGRAPH}', 'fertiger Mahnspesen-Absatz']
    ];

    function isDunningType(value) {
        return /^mahnwesen_(reminder|dunning1|dunning2|dunning3)$/.test(value || '');
    }

    function buildHelp() {
        var box = document.createElement('div');
        box.id = 'mahnwesen-email-template-help';
        box.className = 'info margintoponly marginbottomonly';
        box.style.display = 'none';
        box.style.maxWidth = '980px';

        var title = document.createElement('div');
        title.innerHTML = '<strong>ⓘ Mahnwesen-Variablen</strong> <span class="opacitymedium">– anklicken zum Kopieren; verwendbar in Betreff und Inhalt. Dolibarr-Standardvariablen funktionieren zusätzlich.</span>';
        box.appendChild(title);

        var wrap = document.createElement('div');
        wrap.style.marginTop = '8px';
        wrap.style.display = 'flex';
        wrap.style.flexWrap = 'wrap';
        wrap.style.gap = '6px 10px';
        tokens.forEach(function (item) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'button smallpaddingimp';
            chip.setAttribute('data-mahn-token', item[0]);
            chip.title = item[1] + ' – klicken zum Kopieren';
            chip.style.fontFamily = 'monospace';
            chip.textContent = item[0];
            chip.addEventListener('click', function () {
                var value = chip.getAttribute('data-mahn-token');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).catch(function () {});
                }
                var before = chip.textContent;
                chip.textContent = value + ' ✓';
                window.setTimeout(function () { chip.textContent = before; }, 900);
            });
            wrap.appendChild(chip);
        });
        box.appendChild(wrap);
        return box;
    }

    function install() {
        if (!/\/admin\/mails_templates\.php$/.test(window.location.pathname)) return;
        var select = document.querySelector('select[name="type_template"]');
        if (!select) return;
        if (document.getElementById('mahnwesen-email-template-help')) return;

        var box = buildHelp();
        var content = document.querySelector('textarea[name="content"]');
        var target = null;
        if (content) {
            target = content.closest ? content.closest('tr') : null;
        }
        if (target && target.parentNode) {
            var colspan = target.children && target.children.length ? target.children.length : 2;
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = colspan;
            td.appendChild(box);
            tr.appendChild(td);
            target.parentNode.insertBefore(tr, target);
        } else {
            var parent = select.parentNode;
            parent.appendChild(box);
        }

        function refresh() {
            box.style.display = isDunningType(select.value) ? 'block' : 'none';
        }
        select.addEventListener('change', refresh);
        if (window.jQuery) {
            window.jQuery(select).on('select2:select select2:clear', refresh);
        }
        refresh();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
    else install();
}());
