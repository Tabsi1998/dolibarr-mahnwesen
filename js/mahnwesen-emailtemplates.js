/* Mahnwesen - contextual helper for Dolibarr native email templates */
(function () {
    'use strict';

    // Labels in the user's language come from the page (actions_mahnwesen.class.php, addHtmlHeader).
    var help = window.mahnwesenTemplateHelp || {title: 'Mahnwesen', hint: '', copy: '', tokens: []};
    var tokens = help.tokens || [];

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
        var strong = document.createElement('strong');
        strong.textContent = 'ⓘ ' + help.title;
        var hint = document.createElement('span');
        hint.className = 'opacitymedium';
        hint.textContent = ' – ' + help.hint;
        title.appendChild(strong);
        title.appendChild(hint);
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
            chip.title = item[1] + ' – ' + help.copy;
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
