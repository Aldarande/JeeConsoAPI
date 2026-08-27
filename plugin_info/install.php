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

/* ===================================================================
   Install / Update / Remove

   Un seul cron global : jeeconsoapi::pull.

   Il « tique » toutes les 5 minutes entre 6h et 14h55, mais n'appelle
   RÉELLEMENT l'API que lorsque l'état local de l'équipement l'autorise
   (créneau matinal tiré au hasard entre 08:00 et 09:59, retry différé
   l'après-midi, plafond dur de 3 appels par jour et par PRM).

   La fréquence de tick n'a donc aucun rapport avec la fréquence d'appel :
   voir jeeconsoapi::pull() dans core/class/jeeconsoapi.class.php.
=================================================================== */

/* ===================================================================
   Rend le canal de log visible et utile dès l'installation

   Deux problèmes distincts, souvent confondus :

   1) La page « Analyse → Logs » n'énumère que les FICHIERS présents dans
      www/log/ (cf. log::liste(), log.class.php:589). Tant qu'aucune ligne
      n'a été écrite, le canal n'existe pas et n'apparaît nulle part.

   2) Jeedom crée `log::level::<pluginId>` à l'activation avec `default:1`,
      soit héritage du niveau global — « Erreur » sur une installation
      standard. Or tout ce qui décrit le fonctionnement normal de ce plugin
      (créneau tiré, données obtenues, plancher ajusté) est en `info` : rien
      ne serait donc jamais écrit, et le canal resterait invisible.

   Un cron quotidien produit 3 à 5 lignes par jour en `info` : ce n'est pas
   du bruit, et c'est ce qui permet de comprendre ce que fait le plugin sans
   avoir à reconfigurer quoi que ce soit.

   On ne le fait qu'UNE FOIS, à la première installation : si l'utilisateur
   choisit ensuite un autre niveau, une mise à jour ne doit pas le lui
   reprendre.
=================================================================== */
function jeeconsoapi_setup_log() {
    $logFile = log::getPathToLog('jeeconsoapi');
    if (!file_exists($logFile)) {
        @touch($logFile);
        @chmod($logFile, 0664);
    }

    if (config::byKey('log_level_initialized', 'jeeconsoapi', 0) == 1) {
        return; // choix de l'utilisateur : on n'y touche plus
    }
    config::save('log::level::jeeconsoapi',
        '{"100":"0","200":"1","300":"0","400":"0","1000":"0","default":"0"}');
    config::save('log_level_initialized', 1, 'jeeconsoapi');
    log::add('jeeconsoapi', 'info',
        '[install] Niveau de log initialisé à « Info » — modifiable dans '
        . 'Plugins → Gestion des plugins → JeeConsoAPI');
}

function jeeconsoapi_install() {
    jeeconsoapi_setup_log();

    $cron = cron::byClassAndFunction('jeeconsoapi', 'pull');
    if (!is_object($cron)) {
        $cron = new cron();
        $cron->setClass('jeeconsoapi');
        $cron->setFunction('pull');
    }
    $cron->setEnable(1);
    $cron->setDeamon(0);
    $cron->setSchedule('*/5 6-14 * * *');
    $cron->save();
    log::add('jeeconsoapi', 'info', '[install] Cron jeeconsoapi::pull installé (*/5 6-14 * * *)');
}

function jeeconsoapi_update() {
    jeeconsoapi_install();
}

function jeeconsoapi_remove() {
    $cron = cron::byClassAndFunction('jeeconsoapi', 'pull');
    if (is_object($cron)) {
        $cron->remove();
        log::add('jeeconsoapi', 'info', '[remove] Cron jeeconsoapi::pull supprimé');
    }
}
