/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Plugin JeeConsoAPI - Aldarande */

var JEECONSOAPI_AJAX = '/plugins/jeeconsoapi/core/ajax/jeeconsoapi.ajax.php';

/* -------------------------------------------------------------------
   Utilitaires d'affichage
------------------------------------------------------------------- */
function jeeconsoapi_alert(_selector, _level, _html) {
    $(_selector).html(
        '<div class="alert alert-' + _level + '" style="margin-bottom:0;">' + _html + '</div>'
    );
}

function jeeconsoapi_busy(_button, _busy) {
    var $b = $(_button);
    if (_busy) {
        $b.data('label', $b.html());
        $b.prop('disabled', true).css('pointer-events', 'none')
          .html('<i class="fas fa-spinner fa-spin"></i> {{Requête en cours…}}');
    } else {
        $b.prop('disabled', false).css('pointer-events', '');
        if ($b.data('label')) { $b.html($b.data('label')); }
    }
}

function jeeconsoapi_escape(_text) {
    return $('<div>').text(_text === undefined || _text === null ? '' : _text).html();
}

function jeeconsoapi_eqId() {
    return $('.eqLogicAttr[data-l1key=id]').value();
}

/* -------------------------------------------------------------------
   Encart de planification
------------------------------------------------------------------- */
function jeeconsoapi_loadSchedule() {
    var eqId = jeeconsoapi_eqId();
    if (!eqId) {
        $('#jeeconsoapi_schedule').html('<em>{{Sauvegardez le compteur pour voir sa planification.}}</em>');
        return;
    }
    $.ajax({
        type: 'POST', url: JEECONSOAPI_AJAX, dataType: 'json',
        data: { action: 'getScheduleInfo', id: eqId },
        error: function (request, status, error) { handleAjaxError(request, status, error); },
        success: function (data) {
            if (data.state !== 'ok') { jeedom.private.handleError(data); return; }
            var r = data.result || {};
            var html = '';

            html += '<div><i class="fas fa-key"></i> {{Token}} : '
                 + (r.has_token
                    ? '<span class="label label-success">{{enregistré et chiffré}}</span>'
                    : '<span class="label label-warning">{{aucun}}</span>')
                 + '</div>';

            html += '<div><i class="fas fa-check-circle"></i> {{Dernières données obtenues}} : '
                 + (r.done_date ? '<strong>' + jeeconsoapi_escape(r.done_date) + '</strong>'
                                : '<em>{{aucune pour l\'instant}}</em>')
                 + '</div>';

            html += '<div><i class="fas fa-hourglass-half"></i> {{Prochain appel autorisé}} : '
                 + (r.next ? '<strong>' + jeeconsoapi_escape(r.next) + '</strong>'
                           : '<em>{{au prochain passage du cron}}</em>')
                 + '</div>';

            html += '<div><i class="fas fa-tachometer-alt"></i> {{Appels automatiques aujourd\'hui}} : '
                 + '<strong>' + jeeconsoapi_escape(r.attempts) + '</strong> / '
                 + jeeconsoapi_escape(r.max) + '</div>';

            html += '<div><i class="fas fa-hand-pointer"></i> {{Actions manuelles aujourd\'hui}} : '
                 + '<strong>' + jeeconsoapi_escape(r.manual) + '</strong> / '
                 + jeeconsoapi_escape(r.manual_max) + '</div>';

            if (r.last_error) {
                html += '<div style="color:#a94442;"><i class="fas fa-exclamation-triangle"></i> '
                     + '{{Dernière erreur}} : ' + jeeconsoapi_escape(r.last_error) + '</div>';
            }

            $('#jeeconsoapi_schedule').html(html);
        }
    });
}

/* -------------------------------------------------------------------
   Hook appelé par le core après remplissage du formulaire

   Le champ token est volontairement VIDÉ : le token stocké est chiffré
   et ne doit jamais être réaffiché. Un champ laissé vide signifie
   « ne pas changer » — c'est preSave() côté PHP qui restaure alors la
   valeur existante.
------------------------------------------------------------------- */
function printEqLogic(_eqLogic) {
    var cfg = (_eqLogic && _eqLogic.configuration) ? _eqLogic.configuration : {};

    $('#jeeconsoapi_token').val('');
    if (cfg.token) {
        $('#jeeconsoapi_tokenState').html(
            '<i class="fas fa-lock" style="color:#3c763d;"></i> '
            + '{{Un token est enregistré (chiffré). Laissez ce champ vide pour le conserver.}}'
        );
    } else {
        $('#jeeconsoapi_tokenState').html(
            '<i class="fas fa-exclamation-triangle" style="color:#8a6d3b;"></i> '
            + '{{Aucun token enregistré — le plugin ne peut pas encore récupérer de données.}}'
        );
    }

    $('#jeeconsoapi_testResult').empty();
    $('#jeeconsoapi_backfillResult').empty();
    jeeconsoapi_loadSchedule();
}

/* -------------------------------------------------------------------
   Saisie du PRM : chiffres uniquement
------------------------------------------------------------------- */
$('body').on('input', '#jeeconsoapi_prm', function () {
    var cleaned = $(this).val().replace(/\D/g, '').substring(0, 14);
    if (cleaned !== $(this).val()) { $(this).val(cleaned); }
});

/* -------------------------------------------------------------------
   Tester la connexion
------------------------------------------------------------------- */
$('body').on('click', '#bt_jeeconsoapiTest', function () {
    var eqId = jeeconsoapi_eqId();
    if (!eqId) {
        jeeconsoapi_alert('#jeeconsoapi_testResult', 'warning',
            '{{Sauvegardez d\'abord le compteur.}}');
        return;
    }
    var $btn = $(this);
    jeeconsoapi_busy($btn, true);
    jeeconsoapi_alert('#jeeconsoapi_testResult', 'info', '{{Appel de Conso API en cours…}}');

    $.ajax({
        type: 'POST', url: JEECONSOAPI_AJAX, dataType: 'json',
        data: { action: 'testConnection', id: eqId },
        error: function (request, status, error) {
            jeeconsoapi_busy($btn, false);
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            jeeconsoapi_busy($btn, false);
            if (data.state !== 'ok') { jeedom.private.handleError(data); return; }
            var r = data.result || {};
            jeeconsoapi_alert('#jeeconsoapi_testResult', r.ok ? 'success' : 'danger',
                '<strong>{{HTTP}} ' + jeeconsoapi_escape(r.code) + '</strong> — '
                + jeeconsoapi_escape(r.message));
            jeeconsoapi_loadSchedule();
        }
    });
});

/* -------------------------------------------------------------------
   Actualiser maintenant
------------------------------------------------------------------- */
$('body').on('click', '#bt_jeeconsoapiRefresh', function () {
    var eqId = jeeconsoapi_eqId();
    if (!eqId) {
        jeeconsoapi_alert('#jeeconsoapi_testResult', 'warning',
            '{{Sauvegardez d\'abord le compteur.}}');
        return;
    }
    var $btn = $(this);
    jeeconsoapi_busy($btn, true);
    jeeconsoapi_alert('#jeeconsoapi_testResult', 'info', '{{Récupération des données de la veille…}}');

    $.ajax({
        type: 'POST', url: JEECONSOAPI_AJAX, dataType: 'json',
        data: { action: 'refresh', id: eqId },
        error: function (request, status, error) {
            jeeconsoapi_busy($btn, false);
            handleAjaxError(request, status, error);
        },
        success: function (data) {
            jeeconsoapi_busy($btn, false);
            if (data.state !== 'ok') { jeedom.private.handleError(data); return; }
            var r = data.result || {};
            var level = 'info';
            var msg   = '';

            switch (r.status) {
                case 'ok':
                    level = 'success';
                    msg = '{{Données du}} <strong>' + jeeconsoapi_escape(r.date) + '</strong> : '
                        + '<strong>' + jeeconsoapi_escape(r.consumption) + ' kWh</strong>';
                    break;
                case 'no_data_yet':
                    level = 'warning';
                    msg = jeeconsoapi_escape(r.message);
                    break;
                case 'auth_error':
                case 'bad_request':
                case 'config_error':
                case 'parse_error':
                    level = 'danger';
                    msg = jeeconsoapi_escape(r.message);
                    break;
                case 'retry_later':
                case 'manual_quota_reached':
                    level = 'warning';
                    msg = jeeconsoapi_escape(r.message);
                    break;
                default:
                    msg = '{{Statut}} : ' + jeeconsoapi_escape(r.status);
            }
            jeeconsoapi_alert('#jeeconsoapi_testResult', level, msg);
            jeeconsoapi_loadSchedule();
        }
    });
});

/* -------------------------------------------------------------------
   Importer l'historique
------------------------------------------------------------------- */
$('body').on('click', '#bt_jeeconsoapiBackfill', function () {
    var eqId = jeeconsoapi_eqId();
    if (!eqId) {
        jeeconsoapi_alert('#jeeconsoapi_backfillResult', 'warning',
            '{{Sauvegardez d\'abord le compteur.}}');
        return;
    }
    var months = $('#jeeconsoapi_backfillMonths').value();
    var $btn   = $(this);

    bootbox.confirm({
        title: '{{Importer l\'historique}}',
        message: '{{Cette opération déclenche deux requêtes vers Conso API et écrit les mesures '
               + 'passées directement dans l\'historique Jeedom. Continuer ?}}',
        callback: function (result) {
            if (!result) { return; }
            jeeconsoapi_busy($btn, true);
            jeeconsoapi_alert('#jeeconsoapi_backfillResult', 'info',
                '{{Import en cours — cela peut prendre jusqu\'à quelques minutes…}}');

            $.ajax({
                type: 'POST', url: JEECONSOAPI_AJAX, dataType: 'json',
                data: { action: 'backfill', id: eqId, months: months },
                error: function (request, status, error) {
                    jeeconsoapi_busy($btn, false);
                    handleAjaxError(request, status, error);
                },
                success: function (data) {
                    jeeconsoapi_busy($btn, false);
                    if (data.state !== 'ok') { jeedom.private.handleError(data); return; }
                    var r = data.result || {};
                    var html = '{{Import terminé}} — '
                             + '<strong>' + jeeconsoapi_escape(r.consumption) + '</strong> {{point(s) de consommation}}, '
                             + '<strong>' + jeeconsoapi_escape(r.max_power) + '</strong> {{point(s) de puissance max}}, '
                             + '<strong>' + jeeconsoapi_escape(r.skipped) + '</strong> {{déjà présent(s)}}.';
                    if (r.warnings && r.warnings.length) {
                        html += '<br><i class="fas fa-exclamation-triangle"></i> '
                              + jeeconsoapi_escape(r.warnings.join(' '));
                    }
                    jeeconsoapi_alert('#jeeconsoapi_backfillResult',
                        (r.consumption > 0 || r.skipped > 0) ? 'success' : 'warning', html);
                }
            });
        }
    });
});

/* -------------------------------------------------------------------
   Tableau des commandes
------------------------------------------------------------------- */
/* Les commandes sont créées et maintenues par le plugin (createCommands()).
   Le tableau n'expose donc PAS de sélecteur de type / sous-type / generic_type :
   les laisser modifiables permettrait d'écraser à la sauvegarde le generic_type
   posé par le plugin (DAILY_CONSUMPTION, POWER), et de casser le widget. */
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) { var _cmd = { configuration: {} }; }
    if (!isset(_cmd.configuration)) { _cmd.configuration = {}; }

    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';

    tr += '<td><span class="cmdAttr" data-l1key="id"></span></td>';

    tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}">';
    tr += '</td>';

    tr += '<td>';
    tr += '<span style="color:#777;">' + jeeconsoapi_escape(init(_cmd.unite)) + '</span>';
    tr += '</td>';

    tr += '<td>';
    if (_cmd.type === 'info' && is_numeric(_cmd.id)) {
        tr += '<span class="jeeconsoapi_val_cell" data-cmd_id="' + init(_cmd.id)
            + '" style="font-size:.85em;color:#555;word-break:break-all;">…</span>';
    } else {
        tr += '<span style="color:#bbb;">—</span>';
    }
    tr += '</td>';

    tr += '<td style="white-space:nowrap;">';
    tr += '<label class="checkbox-inline">'
        + '<input type="checkbox" class="cmdAttr" data-l1key="isVisible"/>{{Afficher}}</label>';
    if (_cmd.type === 'info' && _cmd.subType === 'numeric') {
        tr += '<label class="checkbox-inline">'
            + '<input type="checkbox" class="cmdAttr" data-l1key="isHistorized"/>{{Historiser}}</label>';
    }
    tr += '</td>';

    tr += '<td style="white-space:nowrap;">';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure">'
            + '<i class="fas fa-cogs"></i></a> ';
        if (_cmd.type === 'action') {
            tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test">'
                + '<i class="fas fa-rss"></i> {{Tester}}</a>';
        }
    }
    tr += '</td>';

    tr += '</tr>';

    $('#table_cmd tbody').append(tr);
    var $tr = $('#table_cmd tbody tr').last();
    $tr.setValues(_cmd, '.cmdAttr');

    // Valeur courante des commandes info
    if (_cmd.type === 'info' && is_numeric(_cmd.id)) {
        (function (cmdId) {
            $.post('core/ajax/cmd.ajax.php', { action: 'execCmd', id: cmdId }, function (data) {
                if (data.state !== 'ok') { return; }
                var raw = data.result;
                var val = (raw !== null && typeof raw === 'object' && raw.value !== undefined)
                        ? String(raw.value)
                        : String(raw !== undefined && raw !== null ? raw : '—');
                if (val === '' || val === 'null' || val === 'undefined') { val = '—'; }
                if (val.length > 80) { val = val.substring(0, 77) + '…'; }
                $('[data-cmd_id="' + cmdId + '"].jeeconsoapi_val_cell').text(val);
            }, 'json');
        })(_cmd.id);
    }
}
