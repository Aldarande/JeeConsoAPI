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
   (créneau matinal tiré au hasard entre 06:00 et 09:59, retry différé
   l'après-midi, plafond dur de 3 appels par jour et par PRM).

   La fréquence de tick n'a donc aucun rapport avec la fréquence d'appel :
   voir jeeconsoapi::pull() dans core/class/jeeconsoapi.class.php.
=================================================================== */

function jeeconsoapi_install() {
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
