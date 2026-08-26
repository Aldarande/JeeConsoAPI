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

/* Plugin JeeConsoAPI - Aldarande
 *
 * AUCUN endpoint de ce fichier ne renvoie le token Bearer, même partiellement.
 * Les diagnostics remontent un code HTTP et un nombre de points, rien d'autre.
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    // SECURITY: ajax::init() vérifie le token CSRF et restreint aux actions listées
    ajax::init(array('testConnection', 'refresh', 'backfill', 'getScheduleInfo'));

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__), 401);
    }

    /**
     * Résout l'équipement depuis init('id') ou lève une exception.
     */
    function jeeconsoapi_ajax_eqLogic() {
        $id = init('id');
        if (!$id) {
            throw new Exception(__('Identifiant d\'équipement manquant', __FILE__));
        }
        $eqLogic = jeeconsoapi::byId(intval($id));
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        return $eqLogic;
    }

    switch (init('action')) {

        // ── Test de connexion — consomme une requête vers Conso API ────────
        case 'testConnection':
            set_time_limit(60);
            ajax::success(jeeconsoapi_ajax_eqLogic()->testConnection());
            break;

        // ── Actualisation immédiate, hors planification ───────────────────
        // Consomme une des tentatives quotidiennes autorisées.
        case 'refresh':
            set_time_limit(90);
            ajax::success(jeeconsoapi_ajax_eqLogic()->runCycle(true));
            break;

        // ── Import de l'historique — jamais automatique ────────────────────
        case 'backfill':
            set_time_limit(300);
            $months = intval(init('months', 12));
            ajax::success(jeeconsoapi_ajax_eqLogic()->backfillHistory($months));
            break;

        // ── État de planification pour l'encart de la page équipement ──────
        case 'getScheduleInfo':
            ajax::success(jeeconsoapi_ajax_eqLogic()->getScheduleInfo());
            break;
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
} catch (Exception $e) {
    ajax::error(displayExeption($e), $e->getCode());
}
