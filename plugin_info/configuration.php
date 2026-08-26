<?php
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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>

<form class="form-horizontal">
    <fieldset>

        <legend><i class="fas fa-clock"></i> {{Fenêtre d'appel quotidienne}}</legend>

        <div class="alert alert-info">
            {{Conso API impose un maximum d'une requête par jour et par compteur. Le plugin
            tire au hasard un horaire d'appel dans la fenêtre du matin, afin de ne jamais
            solliciter le service pile à l'heure ronde. Les données de la veille sont
            généralement publiées par Enedis vers 8h, parfois avec 1 à 2 heures de retard :
            si elles sont absentes, un unique nouvel essai est programmé l'après-midi.}}
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">
                {{Début de la fenêtre du matin}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Heure la plus tôt à laquelle un appel peut avoir lieu. Défaut : 6.}}"></i></sup>
            </label>
            <div class="col-sm-2">
                <input type="number" min="0" max="23" step="1"
                       class="configKey form-control" data-l1key="morning_start"
                       placeholder="6"/>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">
                {{Fin de la fenêtre du matin}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Heure limite de la fenêtre matinale (exclue). Défaut : 10.}}"></i></sup>
            </label>
            <div class="col-sm-2">
                <input type="number" min="1" max="23" step="1"
                       class="configKey form-control" data-l1key="morning_end"
                       placeholder="10"/>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">
                {{Heure du nouvel essai de l'après-midi}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Heure à partir de laquelle le plugin retente si les données de la veille n'étaient pas encore disponibles le matin. Défaut : 14.}}"></i></sup>
            </label>
            <div class="col-sm-2">
                <input type="number" min="10" max="23" step="1"
                       class="configKey form-control" data-l1key="afternoon_retry"
                       placeholder="14"/>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">
                {{Appels maximum par jour et par compteur}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Plafond dur. Une fois atteint, plus aucun appel n'est émis jusqu'au lendemain, quelle que soit la situation. Défaut : 3.}}"></i></sup>
            </label>
            <div class="col-sm-2">
                <input type="number" min="1" max="5" step="1"
                       class="configKey form-control" data-l1key="max_attempts"
                       placeholder="3"/>
            </div>
        </div>

        <legend><i class="fas fa-heart"></i> {{Soutenir le développement}}</legend>

        <div class="form-group">
            <div class="col-sm-12">
                <p style="margin-bottom:10px;">
                    {{JeeConsoAPI est gratuit et open-source. Un don aide à financer le
                    développement, les tests et les mises à jour.}}
                </p>
                <a href="https://ko-fi.com/aldarande" target="_blank" rel="noopener noreferrer"
                   class="btn btn-sm" style="background:#FF5E5B;color:#fff;margin-right:6px;">
                    <i class="fas fa-mug-hot"></i> {{Ko-fi}}
                </a>
                <a href="https://github.com/sponsors/Aldarande" target="_blank" rel="noopener noreferrer"
                   class="btn btn-sm" style="background:#ea4aaa;color:#fff;margin-right:6px;">
                    <i class="fab fa-github"></i> {{GitHub Sponsors}}
                </a>
                <a href="https://liberapay.com/Aldarande/donate" target="_blank" rel="noopener noreferrer"
                   class="btn btn-sm" style="background:#F6C915;color:#333;">
                    <i class="fas fa-hand-holding-heart"></i> {{Liberapay}}
                </a>
                <p style="margin-top:12px;font-size:0.9em;color:#888;">
                    {{Merci également à Boris K. pour Conso API, sans qui ce plugin n'existerait pas.}}
                    <a href="https://conso.boris.sh" target="_blank" rel="noopener noreferrer">conso.boris.sh</a>
                </p>
            </div>
        </div>

    </fieldset>
</form>
